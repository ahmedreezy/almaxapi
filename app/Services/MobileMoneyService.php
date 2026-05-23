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

        $normalizedPhone = $this->normalizePhone($phone);
        $apiUrl = $this->apiUrl;
        if (str_contains($apiUrl, 'my.jpesa.com') && str_contains($apiUrl, '/api/collect')) {
            $apiUrl = 'https://my.jpesa.com/api/';
        }

        $xml = $this->buildJpesaXml([
            '_key_'       => $this->apiKey,
            'cmd'         => 'account',
            'action'      => 'credit',
            'pt'          => 'mm',
            'mobile'      => $normalizedPhone,
            'amount'      => (string) ((int) round($amount)),
            'callback'    => $this->callbackUrl,
            'tx'          => $reference,
            'description' => 'Almax VIP Subscription',
        ]);

        try {
            $response = Http::withHeaders([
                    'Content-Type' => 'text/xml',
                    'Accept'       => 'application/json',
                ])
                ->timeout(30)
                ->withBody($xml, 'text/xml')
                ->post($apiUrl);

            $json = json_decode((string) $response->body(), true);
            if (! is_array($json)) {
                $json = [];
            }
            $status = strtolower((string) (
                ($json['status'] ?? null)
                ?? ($json['payment_status'] ?? null)
                ?? ($json['result'] ?? null)
                ?? ''
            ));
            $apiStatus = strtolower((string) (($json['api_status'] ?? $json['apiStatus'] ?? '')));
            $messageText = strtolower((string) ($json['message'] ?? $json['msg'] ?? ''));

            $looksAccepted = in_array($status, ['success', 'successful', 'accepted', 'pending', 'processing', 'queued'], true);
            $looksError = in_array($status, ['error', 'failed', 'failure', 'declined', 'rejected', 'cancelled'], true)
                || in_array($apiStatus, ['error', 'failed', 'failure'], true)
                || str_contains($messageText, 'invalid')
                || str_contains($messageText, 'missing api key')
                || str_contains($messageText, 'unauthor');

            $success = $response->successful() && ! $looksError && ($looksAccepted || ($status === '' && $apiStatus !== 'error'));

            if ($success) {
                return [
                    'success'   => true,
                    'reference' => $reference,
                    'message'   => $json['message'] ?? $json['msg'] ?? 'Payment request sent to your phone.',
                    'raw'       => is_array($json) ? $json : ['body' => $response->body()],
                ];
            }

            Log::warning('MobileMoneyService: STK push rejected by provider.', [
                'status_code' => $response->status(),
                'status'      => $status,
                'reference'   => $reference,
                'endpoint'    => $apiUrl,
                'body'        => $response->body(),
            ]);

            return [
                'success'   => false,
                'reference' => $reference,
                'message'   => (is_array($json) ? ($json['message'] ?? null) : null)
                    ?? 'Payment initiation failed at provider.',
                'raw'       => is_array($json) ? $json : ['body' => $response->body()],
            ];
        } catch (\Throwable $e) {
            Log::error('MobileMoneyService: STK push request failed.', [
                'reference' => $reference,
                'endpoint'  => $apiUrl,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'reference' => $reference,
                'message'   => 'Payment request could not be sent. Please try again shortly.',
            ];
        }
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
        $status = strtolower((string) (
            $data['status']
            ?? $data['payment_status']
            ?? $data['result']
            ?? $data['state']
            ?? 'unknown'
        ));

        if (in_array($status, ['ok', 'success', 'successful', 'completed'], true)) {
            $status = 'success';
        } elseif (in_array($status, ['approved'], true)) {
            $status = 'success';
        } elseif (in_array($status, ['failed', 'failure', 'cancelled', 'declined', 'rejected', 'error'], true)) {
            $status = 'failed';
        }

        return [
            'reference'      => $data['reference']
                ?? $data['external_reference']
                ?? $data['external_ref']
                ?? $data['merchant_reference']
                ?? $data['merchant_ref']
                ?? $data['order_reference']
                ?? $data['memo']
                ?? $data['tx']
                ?? $data['ref']
                ?? '',
            'status'         => $status,
            'transaction_id' => $data['transaction_id']
                ?? $data['txn_id']
                ?? $data['txnid']
                ?? $data['trx_id']
                ?? $data['tr_id']
                ?? $data['provider_txn_id']
                ?? $data['tid']
                ?? null,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone ?? '') ?? '';

        if (str_starts_with($digits, '256')) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '256' . substr($digits, 1);
        }

        return $digits;
    }

    private function buildJpesaXml(array $fields): string
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n<g7bill>\n";
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'ISO-8859-1');
            $xml .= "  <{$key}>{$escaped}</{$key}>\n";
        }
        $xml .= '</g7bill>';

        return $xml;
    }
}
