<?php

namespace App\Services\Payments;

use App\Models\PayoutRequest;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayoutExecutionService
{
    public function __construct(
        private readonly RelworxGateway $relworx,
    ) {}

    public function execute(PayoutRequest $payout): PayoutRequest
    {
        $claimed = PayoutRequest::query()
            ->whereKey($payout->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'processing',
                'failure_reason' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return $payout->fresh();
        }

        $payout = $payout->fresh();
        $result = $this->relworx->initiatePayout(
            (int) round((float) $payout->amount),
            $payout->reference,
            'Almax '.ucfirst($payout->beneficiary).' wallet payout',
            $payout->destination_msisdn,
        );

        $providerRequestId = $result['provider_request_id'] ?? $result['transaction_id'] ?? null;
        if ($result['success'] ?? false) {
            $payout->update([
                'provider_request_id' => $providerRequestId,
                'failure_reason' => null,
            ]);

            if ($result['final'] ?? false) {
                return $this->settle($payout, 'success', $result);
            }

            return $payout->fresh();
        }

        if ($result['pending'] ?? false) {
            $payout->update([
                'provider_request_id' => $providerRequestId,
                'failure_reason' => substr((string) ($result['message'] ?? 'Relworx payout outcome is pending.'), 0, 1000),
            ]);

            return $payout->fresh();
        }

        Log::warning('Relworx payout request was rejected.', [
            'payout_request_id' => $payout->id,
            'reference' => $payout->reference,
            'beneficiary' => $payout->beneficiary,
            'message' => $result['message'] ?? null,
        ]);

        return $this->settle($payout, 'failed', $result);
    }

    public function settle(PayoutRequest $payout, string $status, array $providerData = []): PayoutRequest
    {
        return DB::transaction(function () use ($payout, $status, $providerData) {
            $payout = PayoutRequest::query()->lockForUpdate()->findOrFail($payout->id);
            if (in_array($payout->status, ['completed', 'failed', 'cancelled'], true)) {
                return $payout;
            }

            $providerRequestId = $providerData['provider_request_id'] ?? null;
            $transactionId = $providerData['transaction_id'] ?? null;
            $fee = isset($providerData['provider_fee_actual'])
                ? max(0, round((float) $providerData['provider_fee_actual'], 2))
                : null;

            if ($status === 'success') {
                $reservation = WalletLedgerEntry::query()
                    ->where('idempotency_key', "payout:{$payout->id}:reserve")
                    ->first();
                $reservation?->update([
                    'entry_type' => 'payout_debit',
                    'metadata' => [
                        'reference' => $payout->reference,
                        'provider_request_id' => $providerRequestId ?: $payout->provider_request_id,
                        'transaction_id' => $transactionId,
                    ],
                ]);

                if ($fee !== null && $fee > 0) {
                    WalletLedgerEntry::query()->firstOrCreate(
                        ['idempotency_key' => "payout:{$payout->id}:fee"],
                        [
                            'wallet_account_id' => $payout->wallet_account_id,
                            'payout_request_id' => $payout->id,
                            'entry_type' => 'payout_fee',
                            'amount' => -$fee,
                            'metadata' => ['reference' => $payout->reference],
                            'created_at' => now(),
                        ]
                    );
                }

                $payout->update([
                    'status' => 'completed',
                    'provider_request_id' => $providerRequestId ?: $payout->provider_request_id,
                    'transaction_id' => $transactionId ?: $payout->transaction_id,
                    'provider_fee_actual' => $fee ?? $payout->provider_fee_actual,
                    'failure_reason' => null,
                    'completed_at' => $providerData['completed_at'] ?? now(),
                ]);

                return $payout->fresh();
            }

            if ($status === 'failed') {
                WalletLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => "payout:{$payout->id}:release"],
                    [
                        'wallet_account_id' => $payout->wallet_account_id,
                        'payout_request_id' => $payout->id,
                        'entry_type' => 'payout_release',
                        'amount' => (float) $payout->amount,
                        'metadata' => ['reference' => $payout->reference],
                        'created_at' => now(),
                    ]
                );
                $payout->update([
                    'status' => 'failed',
                    'provider_request_id' => $providerRequestId ?: $payout->provider_request_id,
                    'transaction_id' => $transactionId ?: $payout->transaction_id,
                    'failure_reason' => substr((string) ($providerData['message'] ?? 'Relworx payout failed.'), 0, 1000),
                    'completed_at' => $providerData['completed_at'] ?? now(),
                ]);
            }

            return $payout->fresh();
        }, 3);
    }
}
