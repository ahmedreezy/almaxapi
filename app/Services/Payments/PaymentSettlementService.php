<?php

namespace App\Services\Payments;

use App\Models\Subscription;
use App\Services\PaymentFailureAlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentSettlementService
{
    public function apply(
        Subscription $subscription,
        bool $success,
        ?string $transactionId,
        string $source,
        array $providerData = [],
        string $failureCategory = 'provider',
        string $failureReason = 'Payment provider reported a failed transaction.'
    ): Subscription {
        $subscriptionId = $subscription->id;

        $settled = DB::transaction(function () use (
            $subscriptionId,
            $success,
            $transactionId,
            $providerData,
            $failureCategory,
            $failureReason
        ) {
            $sub = Subscription::with(['payment', 'group'])->lockForUpdate()->findOrFail($subscriptionId);
            $payment = $sub->payment;

            if ($success) {
                $confirmedAt = $this->completionTime($providerData['completed_at'] ?? null)
                    ?? $payment?->confirmed_at
                    ?? now();

                if ($sub->status !== 'active' && $sub->status !== 'expired') {
                    $sub->update([
                        'status' => 'active',
                        'started_at' => $confirmedAt,
                        'expires_at' => $confirmedAt->copy()->addSeconds($sub->durationSeconds()),
                        'betslip_link' => $sub->betslip_link ?: ($sub->group?->betslip_link ?? ''),
                        'betslip_code' => $sub->betslip_code ?: ($sub->group?->betslip_code ?? ''),
                    ]);
                }

                if ($payment) {
                    $chargeAmount = (float) ($payment->customer_charge_amount ?: $payment->amount);
                    $actualFee = array_key_exists('provider_fee_actual', $providerData)
                        && $providerData['provider_fee_actual'] !== null
                        ? max(0, (float) $providerData['provider_fee_actual'])
                        : $payment->provider_fee_actual;
                    $netAmount = array_key_exists('provider_net_amount', $providerData)
                        && $providerData['provider_net_amount'] !== null
                        ? max(0, (float) $providerData['provider_net_amount'])
                        : ($actualFee !== null ? max(0, $chargeAmount - (float) $actualFee) : $payment->provider_net_amount);

                    $payment->update([
                        'status' => 'confirmed',
                        'confirmed_at' => $payment->confirmed_at ?: $confirmedAt,
                        'transaction_id' => $transactionId ?: $payment->transaction_id,
                        'provider_request_id' => $providerData['provider_request_id'] ?? $payment->provider_request_id,
                        'provider_fee_actual' => $actualFee,
                        'provider_net_amount' => $netAmount,
                        'payment_reference' => $sub->payment_reference,
                        'receipt_number' => $payment->receipt_number ?: 'ALX-RCP-'.Str::upper(Str::random(12)),
                        'failure_category' => null,
                        'failure_reason' => null,
                        'failed_at' => null,
                    ]);
                }

                return $sub->fresh(['payment', 'group']);
            }

            if ($payment?->status === 'confirmed') {
                return $sub;
            }

            $sub->update(['status' => 'failed']);
            if ($payment) {
                $payment->update([
                    'transaction_id' => $transactionId ?: $payment->transaction_id,
                    'provider_request_id' => $providerData['provider_request_id'] ?? $payment->provider_request_id,
                    'payment_reference' => $sub->payment_reference,
                ]);
                app(PaymentFailureAlertService::class)->record($payment, $failureCategory, $failureReason);
            }

            return $sub->fresh(['payment', 'group']);
        }, 3);

        if ($success && $settled->payment) {
            try {
                app(AgentCommissionService::class)->process($settled->payment->fresh(), $source);
            } catch (\Throwable $e) {
                Log::error('Payment settlement: agent commission processing failed.', [
                    'source' => $source,
                    'subscription_id' => $settled->id,
                    'payment_id' => $settled->payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Payment settlement applied.', [
            'source' => $source,
            'subscription_id' => $settled->id,
            'reference' => $settled->payment_reference,
            'success' => $success,
            'transaction_id' => $transactionId,
        ]);

        return $settled;
    }

    private function completionTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
