<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\MobileMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /** Admin: list all subscriptions with auto-expiry updates */
    public function index(): JsonResponse
    {
        // Auto-delete stale pending/failed subs whose booking deadline has passed
        $this->cleanupDeadlineSubscriptions();

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
            ->with(['group'])
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
            'userId'        => ['required', 'integer', 'exists:users,id'],
            'groupId'       => ['required', 'integer', 'exists:groups,id'],
            'paymentMethod' => ['required', 'string', 'in:mtn,airtel'],
            'phone'         => ['required', 'string', 'max:30'],
        ]);

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
            return response()->json([
                'message' => 'A payment request is already in progress for this package. Please approve the existing prompt or wait a moment before retrying.',
                'existingSubscriptionId' => $existingPending->id,
                'paymentReference' => $existingPending->payment_reference,
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
                ->filter(fn (Group $g) =>
                    $g->isPubliclyVisible()
                    && ! $g->isPastDeadline()
                    && $g->id !== $group->id
                )
                ->map(fn (Group $g) => [
                    'id'             => $g->id,
                    'name'           => $g->name,
                    'planType'       => $g->plan_type,
                    'effectivePrice' => $g->effectivePrice(),
                ])
                ->values();

            return response()->json([
                'message'      => 'Subscriptions for "' . $group->name . '" have closed for today.',
                'alternatives' => $alternatives,
            ], 422);
        }

        // Use special_price for special groups; regular price otherwise
        $effectiveAmount = $group->effectivePrice();

        // Unique reference for this payment attempt. Jpesa may echo this value
        // back as the GET callback tid, so persist it before the STK request.
        $reference = 'ALX-' . $group->id . '-' . $data['userId'] . '-' . time();

        [$sub, $payment] = DB::transaction(function () use ($data, $group, $reference, $effectiveAmount) {
            $sub = Subscription::create([
                'user_id'           => $data['userId'],
                'group_id'          => $group->id,
                'plan_type'         => $group->plan_type,
                'odds_type'         => $group->odds_type,
                'payment_method'    => $data['paymentMethod'],
                'phone'             => $data['phone'],
                'amount'            => $effectiveAmount,
                'status'            => 'pending',
                'payment_reference' => $reference,
            ]);

            $payment = Payment::create([
                'subscription_id'   => $sub->id,
                'user_id'           => $data['userId'],
                'amount'            => $effectiveAmount,
                'plan_type'         => $group->plan_type,
                'payment_method'    => $data['paymentMethod'],
                'phone'             => $data['phone'],
                'status'            => 'pending',
                'payment_reference' => $reference,
                'transaction_id'    => $reference,
            ]);

            return [$sub, $payment];
        });

        $mmService = new MobileMoneyService();
        $pushResult = $mmService->initiateSTKPush(
            $data['phone'],
            $effectiveAmount,
            $reference,
            $data['paymentMethod']
        );

        if (is_array($pushResult['raw'] ?? null)) {
            $providerTxnId = $pushResult['raw']['tid']
                ?? $pushResult['raw']['transaction_id']
                ?? $pushResult['raw']['txn_id']
                ?? null;

            if ($providerTxnId) {
                $payment->update(['transaction_id' => $providerTxnId]);
            }
        }

        return response()->json([
            'subscription'     => $sub->fresh()->load(['payment', 'group']),
            'paymentReference' => $reference,
            'pushResult'       => $pushResult,
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

            if ($sub->status === 'pending') {
                $activeSibling = Subscription::with(['payment', 'group'])
                    ->where('user_id', $sub->user_id)
                    ->where('group_id', $sub->group_id)
                    ->where('phone', $sub->phone)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->orderByDesc('started_at')
                    ->first();

                if ($activeSibling) {
                    return response()->json([
                        'status'       => 'active',
                        'subscription' => $this->formatSub($activeSibling, true),
                    ]);
                }
            }
        }

        // Auto-expire if needed
        if ($sub->isExpired()) {
            $sub->update(['status' => 'expired']);
            $sub->status = 'expired';
        }

        return response()->json([
            'status'       => $sub->status,
            'subscription' => $this->formatSub($sub, $sub->status === 'active'),
        ]);
    }

    private function tryActivateFromProviderQuery(Subscription $sub): void
    {
        $txnId = $sub->payment?->transaction_id;

        if (! $txnId || $txnId === $sub->payment_reference) {
            return;
        }

        $result = (new MobileMoneyService())->queryTransaction($txnId);
        if (! ($result['success'] ?? false)) {
            return;
        }

        $now         = now();
        $expiresAt   = $now->copy()->addSeconds($sub->durationSeconds());
        $group       = $sub->group;
        $betslipLink = $sub->betslip_link ?: ($group?->betslip_link ?? '');
        $betslipCode = $sub->betslip_code ?: ($group?->betslip_code ?? '');

        DB::transaction(function () use ($sub, $now, $expiresAt, $betslipLink, $betslipCode, $txnId) {
            $sub->update([
                'status'       => 'active',
                'started_at'   => $now,
                'expires_at'   => $expiresAt,
                'betslip_link' => $betslipLink,
                'betslip_code' => $betslipCode,
            ]);

            if ($sub->payment) {
                $sub->payment->update([
                    'status'         => 'confirmed',
                    'transaction_id' => $txnId,
                ]);
            }
        });

        \Illuminate\Support\Facades\Log::info('Subscription activated during payment-status polling', [
            'subscription_id' => $sub->id,
            'txn_id'          => $txnId,
        ]);
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
            'id'               => $sub->id,
            'status'           => $sub->status,
            'planType'         => $sub->plan_type,
            'planName'         => $group?->name ?? null,
            'oddsType'         => $sub->odds_type,
            'paymentMethod'    => $sub->payment_method,
            'paymentReference' => $sub->payment_reference,
            'phone'            => $sub->phone,
            'amount'           => $sub->amount,
            'betslipLink'      => $includeBetslip ? $betslipLink : '',
            'betslipCode'      => $includeBetslip ? $betslipCode : '',
            'startedAt'        => $sub->started_at,
            'expiresAt'        => $sub->expires_at,
            'createdAt'        => $sub->created_at,
            'updatedAt'        => $sub->updated_at,
            'group'            => $group,
        ];
    }

    /** Admin: reject, revoke, or manually update a subscription */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status'           => ['sometimes', 'string', 'in:active,rejected,expired,pending'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_link'     => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_code'     => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $sub = Subscription::with(['payment', 'group'])->findOrFail($id);

        if (($data['status'] ?? null) === 'active') {
            $now       = now();
            $expiresAt = $now->copy()->addSeconds($sub->durationSeconds());

            // Betslip comes from the group record; admin override is still accepted
            $betslipLink = $data['betslip_link'] ?? ($sub->group?->betslip_link ?? '');
            $betslipCode = $data['betslip_code'] ?? ($sub->group?->betslip_code ?? '');

            $sub->update([
                'status'       => 'active',
                'started_at'   => $now,
                'expires_at'   => $expiresAt,
                'betslip_link' => $betslipLink,
                'betslip_code' => $betslipCode,
            ]);

            if ($sub->payment) {
                $sub->payment->update(['status' => 'confirmed']);
            }
        } elseif (($data['status'] ?? null) === 'rejected') {
            $sub->update([
                'status'           => 'rejected',
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
        $newExpiry     = $currentExpiry->addSeconds($sub->durationSeconds());

        $sub->update([
            'expires_at' => $newExpiry,
            'status'     => 'active',
        ]);

        return response()->json($sub->fresh());
    }

    /** Admin: permanently delete a subscription and its associated payment record. */
    public function destroy(int $id): JsonResponse
    {
        $sub = Subscription::with(['payment'])->findOrFail($id);

        if ($sub->status === 'active') {
            return response()->json([
                'message' => 'Cannot delete an active subscription. Revoke it first.',
            ], 409);
        }

        DB::transaction(function () use ($sub) {
            $sub->payment()->delete();
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
            'transactionId' => ['required', 'string', 'min:4', 'max:80', 'regex:/^[A-Za-z0-9\-_.]+$/'],
        ]);

        $sub = Subscription::with(['payment', 'group'])->findOrFail($id);

        if (! in_array($sub->status, ['pending', 'failed'], true)) {
            return response()->json([
                'error' => 'This subscription is already ' . $sub->status . '. No action needed.',
            ], 409);
        }

        $txnId     = $data['transactionId'];
        $mmService = new MobileMoneyService();
        $result    = $mmService->queryTransaction($txnId);

        if (! $result['success']) {
            \Illuminate\Support\Facades\Log::warning('submitTransaction: verification failed', [
                'subscription_id' => $sub->id,
                'txn_id'          => $txnId,
                'reason'          => $result['message'] ?? 'unknown',
            ]);

            return response()->json([
                'error'    => $result['message'] ?? 'Transaction ID could not be verified. Please check and try again.',
                'verified' => false,
            ], 422);
        }

        // Valid — activate the subscription
        $now         = now();
        $expiresAt   = $now->copy()->addSeconds($sub->durationSeconds());
        $group       = $sub->group;
        $betslipLink = $sub->betslip_link ?: ($group?->betslip_link ?? '');
        $betslipCode = $sub->betslip_code ?: ($group?->betslip_code ?? '');

        $sub->update([
            'status'       => 'active',
            'started_at'   => $now,
            'expires_at'   => $expiresAt,
            'betslip_link' => $betslipLink,
            'betslip_code' => $betslipCode,
        ]);

        if ($sub->payment) {
            $sub->payment->update([
                'status'         => 'confirmed',
                'transaction_id' => $txnId,
            ]);
        }

        \Illuminate\Support\Facades\Log::info('Subscription activated via user-submitted transaction ID', [
            'subscription_id' => $sub->id,
            'txn_id'          => $txnId,
        ]);

        return response()->json([
            'verified'     => true,
            'message'      => 'Payment verified! Your subscription is now active.',
            'subscription' => $this->formatSub($sub->fresh()->load(['group']), true),
        ]);
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
            $deadlineToday = now()->startOfDay()->setTimeFromTimeString($group->subscription_deadline);

            // Skip groups whose deadline hasn't passed yet today
            if (now()->lt($deadlineToday)) {
                continue;
            }

            // Subs created within 2 hours before deadline are kept for admin review
            $cutoff = $deadlineToday->copy()->subHours(2);

            Subscription::with(['payment'])
                ->where('group_id', $group->id)
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '<', $cutoff)
                ->each(function (Subscription $sub) {
                    DB::transaction(function () use ($sub) {
                        $sub->payment()->delete();
                        $sub->delete();
                    });
                });
        }
    }
}
