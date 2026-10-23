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
        return ['collections' => true, 'payouts' => true];
    }

    public function readiness(bool $live = false): array
    {
        $required = [
            'RELWORX_API_URL' => $this->apiUrl(),
            'RELWORX_API_KEY' => (string) config('services.relworx.api_key', ''),
            'RELWORX_ACCOUNT_NO' => (string) config('services.relworx.account_no', ''),
            'RELWORX_WEBHOOK_KEY' => (string) config('services.relworx.webhook_key', ''),
            'RELWORX_WEBHOOK_URL' => (string) config('services.relworx.webhook_url', ''),
            'PAYMENT_COMMISSION_RECIPIENT_MSISDN' => (string) config('services.mobile_money.agent_commission.mobile', ''),
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
        $reference = $payment->provider_request_id ?: $payment->payment_reference;
        if ($reference === '') {
            return ['success' => false, 'status' => 'unknown', 'message' => 'Relworx request reference is unavailable.'];
        }

        try {
            $response = $this->client()->get($this->apiUrl().'/mobile-money/check-request-status', [
                'internal_reference' => $reference,
                'account_no' => config('services.relworx.account_no'),
            ]);
            $json = $response->json() ?: [];
            $status = $this->normalizeStatus((string) ($json['request_status'] ?? $json['status'] ?? 'unknown'));

            return [
                'success' => $response->successful() && $status === 'success',
                'status' => $status,
                'message' => (string) ($json['message'] ?? 'Relworx status checked.'),
                'provider_request_id' => $json['internal_reference'] ?? $reference,
                'transaction_id' => $json['provider_transaction_id'] ?? null,
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            Log::warning('Relworx collection status check failed.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'status' => 'unknown', 'message' => 'Could not reach Relworx.'];
        }
    }

    public function initiatePayout(int $amount, string $reference, string $description, string $mobile): array
    {
        return $this->send('/mobile-money/send-payment', [
            'account_no' => config('services.relworx.account_no'),
            'reference' => substr($reference, 0, 36),
            'msisdn' => $this->normalizePhone($mobile),
            'currency' => config('services.payment_gateway.currency', 'UGX'),
            'amount' => round($amount, 2),
            'description' => $description,
        ], true);
    }

    public function verifyWebhook(Request $request): bool
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
        if ($timestamp < 1 || $signature === '' || abs(time() - $timestamp) > $tolerance) {
            return false;
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

        return hash_equals($hex, $signature) || hash_equals(base64_encode($binary), $signature);
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
