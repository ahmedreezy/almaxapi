<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RelworxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'relworx';
    }

    public function capabilities(): array
    {
        return [
            'collections' => true,
            'payouts' => (bool) config('services.relworx.payouts_enabled', false),
        ];
    }

    public function readiness(bool $live = false): array
    {
        $required = [
            'RELWORX_API_URL' => $this->apiUrl(),
            'RELWORX_API_KEY' => (string) config('services.relworx.api_key', ''),
            'RELWORX_ACCOUNT_NO' => (string) config('services.relworx.account_no', ''),
            'RELWORX_WEBHOOK_KEY' => (string) config('services.relworx.webhook_key', ''),
            'RELWORX_WEBHOOK_URL' => (string) config('services.relworx.webhook_url', ''),
        ];
        $missing = array_keys(array_filter($required, fn ($value) => trim($value) === ''));
        if ($missing !== []) {
            return [
                'configured' => false,
                'ready' => false,
                'missing' => $missing,
                'message' => 'Relworx configuration is incomplete.',
            ];
        }

        if (! $live) {
            return ['configured' => true, 'ready' => true, 'missing' => [], 'message' => 'Relworx is configured.'];
        }

        try {
            $response = $this->client()->get($this->apiUrl().'/payment-requests/transactions', [
                'account_no' => config('services.relworx.account_no'),
            ]);
            $body = $response->json();
            $ready = $response->successful() && is_array($body) && ($body['success'] ?? true) !== false;

            return [
                'configured' => true,
                'ready' => $ready,
                'missing' => [],
                'message' => $ready
                    ? 'Relworx account access verified.'
                    : (string) ($body['message'] ?? 'Relworx rejected the account readiness check.'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Relworx readiness check failed.', ['error' => $e->getMessage()]);

            return ['configured' => true, 'ready' => false, 'missing' => [], 'message' => 'Could not reach Relworx.'];
        }
    }

    public function initiateCollection(string $phone, float $amount, string $reference, string $paymentMethod): array
    {
        return $this->send('/mobile-money/request-payment', [
            'account_no' => config('services.relworx.account_no'),
            'reference' => substr($reference, 0, 36),
            'msisdn' => $this->normalizePhone($phone),
            'currency' => config('services.payment_gateway.currency', 'UGX'),
            'amount' => round($amount, 2),
            'description' => 'Almax VIP Subscription',
        ], false);
    }

    public function queryCollection(Payment $payment, ?string $manualReference = null): array
    {
        $references = array_values(array_unique(array_filter([
            trim((string) $manualReference),
            trim((string) $payment->payment_reference),
            trim((string) $payment->provider_request_id),
        ])));
        if ($references === []) {
            return ['success' => false, 'status' => 'unknown', 'message' => 'Relworx request reference is unavailable.'];
        }

        $last = ['success' => false, 'status' => 'unknown', 'message' => 'Relworx status is unavailable.'];
        foreach ($references as $reference) {
            try {
                $response = $this->client()->get($this->apiUrl().'/mobile-money/check-request-status', [
                    'internal_reference' => $reference,
                    'account_no' => config('services.relworx.account_no'),
                ]);
                $json = $response->json() ?: [];
                $status = $this->normalizeStatus((string) ($json['request_status'] ?? $json['status'] ?? 'unknown'));
                $customerReference = trim((string) ($json['customer_reference'] ?? ''));
                $referenceMatches = $customerReference === ''
                    ? $reference === (string) $payment->payment_reference
                    : hash_equals((string) $payment->payment_reference, $customerReference);

                $last = [
                    'success' => $response->successful() && $status === 'success' && $referenceMatches,
                    'status' => $referenceMatches ? $status : 'unknown',
                    'message' => $referenceMatches
                        ? (string) ($json['message'] ?? 'Relworx status checked.')
                        : 'Relworx returned a different customer reference.',
                    'reference_matches' => $referenceMatches,
                    'customer_reference' => $customerReference,
                    'provider_request_id' => $json['internal_reference'] ?? null,
                    'transaction_id' => $json['provider_transaction_id'] ?? null,
                    'amount' => isset($json['amount']) ? (float) $json['amount'] : null,
                    'currency' => strtoupper((string) ($json['currency'] ?? '')),
                    'provider_fee_actual' => isset($json['charge']) ? (float) $json['charge'] : null,
                    'completed_at' => $json['completed_at'] ?? null,
                    'raw' => $json,
                ];

                if ($referenceMatches) {
                    return $last;
                }
            } catch (\Throwable $e) {
                Log::warning('Relworx collection status check failed.', [
                    'payment_id' => $payment->id,
                    'reference_type' => $reference === $payment->payment_reference ? 'customer' : 'provider',
                    'error' => $e->getMessage(),
                ]);
                $last = ['success' => false, 'status' => 'unknown', 'message' => 'Could not reach Relworx.'];
            }
        }

        return $last;
    }

    public function initiatePayout(int $amount, string $reference, string $description, string $mobile): array
    {
        if (! (bool) config('services.relworx.payouts_enabled', false)) {
            return [
                'success' => false,
                'pending' => false,
                'final' => false,
                'message' => 'Relworx API payouts are not enabled for this account.',
            ];
        }

        return $this->send('/mobile-money/send-payment', [
            'account_no' => config('services.relworx.account_no'),
            'reference' => substr($reference, 0, 36),
            'msisdn' => $this->normalizePhone($mobile),
            'currency' => config('services.payment_gateway.currency', 'UGX'),
            'amount' => round($amount, 2),
            'description' => $description,
        ], true);
    }

    public function walletBalance(): array
    {
        $readiness = $this->readiness(false);
        if (! $readiness['configured']) {
            return [
                'available' => false,
                'balance' => null,
                'currency' => config('services.payment_gateway.currency', 'UGX'),
                'message' => $readiness['message'],
            ];
        }

        try {
            $currency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
            $response = $this->client()->get($this->apiUrl().'/mobile-money/check-wallet-balance', [
                'account_no' => config('services.relworx.account_no'),
                'currency' => $currency,
            ]);
            $json = $response->json() ?: [];
            $available = $response->successful() && ($json['success'] ?? false) === true;

            return [
                'available' => $available,
                'balance' => $available ? (float) ($json['balance'] ?? 0) : null,
                'currency' => $currency,
                'checked_at' => now()->toIso8601String(),
                'message' => $available ? 'Relworx wallet balance retrieved.' : (string) ($json['message'] ?? 'Relworx wallet balance is unavailable.'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Relworx wallet balance check failed.', ['error' => $e->getMessage()]);

            return [
                'available' => false,
                'balance' => null,
                'currency' => config('services.payment_gateway.currency', 'UGX'),
                'checked_at' => now()->toIso8601String(),
                'message' => 'Could not reach Relworx for the live wallet balance.',
            ];
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->webhookVerification($request)['valid'];
    }

    public function webhookVerification(Request $request): array
    {
        $header = (string) $request->header('Relworx-Signature', '');
        $parts = [];
        foreach (explode(',', $header) as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = isset($parts['t']) && ctype_digit((string) $parts['t']) ? (int) $parts['t'] : 0;
        $signature = (string) ($parts['v'] ?? '');
        $tolerance = max(30, (int) config('services.relworx.webhook_tolerance', 300));
        if ($timestamp < 1 || $signature === '') {
            return ['valid' => false, 'reason' => 'missing_signature_parts', 'timestamp_age' => null];
        }
        $timestampAge = abs(time() - $timestamp);
        if ($timestampAge > $tolerance) {
            return ['valid' => false, 'reason' => 'stale_timestamp', 'timestamp_age' => $timestampAge];
        }

        $params = [
            'customer_reference' => (string) $request->input('customer_reference', ''),
            'internal_reference' => (string) $request->input('internal_reference', ''),
            'status' => (string) $request->input('status', ''),
        ];
        ksort($params);
        $signed = (string) config('services.relworx.webhook_url', '').$timestamp;
        foreach ($params as $key => $value) {
            $signed .= $key.$value;
        }

        $binary = hash_hmac('sha256', $signed, (string) config('services.relworx.webhook_key', ''), true);
        $hex = bin2hex($binary);

        $valid = hash_equals($hex, $signature) || hash_equals(base64_encode($binary), $signature);

        return [
            'valid' => $valid,
            'reason' => $valid ? 'valid' : 'signature_mismatch',
            'timestamp_age' => $timestampAge,
        ];
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'reference' => (string) $request->input('customer_reference', ''),
            'provider_request_id' => (string) $request->input('internal_reference', ''),
            'transaction_id' => $request->input('provider_transaction_id'),
            'status' => $this->normalizeStatus((string) $request->input('status', 'unknown')),
            'message' => (string) $request->input('message', ''),
            'amount' => $request->input('amount'),
            'provider_fee_actual' => $request->input('charge'),
            'completed_at' => $request->input('completed_at'),
            'currency' => strtoupper((string) $request->input('currency', '')),
        ];
    }

    private function send(string $path, array $payload, bool $payout): array
    {
        $readiness = $this->readiness(false);
        if (! $readiness['ready']) {
            return ['success' => false, 'pending' => false, 'message' => $readiness['message']];
        }

        try {
            $response = $this->client()->post($this->apiUrl().$path, $payload);
            $json = $response->json() ?: [];
            $accepted = $response->successful() && ($json['success'] ?? false) === true;
            $uncertain = $response->serverError() || $response->status() === 408;

            return [
                'success' => $accepted,
                'pending' => $accepted || $uncertain,
                'final' => false,
                'message' => (string) ($json['message'] ?? match (true) {
                    $accepted => 'Relworx request accepted.',
                    $uncertain => 'Relworx did not return a definitive response. The request remains pending.',
                    default => 'Relworx rejected the request.',
                }),
                'provider_request_id' => $json['internal_reference'] ?? null,
                'transaction_id' => null,
                'raw' => $json,
            ];
        } catch (ConnectionException $e) {
            Log::warning('Relworx request outcome is uncertain.', ['path' => $path, 'error' => $e->getMessage()]);

            return [
                'success' => false,
                'pending' => true,
                'final' => false,
                'message' => 'Relworx did not return a definitive response. The request remains pending.',
            ];
        } catch (\Throwable $e) {
            Log::error('Relworx request failed.', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'pending' => false, 'final' => false, 'message' => 'Relworx request failed.'];
        }
    }

    private function client()
    {
        $timeout = min(60, max(5, (int) config('services.relworx.timeout', 30)));

        return Http::accept('application/vnd.relworx.v2')
            ->withToken((string) config('services.relworx.api_key', ''))
            ->asJson()
            ->timeout($timeout);
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.relworx.api_url', ''), '/');
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '256'.substr($digits, 1);
        }

        return $digits === '' ? '' : '+'.$digits;
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success', 'successful', 'completed', 'approved' => 'success',
            'failed', 'failure', 'declined', 'rejected', 'cancelled', 'canceled', 'error' => 'failed',
            'pending', 'processing', 'queued' => 'pending',
            default => 'unknown',
        };
    }
}
