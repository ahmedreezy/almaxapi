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

        $payload = $request->json()->all();
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

        if ($status === 'success' || $status === 'successful' || $status === 'completed') {
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
                    'status'         => 'confirmed',
                    'transaction_id' => $txnId,
                ]);
            }

            Log::info('Payment webhook: subscription activated', [
                'subscription_id' => $sub->id,
                'reference'       => $reference,
                'txn_id'          => $txnId,
            ]);
        } else {
            // Payment failed / declined
            $sub->update(['status' => 'failed']);

            if ($sub->payment) {
                $sub->payment->update([
                    'status'         => 'failed',
                    'transaction_id' => $txnId,
                ]);
            }

            Log::info('Payment webhook: subscription payment failed', [
                'subscription_id' => $sub->id,
                'reference'       => $reference,
                'raw_status'      => $status,
            ]);
        }

        return response()->json(['message' => 'Processed']);
    }
}
