<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletLedgerService
{
    public const BENEFICIARIES = ['owner', 'developer'];

    public function accrueConfirmedPayment(Payment $payment, float $commission, bool $creditDeveloper): void
    {
        $payment->refresh();
        if ($payment->status !== 'confirmed') {
            return;
        }

        $provider = strtolower((string) ($payment->provider ?: 'jpesa'));
        $currency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $commission = max(0, round($commission, 2));
        $providerNet = $payment->provider_net_amount;
        $allocatable = $providerNet === null
            ? (float) $payment->amount
            : min((float) $payment->amount, max(0, (float) $providerNet));
        $ownerAmount = max(0, round($allocatable - $commission, 2));

        DB::transaction(function () use ($payment, $provider, $currency, $commission, $ownerAmount, $creditDeveloper) {
            if ($ownerAmount > 0) {
                $account = $this->account('owner', $provider, $currency);
                WalletLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => "payment:{$payment->id}:owner"],
                    [
                        'wallet_account_id' => $account->id,
                        'payment_id' => $payment->id,
                        'entry_type' => 'payment_credit',
                        'amount' => $ownerAmount,
                        'metadata' => ['payment_reference' => $payment->payment_reference],
                        'created_at' => $payment->confirmed_at ?: now(),
                    ]
                );
            }

            if ($creditDeveloper && $commission > 0) {
                $account = $this->account('developer', $provider, $currency);
                WalletLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => "payment:{$payment->id}:developer"],
                    [
                        'wallet_account_id' => $account->id,
                        'payment_id' => $payment->id,
                        'entry_type' => 'commission_credit',
                        'amount' => $commission,
                        'metadata' => ['payment_reference' => $payment->payment_reference],
                        'created_at' => $payment->confirmed_at ?: now(),
                    ]
                );
            }
        });
    }

    public function summary(string $beneficiary): array
    {
        $currency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $accounts = WalletAccount::query()
            ->where('beneficiary', $beneficiary)
            ->where('currency', $currency)
            ->get();

        $byProvider = [];
        foreach (PaymentGatewayManager::PROVIDERS as $provider) {
            $account = $accounts->firstWhere('provider', $provider) ?? $this->account($beneficiary, $provider, $currency);
            $credited = (float) WalletLedgerEntry::query()
                ->where('wallet_account_id', $account->id)
                ->whereIn('entry_type', ['payment_credit', 'commission_credit'])
                ->sum('amount');
            $available = (float) WalletLedgerEntry::query()
                ->where('wallet_account_id', $account->id)
                ->sum('amount');
            $reserved = (float) PayoutRequest::query()
                ->where('wallet_account_id', $account->id)
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount');

            $byProvider[$provider] = [
                'credited' => round($credited, 2),
                'reserved' => round($reserved, 2),
                'available' => round(max(0, $available), 2),
            ];
        }

        return [
            'currency' => $currency,
            'credited' => round(collect($byProvider)->sum('credited'), 2),
            'reserved' => round(collect($byProvider)->sum('reserved'), 2),
            'available' => round(collect($byProvider)->sum('available'), 2),
            'by_provider' => $byProvider,
        ];
    }

    public function providerSummary(string $beneficiary, string $provider): array
    {
        $currency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $account = $this->account($beneficiary, strtolower($provider), $currency);
        $credited = (float) WalletLedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->whereIn('entry_type', ['payment_credit', 'commission_credit'])
            ->sum('amount');
        $available = (float) WalletLedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->sum('amount');
        $reserved = (float) PayoutRequest::query()
            ->where('wallet_account_id', $account->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');
        $today = (float) WalletLedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->whereIn('entry_type', ['payment_credit', 'commission_credit'])
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('amount');

        return [
            'provider' => strtolower($provider),
            'currency' => $currency,
            'today_credited' => round($today, 2),
            'credited' => round($credited, 2),
            'reserved' => round($reserved, 2),
            'available' => round(max(0, $available), 2),
        ];
    }

    public function createPayoutRequest(string $beneficiary, float $amount, int $actorId): PayoutRequest
    {
        if (! in_array($beneficiary, self::BENEFICIARIES, true)) {
            throw ValidationException::withMessages(['beneficiary' => 'Unsupported wallet beneficiary.']);
        }

        if ((string) config('services.payouts.execution_mode', 'request_only') !== 'request_only') {
            throw ValidationException::withMessages(['amount' => 'The payout execution mode is unavailable.']);
        }

        $provider = 'relworx';
        $currency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $amount = round($amount, 2);
        $destination = $this->destination($beneficiary);
        if ($destination === '') {
            throw ValidationException::withMessages([
                'amount' => strtoupper($beneficiary).' payout destination is not configured on the server.',
            ]);
        }

        return DB::transaction(function () use ($beneficiary, $provider, $currency, $amount, $destination, $actorId) {
            $account = $this->account($beneficiary, $provider, $currency);
            $account = WalletAccount::query()->lockForUpdate()->findOrFail($account->id);
            $available = (float) WalletLedgerEntry::query()
                ->where('wallet_account_id', $account->id)
                ->sum('amount');

            if ($amount < 1 || $amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => 'Payout request exceeds the available Relworx balance of '.number_format(max(0, $available), 2).' '.$currency.'.',
                ]);
            }

            $request = PayoutRequest::query()->create([
                'reference' => 'ALX-PAY-'.strtoupper(Str::random(12)),
                'wallet_account_id' => $account->id,
                'beneficiary' => $beneficiary,
                'provider' => $provider,
                'amount' => $amount,
                'currency' => $currency,
                'destination_msisdn' => $destination,
                'status' => 'pending',
                'requested_by' => $actorId,
            ]);

            WalletLedgerEntry::query()->create([
                'wallet_account_id' => $account->id,
                'payout_request_id' => $request->id,
                'entry_type' => 'payout_reservation',
                'amount' => -$amount,
                'idempotency_key' => "payout:{$request->id}:reserve",
                'metadata' => ['reference' => $request->reference],
                'created_at' => now(),
            ]);

            return $request->fresh();
        }, 3);
    }

    public function cancel(PayoutRequest $request, int $actorId, string $beneficiary): PayoutRequest
    {
        return DB::transaction(function () use ($request, $actorId, $beneficiary) {
            $request = PayoutRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->beneficiary !== $beneficiary || (int) $request->requested_by !== $actorId) {
                abort(403, 'You can only cancel your own payout requests.');
            }
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'Only pending payout requests can be cancelled.']);
            }

            $account = WalletAccount::query()->lockForUpdate()->findOrFail($request->wallet_account_id);
            WalletLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => "payout:{$request->id}:release"],
                [
                    'wallet_account_id' => $account->id,
                    'payout_request_id' => $request->id,
                    'entry_type' => 'payout_release',
                    'amount' => (float) $request->amount,
                    'metadata' => ['reference' => $request->reference],
                    'created_at' => now(),
                ]
            );
            $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $request->fresh();
        }, 3);
    }

    public function account(string $beneficiary, string $provider, string $currency = 'UGX'): WalletAccount
    {
        return WalletAccount::query()->firstOrCreate([
            'beneficiary' => $beneficiary,
            'provider' => $provider,
            'currency' => $currency,
        ]);
    }

    public function serialiseRequest(PayoutRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'beneficiary' => $request->beneficiary,
            'provider' => $request->provider,
            'amount' => (float) $request->amount,
            'currency' => $request->currency,
            'destination' => $this->maskMsisdn($request->destination_msisdn),
            'status' => $request->status,
            'requested_by' => $request->requester?->username,
            'created_at' => $request->created_at,
            'cancelled_at' => $request->cancelled_at,
            'completed_at' => $request->completed_at,
            'message' => $request->status === 'pending' ? 'Awaiting payout connection' : null,
        ];
    }

    private function destination(string $beneficiary): string
    {
        $value = trim((string) config("services.payouts.{$beneficiary}_msisdn", ''));
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '256'.substr($digits, 1);
        }

        return $digits === '' ? '' : '+'.$digits;
    }

    private function maskMsisdn(string $value): string
    {
        return strlen($value) <= 4 ? $value : substr($value, 0, 4).str_repeat('*', max(0, strlen($value) - 8)).substr($value, -4);
    }
}
