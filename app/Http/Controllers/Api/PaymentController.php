<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collecte;
use App\Models\Payment;
use App\Services\FlexpaieService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{

    public function initiate(Request $request, FlexpaieService $flexpaieService)
    {
        try {

            $user = $request->user();

            $validation = $request->validate([
                'collecte_id' => ['required', 'exists:collectes,id'],
                'montant' => 'required|numeric|min:5000',
                'currency' => 'required|string|in:CDF,USD',
                'phone' => 'required|string|size:12',
                'temps_attente' => 'nullable|string',
            ]);

            $collecte = Collecte::find($validation['collecte_id']);

            if (!$collecte) {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => 'Erreur : Collecte non retrouvée !',
                ], 409);
            }

            $collecte->update([
                'temps_attente' => $validation['temps_attente']
            ]);

            $reference = 'PCOL-' . strtoupper(uniqid());
            $description = 'Paiement de la collecte de ' . $user->name;

            $payment = Payment::create([
                'user_id' => $user->id,
                'type' => 'collecte',
                'montant' => $validation['montant'],
                'currency' => $validation['currency'] ?? 'CDF',
                'phone' => $validation['phone'],
                'collecte_id' => $collecte->id,
                'reference' => $reference,
                'status' => 'pending',
                'description' => $description,
            ]);

            $response = $flexpaieService->mobilePayment(
                $reference,
                $validation['montant'],
                $validation['phone'],
                $validation['currency'],
                $description,
                route('api.collecte.callback')
            );

            $payment->update([
                'order_number' => $response['orderNumber'] ?? null,
            ]);

            return response()->json([
                'code' => $response['code'],
                'success' => (string) $response['code'] === "0",
                'message' => $response['message'] ?? 'Paiement de la collecte initié avec succès.',
                'order_number' => $response['orderNumber'] ?? null,
            ], 200);
        } catch (\Exception $e) {

            Log::error('Erreur lors de l\'initiation du paiement de le collecte : ' . $e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'request_data' => $request->all(),
            ]);
            return response()->json([
                'code' => '1',
                'message' => 'Une erreur est survenue lors de l\'initiation du paiement de le collecte.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function callback(Request $request, $reference)
    {
        try {

            $content = json_decode($request->getContent(), true);
            $payment = Payment::where('reference', $reference)->first();
            $flexStatus = $content['status'] ?? 2;

            if (!$payment) {
                Log::warning("Payment introuvable : $reference");

                return response()->json([
                    'success' => false,
                    'message' => 'Payment introuvable.'
                ], 404);
            }

            if ($payment) {
                $this->closeCollectePayment($payment, $flexStatus, $content);
            }

            Log::error('Callback received: ' . $reference . ' - ' . json_encode($request->all()));
            return response()->json([
                'success' => true
            ]);
        } catch (\Throwable $e) {
            Log::error('Callback error: ' . $e->getMessage());
        }
    }

    public function check(Request $request, FlexpaieService $flexpaieService)
    {
        try {
            $validation = $request->validate([
                'order_number' => 'required|string',
            ]);

            $order_number = $validation['order_number'];
            $payment = Payment::where('order_number', $order_number)->first();

            if (!$payment) {
                return response()->json([
                    'code' => '1',
                    'success' => false,
                    'message' => 'Payment introuvable.'
                ], 404);
            }

            $response = $flexpaieService->getPaymentStatus($order_number);
            $flexStatus = $response['transaction']['status'] ?? 2;

            if ((string) $response['code'] != "0") {
                return response()->json([
                    'code' => "1",
                    'success' => false,
                    'message' => $response['message'] ?? 'Une erreur est survenue lors de la vérification du paiement.'
                ], 404);
            }


            $this->closeCollectePayment($payment, $flexStatus, $response);

            return response()->json([
                'code' => $flexStatus,
                'success' => true,
                'message' => $response['message'] ?? 'Statut du paiement récupéré avec succès.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Check payment error: ' . $e->getMessage());
            return response()->json([
                'code' => '1',
                'success' => false,
                'message' => 'Une erreur est survenue lors de la vérification du paiement.'
            ], 500);
        }
    }

    private function closeCollectePayment(Payment $payment, string $flexStatus, $content = null)
    {
        switch ($flexStatus) {
            case 0:
                if ($payment->status === 'success') {
                    break;
                }

                DB::transaction(function () use ($payment) {
                    $payment->update([
                        'status' => 'success'
                    ]);

                    $collecteId = $payment->id;
                    $collecte = Collecte::find($collecteId);

                    $collecte->updated([
                        'statut' => 'terminee',
                        'terminee_at' => now()
                    ]);
                });

                break;
            case 1:
                $payment->update([
                    'status' => 'failed'
                ]);
                break;
            case 2:
                $payment->update([
                    'status' => 'pending'
                ]);
                break;
            case 4:
                $payment->update([
                    'status' => 'cancelled'
                ]);
                break;
            default:
                logger()->warning('Statut FlexPay inconnu', [
                    'order_number' => $payment->order_number,
                    'status' => $flexStatus,
                    'response' => $content,
                ]);
                break;
        }
    }

}
