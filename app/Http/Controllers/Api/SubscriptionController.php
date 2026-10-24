<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentFailureAlertService;
use App\Services\Payments\AgentCommissionService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentPricingService;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manages the full subscription lifecycle.
 *
 * Subscriptions are now group-based. The client sends a groupId; the price
 * is always read from the group record (never trusted from the client).
 * Payment is initiated via STK Push through MobileMoneyService.
 * Activation happens automatically via the payment webhook.
 */
class SubscriptionController extends Controller
{
    /** Public: quote the server-controlled price for the currently active gateway. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['groupId' => ['required', 'integer', 'exists:groups,id']]);
        $group = Group::findOrFail($data['groupId']);
        if (! $group->isPurchasable()) {
            return response()->json(['message' => 'This package is currently unavailable.'], 422);
        }

        $provider = app(PaymentGatewayManager::class)->activeName();

        return response()->json(app(PaymentPricingService::class)->quote($group->effectivePrice(), $provider));
    }

    /** Admin: list all subscriptions with auto-expiry updates */
    public function index(): JsonResponse
    {
        // Auto-delete stale pending/failed subs whose booking deadline has passed
        $this->cleanupDeadlineSubscriptions();

        // Timeout pending subscriptions that have been waiting more than 1 hour
        $stalePending = Subscription::with('payment')->where('status', 'pending')
            ->where('created_at', '<', now()->subHour())
            ->get();
        foreach ($stalePending as $staleSub) {
            $staleSub->update(['status' => 'failed']);
            if ($staleSub->payment && $staleSub->payment->status === 'pending') {
                app(PaymentFailureAlertService::class)->record(
                    $staleSub->payment,
                    'provider',
                    'Payment provider did not return a final status within one hour.'
                );
            }
        }

        Subscription::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $subs = Subscription::with(['user', 'payment', 'group'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($subs);
    }

    /** Public: get a user's subscriptions. Only returns betslip for active subs. */
    public function forUser(int $userId): JsonResponse
    {
        $subs = Subscription::where('user_id', $userId)
            ->with(['group', 'payment'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Subscription $sub) {
                if ($sub->isExpired()) {
                    $sub->update(['status' => 'expired']);
                    $sub->status = 'expired';
                }

                return $this->formatSub($sub, $sub->status === 'active');
            });

        return response()->json($subs);
    }

    /** Authenticated user: get only the subscriptions owned by this account. */
    public function forMe(Request $request): JsonResponse
    {
        return $this->forUser($request->user()->id);
    }

    /**
     * Public: create a subscription and initiate STK push.
     *
     * Expected body: { userId, groupId, paymentMethod, phone }
     * - groupId   → looked up from the groups table; price is taken from the group
     * - phone     → the mobile money number that will receive the STK push
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'groupId' => ['required', 'integer', 'exists:groups,id'],
            'paymentMethod' => ['required', 'string', 'in:mtn,airtel'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $user = User::findOrFail($data['userId']);
        if ($user->blacklisted) {
            return response()->json([
                'error' => 'Blacklisted',
                'message' => 'This phone number has been blacklisted.',
            ], 403);
        }

        // Fail any pending records for this user+group older than 1 hour so they don't stack
        $stale = Subscription::with('payment')
            ->where('user_id', $data['userId'])
            ->where('group_id', $data['groupId'])
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHour())
            ->get();
        foreach ($stale as $staleSub) {
            $staleSub->update(['status' => 'failed']);
            if ($staleSub->payment) {
                app(PaymentFailureAlertService::class)->record(
                    $staleSub->payment,
                    'provider',
                    'Payment provider did not return a final status within one hour.'
                );
            }
        }

        $duplicateWindowSeconds = max((int) env('PAYMENT_DUPLICATE_WINDOW_SECONDS', 180), 30);
        $existingPending = Subscription::with(['payment', 'group'])
            ->where('user_id', $data['userId'])
            ->where('group_id', $data['groupId'])
            ->where('payment_method', $data['paymentMethod'])
            ->where('phone', $data['phone'])
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subSeconds($duplicateWindowSeconds))
            ->orderByDesc('created_at')
            ->first();

        if ($existingPending) {
            $existingPayment = $existingPending->payment;
            return response()->json([
                'message' => 'A payment request is already in progress for this package. Please approve the existing prompt or wait a moment before retrying.',
                'existingSubscriptionId' => $existingPending->id,
                'paymentReference' => $existingPending->payment_reference,
                'paymentProvider' => $existingPending->payment?->provider ?? 'jpesa',
                'quote' => $existingPayment ? [
                    'baseAmount' => (float) $existingPayment->amount,
                    'providerFee' => (float) ($existingPayment->provider_fee_estimate ?? 0),
                    'totalCharge' => (float) ($existingPayment->customer_charge_amount ?? $existingPayment->amount),
                    'currency' => strtoupper((string) config('services.payment_gateway.currency', 'UGX')),
                    'provider' => $existingPayment->provider ?? 'jpesa',
                ] : null,
                'status' => $existingPending->status,
            ], 409);
        }

        $group = Group::findOrFail($data['groupId']);

        // Guard: special groups must have an admin-set price for today
        if ($group->is_special && $group->special_price === null) {
            return response()->json([
                'message' => 'Special Odds are not available today. Check back later.',
            ], 422);
        }

        // Guard: inactive groups cannot be subscribed to
        if (! $group->is_active) {
            return response()->json([
                'message' => 'This package is currently unavailable.',
            ], 422);
        }

        // Guard: subscription deadline — block new subs after the cutoff time
        if ($group->isPastDeadline()) {
            $alternatives = Group::orderBy('price')
                ->get()
                ->filter(fn (Group $g) => $g->isPurchasable() && $g->id !== $group->id)
                ->map(fn (Group $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'planType' => $g->plan_type,
                    'effectivePrice' => $g->effectivePrice(),
                ])
                ->values();

            return response()->json([
                'message' => 'Subscriptions for "'.$group->name.'" have closed for today.',
                'alternatives' => $alternatives,
            ], 422);
        }

        // Use special_price for special groups; regular price otherwise
        $effectiveAmount = $group->effectivePrice();

        // Unique reference for this payment attempt. Jpesa may echo this value
        // back as the GET callback tid, so persist it before the STK request.
        // Relworx requires an 8-36 character reference. Keep the same bounded,
        // provider-neutral reference for every gateway.
        $reference = 'ALX-'.Str::lower(Str::random(24));

        $gatewayManager = app(PaymentGatewayManager::class);
        $provider = $gatewayManager->activeName();
        $quote = app(PaymentPricingService::class)->quote((float) $effectiveAmount, $provider);

        [$sub, $payment] = DB::transaction(function () use ($data, $group, $reference, $effectiveAmount, $provider, $quote) {
            $sub = Subscription::create([
                'user_id' => $data['userId'],
                'group_id' => $group->id,
                'plan_type' => $group->plan_type,
                'odds_type' => $group->odds_type,
                'payment_method' => $data['paymentMethod'],
                'phone' => $data['phone'],
                'amount' => $effectiveAmount,
                'status' => 'pending',
                'payment_reference' => $reference,
            ]);

            $payment = Payment::create([
                'subscription_id' => $sub->id,
                'user_id' => $data['userId'],
                'amount' => $effectiveAmount,
                'customer_charge_amount' => $quote['totalCharge'],
                'provider_fee_estimate' => $quote['providerFee'],
                'plan_type' => $group->plan_type,
                'payment_method' => $data['paymentMethod'],
                'phone' => $data['phone'],
                'status' => 'pending',
                'payment_reference' => $reference,
                'provider' => $provider,
            ]);

            return [$sub, $payment];
        });

        $pushResult = $gatewayManager->gateway($provider)->initiateCollection(
            $data['phone'],
            $quote['totalCharge'],
            $reference,
            $data['paymentMethod']
        );

        $providerRequestId = $pushResult['provider_request_id'] ?? null;
        $providerTxnId = $pushResult['transaction_id'] ?? null;
        if ($providerRequestId || $providerTxnId) {
            $payment->update([
                'provider_request_id' => $providerRequestId,
                'transaction_id' => $providerTxnId,
            ]);
        }

        if (! ($pushResult['success'] ?? false)) {
            if (! ($pushResult['pending'] ?? false)) {
                $sub->update(['status' => 'failed']);
                $category = isset($pushResult['raw']) ? 'provider' : 'system';
                app(PaymentFailureAlertService::class)->record(
                    $payment,
                    $category,
                    (string) ($pushResult['message'] ?? 'Payment initiation failed.')
                );
            }
        }

        return response()->json([
            'subscription' => $sub->fresh()->load(['payment', 'group']),
            'paymentReference' => $reference,
            'pushResult' => $pushResult,
            'quote' => $quote,
        ], 201);
    }

    /**
     * Public (throttled): poll the payment status of a subscription.
     * Used by the frontend spinner while awaiting STK push approval.
     */
    public function paymentStatus(int $id): JsonResponse
    {
        $sub = Subscription::with(['payment', 'group'])->findOrFail($id);

        if ($sub->status === 'pending') {
            $this->tryActivateFromProviderQuery($sub);
            $sub = $sub->fresh(['payment', 'group']);
        }

        // Auto-expire if needed
        if ($sub->isExpired()) {
            $sub->update(['status' => 'expired']);
            $sub->status = 'expired';
        }

        return response()->json([
            'status' => $sub->status,
            'subscription' => $this->formatSub($sub, $sub->status === 'active'),
        ]);
    }

    private function tryActivateFromProviderQuery(Subscription $sub): void
    {
        $payment = $sub->payment;
        if (! $payment) {
            return;
        }

        $result = app(PaymentGatewayManager::class)->forPayment($payment)->queryCollection($payment, $payment->payment_reference);
        if (($result['status'] ?? 'unknown') === 'failed') {
            app(PaymentSettlementService::class)->apply(
                $sub,
                false,
                $result['transaction_id'] ?? null,
                'provider-query',
                $this->providerSettlementData($result),
                'provider',
                (string) ($result['message'] ?? 'Payment provider reported a failed transaction.')
            );

            return;
        }

        if (! ($result['success'] ?? false)) {
            return;
        }

        if (! $this->providerResultMatchesPayment($payment, $result)) {
            Log::warning('Provider query returned mismatched payment details.', ['payment_id' => $payment->id]);
            return;
        }

        app(PaymentSettlementService::class)->apply(
            $sub,
            true,
            $result['transaction_id'] ?? $payment->transaction_id,
            'provider-query',
            $this->providerSettlementData($result)
        );
    }

    /** Normalise a Subscription model to a camelCase array for API responses. */
    private function formatSub(Subscription $sub, bool $includeBetslip = true): array
    {
        // Always fall back to the group's current betslip when the subscription
        // copy is empty — handles cases where the link was set after activation.
        $group = $sub->relationLoaded('group') ? $sub->group : null;
        $betslipLink = $sub->betslip_link ?: ($group?->betslip_link ?? '');
        $betslipCode = $sub->betslip_code ?: ($group?->betslip_code ?? '');

        return [
            'id' => $sub->id,
            'status' => $sub->status,
            'planType' => $sub->plan_type,
            'planName' => $group?->name ?? null,
            'oddsType' => $sub->odds_type,
            'paymentMethod' => $sub->payment_method,
            'paymentProvider' => $sub->payment?->provider ?? 'jpesa',
            'paymentReference' => $sub->payment_reference,
            'phone' => $sub->phone,
            'amount' => $sub->amount,
            'baseAmount' => (float) $sub->amount,
            'providerFee' => (float) ($sub->payment?->provider_fee_estimate ?? 0),
            'totalCharge' => (float) ($sub->payment?->customer_charge_amount ?? $sub->amount),
            'betslipLink' => $includeBetslip ? $betslipLink : '',
            'betslipCode' => $includeBetslip ? $betslipCode : '',
            'packagePhoto' => $includeBetslip ? ($group?->photo_url ?? '') : '',
            'startedAt' => $sub->started_at,
            'expiresAt' => $sub->expires_at,
            'createdAt' => $sub->created_at,
            'updatedAt' => $sub->updated_at,
            'group' => $group,
        ];
    }

    /** Admin: reject, revoke, or manually update a subscription */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,rejected,expired,pending'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_link' => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $sub = Subscription::with(['payment', 'group'])->findOrFail($id);

        if (($data['status'] ?? null) === 'active') {
            $now = now();
            $expiresAt = $now->copy()->addSeconds($sub->durationSeconds());

            // Betslip comes from the group record; admin override is still accepted
            $betslipLink = $data['betslip_link'] ?? ($sub->group?->betslip_link ?? '');
            $betslipCode = $data['betslip_code'] ?? ($sub->group?->betslip_code ?? '');

            $sub->update([
                'status' => 'active',
                'started_at' => $now,
                'expires_at' => $expiresAt,
                'betslip_link' => $betslipLink,
                'betslip_code' => $betslipCode,
            ]);

            if ($sub->payment) {
                $sub->payment->update([
                    'status' => 'confirmed',
                    'confirmed_at' => $sub->payment->confirmed_at ?: $now,
                ]);
            }

            $this->processAgentCommissionSafely($sub->fresh(['payment']), 'admin-activation');
        } elseif (($data['status'] ?? null) === 'rejected') {
            $sub->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'] ?? null,
            ]);
        } else {
            $sub->update(array_filter($data, fn ($v) => $v !== null));
        }

        return response()->json($sub->fresh()->load(['payment', 'group']));
    }

    /** Admin: extend a subscription's expiry */
    public function renew(int $id): JsonResponse
    {
        $sub = Subscription::findOrFail($id);

        $currentExpiry = $sub->expires_at ?? now();
        $newExpiry = $currentExpiry->addSeconds($sub->durationSeconds());

        $sub->update([
            'expires_at' => $newExpiry,
            'status' => 'active',
        ]);

        return response()->json($sub->fresh());
    }

    /** Admin: delete a subscription while retaining its immutable payment ledger row. */
    public function destroy(int $id): JsonResponse
    {
        $sub = Subscription::with(['payment'])->findOrFail($id);

        if ($sub->status === 'active') {
            return response()->json([
                'message' => 'Cannot delete an active subscription. Revoke it first.',
            ], 409);
        }

        DB::transaction(function () use ($sub) {
            $sub->delete();
        });

        return response()->json(['message' => 'Subscription deleted.']);
    }

    /**
     * Public (throttled): user submits their Airtel Money transaction ID when the
     * STK push failed or the payment was not auto-confirmed by the webhook.
     * The system queries the Jpesa API to verify the transaction is genuine
     * and successful, then activates the subscription if confirmed.
     *
     * Works the same in local and production — credentials from .env are used.
     */
    public function submitTransaction(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'transactionId' => ['nullable', 'string', 'min:4', 'max:80', 'regex:/^[A-Za-z0-9\-_.]+$/'],
        ]);

        $sub = Subscription::with(['payment', 'group'])->findOrFail($id);

        if (! in_array($sub->status, ['pending', 'failed'], true)) {
            return response()->json([
                'error' => 'This subscription is already '.$sub->status.'. No action needed.',
            ], 409);
        }

        $txnId = (string) ($data['transactionId'] ?? '');
        $payment = $sub->payment;
        if (! $payment) {
            return response()->json(['error' => 'Payment record is unavailable.', 'verified' => false], 422);
        }

        $provider = $payment->provider ?: 'jpesa';
        if ($provider === 'jpesa' && $txnId === '') {
            return response()->json(['error' => 'Transaction ID is required for JPesa verification.', 'verified' => false], 422);
        }

        $gateway = app(PaymentGatewayManager::class)->forPayment($payment);
        $manualReference = $provider === 'jpesa' ? $txnId : null;
        $result = $gateway->queryCollection($payment, $manualReference);

        if (! $result['success']) {
            Log::warning('submitTransaction: verification failed', [
                'subscription_id' => $sub->id,
                'txn_id' => $txnId,
                'reason' => $result['message'] ?? 'unknown',
            ]);

            return response()->json([
                'error' => $result['message'] ?? 'Transaction ID could not be verified. Please check and try again.',
                'verified' => false,
            ], 422);
        }

        if (! $this->providerResultMatchesPayment($payment, $result)) {
            return response()->json(['error' => 'Provider payment details did not match this subscription.', 'verified' => false], 422);
        }

        $sub = app(PaymentSettlementService::class)->apply(
            $sub,
            true,
            $result['transaction_id'] ?? $txnId,
            'user-submitted-transaction',
            $this->providerSettlementData($result)
        );

        return response()->json([
            'verified' => true,
            'message' => 'Payment verified! Your subscription is now active.',
            'subscription' => $this->formatSub($sub->fresh()->load(['payment', 'group']), true),
        ]);
    }

    private function processAgentCommissionSafely(Subscription $sub, string $source): void
    {
        if (! $sub->payment) {
            return;
        }

        try {
            app(AgentCommissionService::class)->process($sub->payment->fresh(), $source);
        } catch (\Throwable $e) {
            Log::error('Subscription activation: agent commission processing failed.', [
                'source' => $source,
                'subscription_id' => $sub->id,
                'payment_id' => $sub->payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function providerResultMatchesPayment(Payment $payment, array $result): bool
    {
        if (($result['reference_matches'] ?? true) !== true) {
            return false;
        }
        $currency = strtoupper((string) ($result['currency'] ?? ''));
        $expectedCurrency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $amount = $result['amount'] ?? null;
        $expectedAmount = (float) ($payment->customer_charge_amount ?: $payment->amount);

        return ($currency === '' || $currency === $expectedCurrency)
            && ($amount === null || abs((float) $amount - $expectedAmount) < 0.001);
    }

    private function providerSettlementData(array $result): array
    {
        $amount = isset($result['amount']) ? (float) $result['amount'] : null;
        $fee = isset($result['provider_fee_actual']) ? (float) $result['provider_fee_actual'] : null;

        return [
            'provider_request_id' => $result['provider_request_id'] ?? null,
            'provider_fee_actual' => $fee,
            'provider_net_amount' => $amount !== null && $fee !== null ? max(0, $amount - $fee) : null,
            'completed_at' => $result['completed_at'] ?? null,
        ];
    }

    /**
     * Auto-delete pending/failed subscriptions for groups whose booking deadline
     * has passed today, EXCEPT those submitted within 2 hours before the deadline.
     * Those near-deadline subs are retained for admin review (they likely paid).
     */
    private function cleanupDeadlineSubscriptions(): void
    {
        $groups = Group::whereNotNull('subscription_deadline')->get();

        foreach ($groups as $group) {
            $deadline = Group::deadlineAt($group->subscription_deadline);

            if (! $deadline || now()->lt($deadline)) {
                continue;
            }

            // Subs created within 2 hours before deadline are kept for admin review
            $cutoff = $deadline->copy()->subHours(2);

            Subscription::with(['payment'])
                ->where('group_id', $group->id)
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '<', $cutoff)
                ->each(function (Subscription $sub) {
                    if ($sub->payment && $sub->payment->status === 'pending') {
                        app(PaymentFailureAlertService::class)->record(
                            $sub->payment,
                            'provider',
                            'Payment remained pending past the package cleanup window without a provider confirmation.'
                        );
                    }
                    DB::transaction(function () use ($sub) {
                        $sub->delete();
                    });
                });
        }
    }
}
