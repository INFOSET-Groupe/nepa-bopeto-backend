<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collecte;
use App\Models\Garanty;
use App\Models\Payment;
use App\Services\FlexpaieService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        // abort_unless($request->user()->isMenage(), 403, 'Seule un profil ménage peut récupérer son historique de paiements');

        $payments = Payment::where('user_id', $user->id)
            ->where('type', 'collecte')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    public function initiate(Request $request, FlexpaieService $flexpaieService)
    {
        $user = $request->user();
        abort_unless($user->isMenage(), 403, 'Seul un profil ménage peut payer une collecte.');

        $validation = $request->validate([
            'collecte_id' => ['required', 'exists:collectes,id'],
            'montant' => ['required', 'numeric'],
            'currency' => ['required', 'string', 'in:CDF'],
            'phone' => ['nullable', 'string', 'size:12', 'required_if:methode,mobile'],
            'methode' => ['required', 'string', 'in:cash,mobile'],
            'temps_attente' => ['nullable', 'string'],
        ]);

        try {
            $collecte = Collecte::query()
                ->whereKey($validation['collecte_id'])
                ->where('menage_id', $user->id)
                ->first();

            if (! $collecte) {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => 'Collecte non retrouvée.',
                ], 404);
            }

            if (! $collecte->ramasseur_id) {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => 'Aucun ramasseur n’est associé à cette collecte.',
                ], 409);
            }

            $reference = 'PCOL-'.strtoupper(uniqid());
            $description = 'Paiement de la collecte de '.$user->name;
            $method = $validation['methode'];

            $paymentAttributes = [
                'user_id' => $user->id,
                'type' => 'collecte',
                'montant' => $validation['montant'],
                'currency' => $validation['currency'],
                'phone' => $validation['phone'] ?? null,
                'collecte_id' => $collecte->id,
                'reference' => $reference,
                'status' => 'pending',
                'method' => $method,
                'description' => $description,
            ];

            if ($method === 'cash') {
                DB::transaction(function () use ($collecte, $paymentAttributes, $validation) {
                    $lockedCollecte = Collecte::query()
                        ->whereKey($collecte->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    abort_unless(
                        ! $this->hasActiveCollectePayment($lockedCollecte),
                        409,
                        'Un paiement est déjà en cours ou a déjà été effectué pour cette collecte.'
                    );

                    $payment = Payment::create(array_merge($paymentAttributes, [
                        'status' => 'success',
                    ]));

                    $this->deductCollecteGuarantee($lockedCollecte, $payment);

                    $lockedCollecte->update([
                        'temps_attente' => $validation['temps_attente'] ?? null,
                        'statut' => 'terminee',
                        'terminee_at' => now(),
                    ]);

                    return $payment;
                });

                return response()->json([
                    'code' => '0',
                    'success' => true,
                    'message' => 'Paiement cash effectué avec succès.',
                    'order_number' => null,
                ]);
            }

            $payment = DB::transaction(function () use ($collecte, $paymentAttributes, $validation) {
                $lockedCollecte = Collecte::query()
                    ->whereKey($collecte->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    ! $this->hasActiveCollectePayment($lockedCollecte),
                    409,
                    'Un paiement est déjà en cours ou a déjà été effectué pour cette collecte.'
                );

                $lockedCollecte->update([
                    'temps_attente' => $validation['temps_attente'] ?? null,
                ]);

                return Payment::create($paymentAttributes);
            });

            $response = $flexpaieService->mobilePaymentMerchant(
                $reference,
                $validation['montant'],
                $validation['phone'],
                $validation['currency'],
                $description,
                route('api.collecte.callback', ['reference' => $reference])
            );

            $initiated = (string) ($response['code'] ?? '1') === '0';
            $payment->update([
                'order_number' => $response['orderNumber'] ?? null,
                'status' => $initiated ? 'pending' : 'failed',
            ]);

            return response()->json([
                'code' => $response['code'] ?? '1',
                'success' => $initiated,
                'message' => $response['message'] ?? 'Paiement de la collecte initié avec succès.',
                'order_number' => $response['orderNumber'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur lors de l’initiation du paiement de la collecte.', [
                'user_id' => $user->id,
                'collecte_id' => $validation['collecte_id'],
                'exception' => $e,
            ]);

            return response()->json([
                'code' => '1',
                'success' => false,
                'message' => 'Une erreur est survenue lors de l’initiation du paiement de la collecte.',
            ], 500);
        }
    }

    public function callback(Request $request, FlexpaieService $flexpaieService, string $reference)
    {
        try {
            $content = $request->json()->all();
            $callbackCode = $content['code'] ?? null;
            $callbackStatus = $content['status'] ?? null;

            Log::warning('Données de callback reçue pour le paiement de collecte.', [
                'reference' => $reference,
                'code' => $callbackCode,
                'status' => $callbackStatus,
            ]);

            if ((string) $callbackCode !== '0' || ! in_array((string) $callbackStatus, ['0', '1', '2', '4'], true)) {
                Log::warning('Données de callback invalides pour un paiement de collecte.', [
                    'reference' => $reference,
                    'code' => $callbackCode,
                    'status' => $callbackStatus,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Code ou statut de callback invalide.',
                ], 422);
            }

            $payment = Payment::query()
                ->where('reference', $reference)
                ->where('type', 'collecte')
                ->first();

            if (! $payment) {
                Log::warning('Callback de paiement de collecte introuvable.', ['reference' => $reference]);

                return response()->json([
                    'success' => false,
                    'message' => 'Paiement introuvable.',
                ], 404);
            }

            if (! $payment->order_number) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le numéro de commande du paiement n’est pas encore disponible.',
                ], 409);
            }

            $response = $flexpaieService->getPaymentStatus($payment->order_number);
            if ((string) ($response['code'] ?? '') !== '0') {
                Log::warning('Vérification FlexPay échouée pour le callback de collecte.', [
                    'reference' => $reference,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de vérifier le paiement auprès de FlexPay.',
                ], 502);
            }

            $verifiedStatus = $response['transaction']['status'] ?? null;
            if ($verifiedStatus === null || (string) $verifiedStatus !== (string) $callbackStatus) {
                Log::warning('Le statut du callback ne correspond pas au statut vérifié par FlexPay.', [
                    'reference' => $reference,
                    'callback_status' => $callbackStatus,
                    'verified_status' => $verifiedStatus,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Le statut du callback ne correspond pas au statut vérifié par FlexPay.',
                ], 409);
            }

            $this->closeCollectePayment($payment, $verifiedStatus);
            Log::info('Callback de paiement de collecte traité.', [
                'reference' => $reference,
                'status' => $verifiedStatus,
            ]);

            return response()->json([
                'success' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur lors du callback de paiement de collecte.', [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du traitement du callback.',
            ], 500);
        }
    }

    public function check(Request $request, FlexpaieService $flexpaieService)
    {
        $user = $request->user();
        abort_unless($user->isMenage(), 403, 'Seul un profil ménage peut vérifier un paiement de collecte.');

        $validation = $request->validate([
            'order_number' => ['required', 'string'],
        ]);

        try {
            $orderNumber = $validation['order_number'];
            $payment = Payment::query()
                ->where('order_number', $orderNumber)
                ->where('user_id', $user->id)
                ->where('type', 'collecte')
                ->first();

            if (! $payment) {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => 'Paiement introuvable.',
                ], 404);
            }

            $response = $flexpaieService->getPaymentStatus($orderNumber);
            if ((string) ($response['code'] ?? '') !== '0') {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => $response['message'] ?? 'Une erreur est survenue lors de la vérification du paiement.',
                ], 502);
            }

            $flexStatus = $response['transaction']['status'] ?? null;
            if ($flexStatus === null) {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => 'Statut FlexPay manquant.',
                ], 502);
            }

            $paymentStatus = $this->closeCollectePayment($payment, $flexStatus);

            return response()->json([
                'code' => $flexStatus,
                'success' => true,
                'payment_status' => $paymentStatus,
                'message' => $response['message'] ?? 'Statut du paiement récupéré avec succès.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la vérification du paiement de collecte.', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json([
                'code' => '1',
                'success' => false,
                'message' => 'Une erreur est survenue lors de la vérification du paiement.',
            ], 500);
        }
    }

    private function closeCollectePayment(Payment $payment, mixed $flexStatus): ?string
    {
        if (! is_int($flexStatus) && ! is_string($flexStatus)) {
            Log::warning('Statut FlexPay invalide pour un paiement de collecte.', [
                'order_number' => $payment->order_number,
                'status' => $flexStatus,
            ]);

            return $payment->status;
        }

        $newStatus = match ((string) $flexStatus) {
            '0' => 'success',
            '1' => 'failed',
            '2' => 'pending',
            '4' => 'failed',
            default => null,
        };

        if ($newStatus === null) {
            Log::warning('Statut FlexPay inconnu pour un paiement de collecte.', [
                'order_number' => $payment->order_number,
                'status' => $flexStatus,
            ]);

            return $payment->status;
        }

        return DB::transaction(function () use ($payment, $newStatus) {
            $lockedPayment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status !== 'pending') {
                return $lockedPayment->status;
            }

            if ($newStatus === 'success') {
                $collecte = Collecte::query()
                    ->lockForUpdate()
                    ->find($lockedPayment->collecte_id);

                if (! $collecte) {
                    throw new \RuntimeException('Collecte associée au paiement introuvable.');
                }

                $this->deductCollecteGuarantee($collecte, $lockedPayment);

                $collecte->update([
                    'statut' => 'terminee',
                    'terminee_at' => now(),
                ]);
            }

            $lockedPayment->update(['status' => $newStatus]);

            return $newStatus;
        });
    }

    private function deductCollecteGuarantee(Collecte $collecte, Payment $payment): void
    {
        if (! $collecte->ramasseur_id || $payment->currency !== 'CDF') {
            throw new \RuntimeException('La collecte ou le paiement ne permet pas de débiter la garantie.');
        }

        $garanty = Garanty::query()
            ->where('ramasseur_id', $collecte->ramasseur_id)
            ->lockForUpdate()
            ->first();

        if (! $garanty || $garanty->currency !== 'CDF') {
            throw new \RuntimeException('Garantie CDF du ramasseur introuvable.');
        }

        $deduction = round((float) $payment->montant * 0.20, 2);
        $garanty->decrement('reste', $deduction);

        Payment::create([
            'montant' => $deduction,
            'currency' => $payment->currency,
            'type' => 'commission',
            'status' => 'success',
            'user_id' => $collecte->ramasseur_id,
            'garanty_id' => $garanty->id,
            'collecte_id' => $collecte->id,
        ]);
    }

    private function hasActiveCollectePayment(Collecte $collecte): bool
    {
        return Payment::query()
            ->where('collecte_id', $collecte->id)
            ->where('type', 'collecte')
            ->whereIn('status', ['pending', 'success'])
            ->exists();
    }
}
