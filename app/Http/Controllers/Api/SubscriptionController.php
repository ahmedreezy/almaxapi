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

                $data = $sub->toArray();

                if ($sub->status !== 'active') {
                    $data['betslip_link'] = '';
                    $data['betslip_code'] = '';
                }

                return $data;
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

        // Unique reference for this payment attempt
        $reference = 'ALX-' . $group->id . '-' . $data['userId'] . '-' . time();

        // Initiate STK push before creating the subscription record
        $mmService = new MobileMoneyService();
        $pushResult = $mmService->initiateSTKPush(
            $data['phone'],
            $effectiveAmount,
            $reference,
            $data['paymentMethod']
        );

        $providerTxnId = null;
        if (is_array($pushResult['raw'] ?? null)) {
            $providerTxnId = $pushResult['raw']['tid']
                ?? $pushResult['raw']['transaction_id']
                ?? $pushResult['raw']['txn_id']
                ?? null;
        }

        return DB::transaction(function () use ($data, $group, $reference, $pushResult, $effectiveAmount, $providerTxnId) {
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

            Payment::create([
                'subscription_id'   => $sub->id,
                'user_id'           => $data['userId'],
                'amount'            => $effectiveAmount,
                'plan_type'         => $group->plan_type,
                'payment_method'    => $data['paymentMethod'],
                'phone'             => $data['phone'],
                'status'            => 'pending',
                'payment_reference' => $reference,
                'transaction_id'    => $providerTxnId,
            ]);

            return response()->json([
                'subscription'     => $sub->load(['payment', 'group']),
                'paymentReference' => $reference,
                'pushResult'       => $pushResult,
            ], 201);
        });
    }

    /**
     * Public (throttled): poll the payment status of a subscription.
     * Used by the frontend spinner while awaiting STK push approval.
     */
    public function paymentStatus(int $id): JsonResponse
    {
        $sub = Subscription::findOrFail($id);

        // Auto-expire if needed
        if ($sub->isExpired()) {
            $sub->update(['status' => 'expired']);
            $sub->status = 'expired';
        }

        $data = $sub->toArray();
        if ($sub->status !== 'active') {
            $data['betslip_link'] = '';
            $data['betslip_code'] = '';
        }

        return response()->json(['status' => $sub->status, 'subscription' => $data]);
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
}
