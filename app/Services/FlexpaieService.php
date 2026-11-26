<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FlexpaieService
{

    const BASE_URL_V1 = "https://corporateapi.flexpay.cd/api/rest/v1/paymentService";
    const BASE_URL_V2 = "https://corporateapi.flexpay.cd/api/rest/v2/paymentService";
    const BASE_URL_CHECK = "https://apicheck.flexpaie.com/api/rest/v1/check/";

    const SUCCESS = 0;

    public function mobilePaymentMerchant(
        $reference,
        $amount,
        $phone,
        $currency,
        $description,
        $callbackUrl
    ): array {
        $response = Http::withHeaders([
            'Authorization' => config('services.flexpay.token'),
            'Content-Type' => 'application/json',
        ])->post(self::BASE_URL_V1, [
            'merchant' => 'nepa_rdc',
            'type' => '1',
            'reference' => $reference,
            'description' => $description,
            'phone' => $phone,
            'amount' => $amount,
            //'amount' => "100",
            'currency' => $currency,
            'callback_url' => $callbackUrl,
        ]);

        logger()->info('FlexPay mobile', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'json' => $response->json()
        ]);

        return $response->json() ?? [
            'code' => 1,
            'message' => 'Réponse vide de FlexPay',
        ];
    }

    public function mobilePaymentShop(
        $shopCode,
        $reference,
        $amount,
        $phone,
        $currency,
        $description,
        $callbackUrl
    ): array {
        $response = Http::withHeaders([
            'Authorization' => config('services.flexpay.token'),
            'Content-Type' => 'application/json',
        ])->post(self::BASE_URL_V2, [
            'merchant' => 'nepa_rdc',
            'shop_code' => $shopCode,
            'type' => '1',
            'reference' => $reference,
            'description' => $description,
            'phone' => $phone,
            'amount' => $amount,
            'currency' => $currency,
            'callback_url' => $callbackUrl,
        ]);

        logger()->info('FlexPay mobile', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'json' => $response->json()
        ]);

        return $response->json() ?? [
            'code' => 1,
            'message' => 'Réponse vide de FlexPay',
        ];
    }

    public function cardPayment($reference, $amount, $currency, $callbackUrl, $approveUrl, $cancelUrl, $declineUrl): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(self::BASE_URL_V2, [
            'authorization' => config('services.flexpay.token'),
            'merchant' => 'nepa_rdc',
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'description' => "Test de paiement mobile pour l'application Nepa Bopeto",
            'callback_url' => $callbackUrl,
            'approve_url' => $approveUrl,
            'cancel_url' => $cancelUrl,
            'decline_url' => $declineUrl,
        ]);

        logger()->info('FlexPay card', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'json' => $response->json()
        ]);

        return $response->json() ?? [
            'code' => 1,
            'message' => 'Réponse vide de FlexPay',
        ];
    }

    public function getPaymentStatus(string $ordernumber): array
    {
        $response = Http::withHeaders([
            'Authorization' => config('services.flexpay.token'),
        ])->get(self::BASE_URL_CHECK . $ordernumber);

        return $response->json();
    }
}
