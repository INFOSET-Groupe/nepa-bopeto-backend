<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Garanty;
use App\Models\Payment;
use App\Services\FlexpaieService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GarantyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($request->user()->isRamasseur(), 403, 'Seule un profil ramasseur peut récupérer son historique de paiements.');

        $garanty = $user->garanty()->first();

        $last_payment = Payment::where('user_id', $user->id)
            ->where('type', 'garanty')->where('type', 'commission')
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'garanty' => $garanty->montant ?? 0,
            'disponible' => $garanty->reste ?? 0,
            'liste_payment' => $last_payment,
        ];
    }

    public function initiate(Request $request, FlexpaieService $flexpaieService)
    {
        abort_unless($request->user()->isRamasseur(), 403, 'Seule un profil ramasseur peut payer une garantie.');
        try {

            $user = $request->user();

            $validation = $request->validate([
                'montant' => 'required|numeric|min:5000',
                'currency' => 'required|string|in:CDF',
                'phone' => 'required|string|size:12',
                'methode' => 'required|string|in:cash,mobile',
            ]);

            $reference = 'GRT-'.strtoupper(uniqid());
            $description = 'Paiement de garantie pour le ramasseur '.$user->name;
            $method = $validation['methode'];

            if ($method == 'mobile' && ! $validation['phone']) {
                return response()->json([
                    'code' => '1',
                    'message' => 'Numéro de téléphone non renseigné.',
                ], 500);
            }

            $payment = Payment::create([
                'user_id' => $user->id,
                'type' => 'garanty',
                'montant' => $validation['montant'],
                'currency' => $validation['currency'] ?? 'CDF',
                'phone' => $validation['phone'],
                'reference' => $reference,
                'method' => $method,
                'status' => 'pending',
                'description' => $description,
            ]);

            if ($method == 'cash') {

                DB::transaction(function () use ($payment) {
                    $garanty = Garanty::firstOrCreate(
                        [
                            'ramasseur_id' => $payment->user_id,
                        ],
                        [
                            'montant' => 0,
                            'reste' => 0,
                            'currency' => $payment->currency,
                        ]
                    );

                    $payment->update([
                        'status' => 'success',
                        'garanty_id' => $garanty->id,
                    ]);

                    $amountPaid = $payment->montant;

                    $garanty->increment(
                        'montant',
                        $amountPaid
                    );

                    $garanty->increment(
                        'reste',
                        $amountPaid
                    );
                });

                return response()->json([
                    'code' => '0',
                    'success' => true,
                    'message' => 'Paiement cash effectué avec succès',
                    'order_number' => null,
                ], 200);
            }

            $response = $flexpaieService->mobilePaymentMerchant(
                $reference,
                $validation['montant'],
                $validation['phone'],
                $validation['currency'],
                $description,
                route('api.garanty.callback', ['reference' => $reference])
            );

            $payment->update([
                'order_number' => $response['orderNumber'] ?? null,
            ]);

            return response()->json([
                'code' => $response['code'],
                'success' => (string) $response['code'] === '0',
                'message' => $response['message'] ?? 'Paiement de garantie initié avec succès.',
                'order_number' => $response['orderNumber'] ?? null,
            ], 200);
        } catch (\Exception $e) {

            Log::error('Erreur lors de l\'initiation du paiement de garantie : '.$e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'code' => '1',
                'message' => 'Une erreur est survenue lors de l\'initiation du paiement de garantie.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function callback(Request $request, string $reference)
    {
        try {
            $content = $request->json()->all();
            $callbackCode = $content['code'] ?? null;

            if (! is_int($callbackCode) && ! is_string($callbackCode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code de callback invalide.',
                ], 422);
            }

            $payment = Payment::query()
                ->where('reference', $reference)
                ->where('type', 'garanty')
                ->first();

            if (! $payment) {
                Log::warning('Callback de paiement de garantie introuvable.', ['reference' => $reference]);

                return response()->json([
                    'success' => false,
                    'message' => 'Paiement introuvable.',
                ], 404);
            }

            if ((string) $callbackCode !== '0') {
                $paymentStatus = $this->closeGarantyPayment($payment, 1);

                Log::info('Callback de paiement de garantie signalé en échec.', [
                    'reference' => $reference,
                    'code' => $callbackCode,
                    'payment_status' => $paymentStatus,
                ]);

                return response()->json([
                    'success' => true,
                    'payment_status' => $paymentStatus,
                ]);
            }

            $paymentStatus = $this->closeGarantyPayment($payment, 0);

            Log::info('Callback de paiement de garantie traité.', [
                'reference' => $reference,
                'payment_status' => $paymentStatus,
            ]);

            return response()->json([
                'success' => true,
                'payment_status' => $paymentStatus,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur lors du callback de paiement de garantie.', [
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

        $validation = $request->validate([
            'order_number' => ['required', 'string'],
        ]);

        try {
            $orderNumber = $validation['order_number'];
            $payment = Payment::query()
                ->where('order_number', $orderNumber)
                ->where('user_id', $user->id)
                ->where('type', 'garanty')
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

            $paymentStatus = $this->closeGarantyPayment($payment, $flexStatus);

            return response()->json([
                'code' => $flexStatus,
                'success' => true,
                'payment_status' => $paymentStatus,
                'message' => $response['message'] ?? 'Statut du paiement récupéré avec succès.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la vérification du paiement de garantie.', [
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

    private function closeGarantyPayment(Payment $payment, mixed $flexStatus): ?string
    {
        if (! is_int($flexStatus) && ! is_string($flexStatus)) {
            Log::warning('Statut FlexPay invalide pour un paiement de garantie.', [
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
            Log::warning('Statut FlexPay inconnu pour un paiement de garantie.', [
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
                $paymentOwnerId = $lockedPayment->user_id;
                $currency = $lockedPayment->currency;
                $amountPaid = $lockedPayment->montant;

                $garanty = Garanty::firstOrCreate(
                    [
                        'ramasseur_id' => $paymentOwnerId,
                    ],
                    [
                        'montant' => 0,
                        'reste' => 0,
                        'currency' => $currency,
                    ]
                );

                $garanty->increment('montant', $amountPaid);
                $garanty->increment('reste', $amountPaid);
                $lockedPayment->update([
                    'status' => 'success',
                    'garanty_id' => $garanty->id,
                ]);
            } else {
                $lockedPayment->update(['status' => $newStatus]);
            }

            return $newStatus;
        });
    }
}
