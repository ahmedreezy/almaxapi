<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\MobileMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $rawBody = $request->getContent();

        // Jpesa calls this callback with query parameters, especially:
        //   ?tid=<provider transaction id>&status=approved|failed|closed
        // Some provider dashboards report this as a callback even when the
        // transport is POST, so process these query params before HMAC checks.
        // A later "closed" callback must never undo an earlier approval.
        $hasJpesaQuery = $request->query->has('tid')
            || $request->query->has('status')
            || $request->query->has('tx');

        if ($hasJpesaQuery) {
            $tid    = trim((string) $request->query('tid', ''));
            $status = $this->normaliseJpesaStatus((string) $request->query('status', ''));

            if ($tid === '' && $status === 'unknown') {
                return response()->json([
                    'ok'       => true,
                    'endpoint' => '/api/payments/webhook',
                    'message'  => 'Payment callback endpoint is reachable',
                ]);
            }

            return $this->processWebhookOutcome(
                reference: trim((string) $request->query('tx', $request->query('reference', ''))),
                status: $status,
                txnId: $tid !== '' ? $tid : null,
                source: 'jpesa-query'
            );
        }

        if ($request->isMethod('get')) {
            return response()->json([
                'ok'       => true,
                'endpoint' => '/api/payments/webhook',
                'message'  => 'Payment callback endpoint is reachable',
            ]);
        }

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
        if (empty($payload) && Str::startsWith(trim($rawBody), '<')) {
            $payload = $this->xmlPayloadToArray($rawBody);
        }

        $parsed = $mmService->parseWebhookPayload($payload);

        return $this->processWebhookOutcome(
            reference: $parsed['reference'] ?? '',
            status: $this->normaliseJpesaStatus($parsed['status'] ?? ''),
            txnId: $parsed['transaction_id'] ?? null,
            source: 'webhook'
        );
    }

    private function processWebhookOutcome(string $reference, string $status, ?string $txnId, string $source): JsonResponse
    {
        $reference = trim($reference);
        $txnId = $txnId !== null ? trim($txnId) : null;

        if ($status === 'closed') {
            Log::info('Payment webhook: closed status ignored', [
                'source'    => $source,
                'reference' => $reference,
                'txn_id'    => $txnId,
            ]);

            return response()->json(['message' => 'Closed status ignored']);
        }

        $sub = null;

        if ($reference !== '') {
            $sub = Subscription::with(['payment', 'group'])
                ->where('payment_reference', $reference)
                ->where('status', 'pending')
                ->first();
        }

        if (! $sub && $txnId !== null && $txnId !== '') {
            $sub = Subscription::with(['payment', 'group'])
                ->where('status', 'pending')
                ->whereHas('payment', function ($q) use ($txnId) {
                    $q->where('transaction_id', $txnId);
                })
                ->first();
        }

        // Some providers echo the merchant tx value as tid. Try tid as reference too.
        if (! $sub && $txnId !== null && $txnId !== '') {
            $sub = Subscription::with(['payment', 'group'])
                ->where('payment_reference', $txnId)
                ->where('status', 'pending')
                ->first();
        }

        if (! $sub && $reference === '' && ($txnId === null || $txnId === '')) {
            Log::warning('Payment webhook: missing payment identifier', [
                'source' => $source,
                'status' => $status,
            ]);

            return response()->json(['message' => 'Missing payment identifier']);
        }

        if (! $sub) {
            Log::info('Payment webhook: no pending subscription matched callback', [
                'source'    => $source,
                'reference' => $reference,
                'txn_id'    => $txnId,
                'status'    => $status,
            ]);

            return response()->json(['message' => 'No matching pending subscription']);
        }

        if ($status === 'approved') {
            $this->applyOutcome($sub, true, $txnId, $source);
            return response()->json(['message' => 'Processed']);
        }

        if ($status === 'failed') {
            $this->applyOutcome($sub, false, $txnId, $source);
            return response()->json(['message' => 'Processed']);
        }

        Log::info('Payment webhook: unknown status ignored', [
            'source'    => $source,
            'reference' => $reference,
            'txn_id'    => $txnId,
            'status'    => $status,
        ]);

        return response()->json(['message' => 'Unknown status ignored']);
    }

    private function normaliseJpesaStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'approved', 'approve', 'success', 'successful', 'completed', 'ok' => 'approved',
            'failed', 'failure', 'declined', 'rejected', 'cancelled', 'canceled', 'error' => 'failed',
            'closed', 'close' => 'closed',
            default => 'unknown',
        };
    }

    private function xmlPayloadToArray(string $rawBody): array
    {
        $xml = @simplexml_load_string($rawBody, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (! $xml) {
            return [];
        }

        return json_decode(json_encode($xml), true) ?: [];
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
                    'transaction_id'    => $txnId ?: $sub->payment->transaction_id,
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
                'transaction_id'    => $txnId ?: $sub->payment->transaction_id,
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
