<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\MobileMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET  /api/payments        — admin: all payments with user + subscription data
 * GET  /api/payments/:id    — admin: single payment
 * POST /api/payments/webhook — public (HMAC-verified): payment provider callback
 */
class PaymentController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]);

        $from = $data['from'] ?? null;
        $to   = $data['to']   ?? null;

        $base = Payment::query();
        if ($from) {
            $base->where('created_at', '>=', $from);
        }
        if ($to) {
            $base->where('created_at', '<=', $to);
        }

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as total_payments, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $byMethod = (clone $base)
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();

        $byPlan = (clone $base)
            ->selectRaw('plan_type, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('plan_type')
            ->orderBy('plan_type')
            ->get();

        $recent = (clone $base)
            ->with(['user', 'subscription.group'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'range' => ['from' => $from, 'to' => $to],
            'summary' => [
                'totalPayments' => (int) ($summary?->total_payments ?? 0),
                'totalAmount'   => (float) ($summary?->total_amount ?? 0),
            ],
            'byStatus' => $byStatus,
            'byMethod' => $byMethod,
            'byPlan'   => $byPlan,
            'recent'   => $recent,
        ]);
    }

    public function index(): JsonResponse
    {
        $payments = Payment::with(['user', 'subscription.group'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($payments);
    }

    public function show(int $id): JsonResponse
    {
        $payment = Payment::with(['user', 'subscription.user', 'subscription.group'])
            ->findOrFail($id);

        return response()->json($payment);
    }

    /**
     * Payment provider webhook.
     *
     * The provider POSTs a JSON payload after the customer approves (or declines)
     * the STK push. We verify the HMAC signature, then activate or fail the
     * matching subscription.
     *
     * No auth middleware — signature validation is the security control.
     */
    public function webhook(Request $request): JsonResponse
    {
        $rawBody  = $request->getContent();
        $signature = $request->header('X-Signature', $request->header('X-Webhook-Signature', ''));

        $mmService = new MobileMoneyService();

        if (! $mmService->validateWebhookSignature($rawBody, $signature)) {
            Log::warning('Payment webhook: invalid signature', [
                'ip'        => $request->ip(),
                'signature' => substr($signature, 0, 20),
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        if (empty($payload) && $request->isJson()) {
            $payload = $request->json()->all();
        }
        $parsed  = $mmService->parseWebhookPayload($payload);

        $reference = $parsed['reference'] ?? '';
        $status    = $parsed['status']    ?? '';
        $txnId     = $parsed['transaction_id'] ?? null;

        if (empty($reference)) {
            return response()->json(['error' => 'Missing reference'], 422);
        }

        // Find the pending subscription by payment reference
        $sub = Subscription::with(['payment', 'group'])
            ->where('payment_reference', $reference)
            ->where('status', 'pending')
            ->first();

        if (! $sub) {
            Log::info('Payment webhook: no pending subscription for reference', ['reference' => $reference]);
            // Return 200 so the provider does not keep retrying
            return response()->json(['message' => 'No matching pending subscription']);
        }

        $this->applyOutcome($sub, $status === 'success', $txnId, 'webhook');

        return response()->json(['message' => 'Processed']);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference'     => ['sometimes', 'string'],
            'subscriptionId'=> ['sometimes', 'integer'],
            'paymentId'     => ['sometimes', 'integer'],
            'status'        => ['required', 'string', 'in:success,failed'],
            'transactionId' => ['sometimes', 'nullable', 'string', 'max:120'],
            'force'         => ['sometimes', 'boolean'],
        ]);

        $sub = null;

        if (! empty($data['reference'])) {
            $sub = Subscription::with(['payment', 'group'])
                ->where('payment_reference', $data['reference'])
                ->first();
        } elseif (! empty($data['subscriptionId'])) {
            $sub = Subscription::with(['payment', 'group'])
                ->find($data['subscriptionId']);
        } elseif (! empty($data['paymentId'])) {
            $payment = Payment::with(['subscription.group', 'subscription.payment'])
                ->find($data['paymentId']);
            $sub = $payment?->subscription;
        }

        if (! $sub) {
            return response()->json(['error' => 'Subscription not found for reconcile request.'], 404);
        }

        $force = (bool) ($data['force'] ?? false);
        if ($sub->status !== 'pending' && ! $force) {
            return response()->json([
                'error'   => 'Subscription is not pending. Set force=true to override.',
                'current' => $sub->status,
            ], 409);
        }

        $isSuccess = $data['status'] === 'success';
        $this->applyOutcome($sub, $isSuccess, $data['transactionId'] ?? null, 'manual-reconcile');

        return response()->json([
            'message'      => 'Reconcile applied.',
            'subscription' => $sub->fresh()->load(['payment', 'group']),
        ]);
    }

    private function applyOutcome(Subscription $sub, bool $isSuccess, ?string $txnId, string $source): void
    {
        if ($isSuccess) {
            $now       = now();
            $expiresAt = $now->copy()->addSeconds($sub->durationSeconds());

            $betslipLink = $sub->group?->betslip_link ?? '';
            $betslipCode = $sub->group?->betslip_code ?? '';

            $sub->update([
                'status'       => 'active',
                'started_at'   => $now,
                'expires_at'   => $expiresAt,
                'betslip_link' => $betslipLink,
                'betslip_code' => $betslipCode,
            ]);

            if ($sub->payment) {
                $sub->payment->update([
                    'status'            => 'confirmed',
                    'transaction_id'    => $txnId,
                    'payment_reference' => $sub->payment_reference,
                ]);
            }

            Log::info('Payment outcome: subscription activated', [
                'source'          => $source,
                'subscription_id' => $sub->id,
                'reference'       => $sub->payment_reference,
                'txn_id'          => $txnId,
            ]);

            return;
        }

        $sub->update(['status' => 'failed']);

        if ($sub->payment) {
            $sub->payment->update([
                'status'            => 'failed',
                'transaction_id'    => $txnId,
                'payment_reference' => $sub->payment_reference,
            ]);
        }

        Log::info('Payment outcome: subscription failed', [
            'source'          => $source,
            'subscription_id' => $sub->id,
            'reference'       => $sub->payment_reference,
            'txn_id'          => $txnId,
        ]);
    }
}
