<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mobile Money STK Push abstraction.
 *
 * This service is a structured placeholder ready for a specific provider.
 * To integrate a real gateway, set these .env variables and implement
 * the provider-specific HTTP calls below:
 *
 *   MOBILE_MONEY_API_URL=https://api.yourprovider.com
 *   MOBILE_MONEY_API_KEY=your_api_key
 *   MOBILE_MONEY_API_SECRET=your_api_secret
 *   MOBILE_MONEY_WEBHOOK_SECRET=your_webhook_hmac_secret
 *   MOBILE_MONEY_CALLBACK_URL=https://yourdomain.com/api/payments/webhook
 */
class MobileMoneyService
{
    private string $apiUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $callbackUrl;

    public function __construct()
    {
        $this->apiUrl      = config('services.mobile_money.api_url', '');
        $this->apiKey      = config('services.mobile_money.api_key', '');
        $this->apiSecret   = config('services.mobile_money.api_secret', '');
        $this->callbackUrl = config('services.mobile_money.callback_url', '');
    }

    /**
     * Initiate an STK push (phone prompt) to collect payment.
     *
     * @param  string $phone           Customer phone number (e.g. "0772000000")
     * @param  float  $amount          Amount to charge in UGX
     * @param  string $reference       Unique internal reference (subscription ID + timestamp)
     * @param  string $paymentMethod   'mtn' | 'airtel'
     * @return array{success: bool, reference: string, message: string, raw?: array}
     */
    public function initiateSTKPush(string $phone, float $amount, string $reference, string $paymentMethod): array
    {
        // ── STUB: Replace this block with the real provider API call ──────
        //
        // Example for a generic provider:
        //
        // $response = Http::withToken($this->apiKey)
        //     ->post($this->apiUrl . '/payments/initiate', [
        //         'phone'         => $phone,
        //         'amount'        => $amount,
        //         'currency'      => 'UGX',
        //         'reference'     => $reference,
        //         'provider'      => $paymentMethod,   // 'mtn' | 'airtel'
        //         'callback_url'  => $this->callbackUrl,
        //         'description'   => 'Almax VIP Subscription',
        //     ]);
        //
        // if ($response->successful()) {
        //     return [
        //         'success'   => true,
        //         'reference' => $reference,
        //         'message'   => 'Payment request sent to your phone.',
        //         'raw'       => $response->json(),
        //     ];
        // }
        //
        // Log::warning('STK push failed', ['status' => $response->status(), 'body' => $response->body()]);
        // return [
        //     'success' => false,
        //     'reference' => $reference,
        //     'message' => $response->json('message') ?? 'Payment initiation failed.',
        // ];
        // ── END STUB ───────────────────────────────────────────────────────

        // While the provider is not yet configured, return a pending state
        // so the subscription record is created and can be activated manually
        // or via webhook once credentials are plugged in.
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            Log::info('MobileMoneyService: provider not configured — subscription created as pending.', [
                'phone'     => $phone,
                'amount'    => $amount,
                'reference' => $reference,
                'method'    => $paymentMethod,
            ]);

            return [
                'success'   => false,
                'reference' => $reference,
                'message'   => 'Payment provider not yet configured. Your request has been recorded and will be processed manually.',
                'pending'   => true,
            ];
        }

        // ── Real implementation goes here once credentials are available ──
        return [
            'success'   => false,
            'reference' => $reference,
            'message'   => 'Payment provider integration pending.',
        ];
    }

    /**
     * Validate a webhook payload signature from the payment provider.
     *
     * @param  string $payload   Raw request body (JSON string)
     * @param  string $signature Signature header from the provider
     * @return bool
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.mobile_money.webhook_secret', '');

        if (empty($secret)) {
            // No secret configured — accept all webhooks (development only)
            Log::warning('MobileMoneyService: webhook_secret not set; skipping signature validation.');
            return true;
        }

        // ── STUB: Replace with provider-specific HMAC verification ────────
        //
        // Common pattern (HMAC-SHA256):
        // $expected = hash_hmac('sha256', $payload, $secret);
        // return hash_equals($expected, $signature);
        //
        // ── END STUB ───────────────────────────────────────────────────────

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Parse a webhook payload into a normalised structure.
     *
     * @param  array $data Decoded JSON body from the provider
     * @return array{reference: string, status: string, transaction_id: string|null}
     */
    public function parseWebhookPayload(array $data): array
    {
        // ── STUB: Map provider-specific fields to our internal structure ──
        //
        // Example:
        // return [
        //     'reference'      => $data['external_reference'] ?? $data['ref'] ?? '',
        //     'status'         => strtolower($data['status'] ?? ''),    // 'success' | 'failed'
        //     'transaction_id' => $data['transaction_id'] ?? $data['txn_id'] ?? null,
        // ];
        // ── END STUB ──────────────────────────────────────────────────────

        return [
            'reference'      => $data['reference']      ?? $data['external_reference'] ?? '',
            'status'         => strtolower($data['status'] ?? 'unknown'),
            'transaction_id' => $data['transaction_id'] ?? null,
        ];
    }
}
