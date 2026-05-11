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

        $group = Group::findOrFail($data['groupId']);

        // Unique reference for this payment attempt
        $reference = 'ALX-' . $group->id . '-' . $data['userId'] . '-' . time();

        // Initiate STK push before creating the subscription record
        $mmService = new MobileMoneyService();
        $pushResult = $mmService->initiateSTKPush(
            $data['phone'],
            $group->price,
            $reference,
            $data['paymentMethod']
        );

        return DB::transaction(function () use ($data, $group, $reference, $pushResult) {
            $sub = Subscription::create([
                'user_id'           => $data['userId'],
                'group_id'          => $group->id,
                'plan_type'         => $group->plan_type,
                'odds_type'         => $group->odds_type,
                'payment_method'    => $data['paymentMethod'],
                'phone'             => $data['phone'],
                'amount'            => $group->price,
                'status'            => 'pending',
                'payment_reference' => $reference,
            ]);

            Payment::create([
                'subscription_id'   => $sub->id,
                'user_id'           => $data['userId'],
                'amount'            => $group->price,
                'plan_type'         => $group->plan_type,
                'payment_method'    => $data['paymentMethod'],
                'phone'             => $data['phone'],
                'status'            => 'pending',
                'payment_reference' => $reference,
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
