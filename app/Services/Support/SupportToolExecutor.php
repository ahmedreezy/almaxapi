<?php

namespace App\Services\Support;

use App\Models\Group;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportToolAudit;
use App\Services\MobileMoneyService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class SupportToolExecutor
{
    public function execute(
        SupportConversation $conversation,
        SupportMessage $message,
        string $name,
        array $arguments
    ): array {
        try {
            $result = match ($name) {
                'get_available_packages' => $this->availablePackages(),
                'get_recent_payments' => $this->recentPayments($conversation),
                'check_payment_status' => $this->checkPayment($conversation, $arguments),
                'get_subscription_status' => $this->subscriptionStatus($conversation),
                'get_receipt' => $this->receipt($conversation, $arguments),
                default => ['ok' => false, 'error' => 'Unsupported tool.'],
            };
            $successful = (bool) ($result['ok'] ?? true);
        } catch (Throwable $e) {
            report($e);
            $result = ['ok' => false, 'error' => 'The requested account check could not be completed.'];
            $successful = false;
        }

        SupportToolAudit::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'tool_name' => $name,
            'arguments' => $this->redactArguments($arguments),
            'result' => $result,
            'successful' => $successful,
        ]);

        return $result;
    }

    private function availablePackages(): array
    {
        $packages = Group::orderBy('price')
            ->get()
            ->filter(fn (Group $group) => $group->isPubliclyVisible() && ! $group->isPastDeadline())
            ->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'plan_type' => $group->plan_type,
                'duration_days' => match ($group->plan_type) {
                    'monthly' => 30,
                    'weekly', 'special' => 7,
                    default => 1,
                },
                'odds_type' => $group->is_special && $group->special_odds
                    ? $group->special_odds
                    : $group->odds_type,
                'price' => $group->effectivePrice(),
                'currency' => 'UGX',
                'subscription_deadline' => $group->subscription_deadline,
            ])
            ->values();

        return ['ok' => true, 'packages' => $packages];
    }

    private function recentPayments(SupportConversation $conversation): array
    {
        $userId = $conversation->contact?->user_id;
        if (! $userId) {
            return $this->notLinked();
        }

        $payments = Payment::with('subscription.group')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Payment $payment) => $this->paymentPayload($payment))
            ->values();

        return ['ok' => true, 'payments' => $payments];
    }

    private function checkPayment(SupportConversation $conversation, array $arguments): array
    {
        $userId = $conversation->contact?->user_id;
        if (! $userId) {
            return $this->notLinked();
        }

        $reference = trim((string) ($arguments['payment_reference'] ?? ''));
        $query = Payment::with('subscription.group')->where('user_id', $userId);
        if ($reference !== '') {
            $query->where(function ($builder) use ($reference) {
                $builder->where('payment_reference', $reference)
                    ->orWhere('transaction_id', $reference)
                    ->orWhere('receipt_number', $reference);
            });
        }

        $payment = $query->orderByDesc('created_at')->first();
        if (! $payment) {
            return [
                'ok' => true,
                'found' => false,
                'guidance' => 'Do not state that funds were not deducted. Ask for the transaction reference so another automated check can be attempted.',
            ];
        }

        $payload = $this->paymentPayload($payment);
        if ($payment->status === 'pending'
            && $payment->transaction_id
            && $payment->transaction_id !== $payment->payment_reference) {
            $provider = (new MobileMoneyService)->queryTransaction($payment->transaction_id);
            $payload['provider_check'] = [
                'confirmed' => (bool) ($provider['success'] ?? false),
                'message' => (string) ($provider['message'] ?? 'No provider status was returned.'),
            ];
            if ($provider['success'] ?? false) {
                $payload['guidance'] = 'The provider reports success while Almax still shows pending. Do not request another payment. Explain the discrepancy and advise the customer to retry the automated status check later.';
            }
        }

        return ['ok' => true, 'found' => true, 'payment' => $payload];
    }

    private function subscriptionStatus(SupportConversation $conversation): array
    {
        $userId = $conversation->contact?->user_id;
        if (! $userId) {
            return $this->notLinked();
        }

        $subscriptions = Subscription::with('group')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'package' => $subscription->group?->name ?? $subscription->plan_type,
                'status' => $subscription->isExpired() ? 'expired' : $subscription->status,
                'started_at' => $subscription->started_at?->toIso8601String(),
                'expires_at' => $subscription->expires_at?->toIso8601String(),
            ])->values();

        return ['ok' => true, 'subscriptions' => $subscriptions];
    }

    private function receipt(SupportConversation $conversation, array $arguments): array
    {
        $userId = $conversation->contact?->user_id;
        if (! $userId) {
            return $this->notLinked();
        }

        $reference = trim((string) ($arguments['payment_reference'] ?? ''));
        $payment = Payment::with('subscription.group')
            ->where('user_id', $userId)
            ->where(function ($query) use ($reference) {
                $query->where('payment_reference', $reference)
                    ->orWhere('transaction_id', $reference)
                    ->orWhere('receipt_number', $reference);
            })
            ->first();

        if (! $payment) {
            return ['ok' => true, 'found' => false];
        }
        if (! in_array($payment->status, ['confirmed', 'success', 'successful', 'completed'], true)) {
            return ['ok' => true, 'found' => true, 'available' => false, 'status' => $payment->status];
        }

        if (! $payment->receipt_number) {
            $payment->forceFill(['receipt_number' => 'ALX-RCP-'.Str::upper(Str::random(12))])->save();
        }

        return [
            'ok' => true,
            'found' => true,
            'available' => true,
            'receipt_number' => $payment->receipt_number,
            'receipt_url' => URL::temporarySignedRoute(
                'support.receipt',
                now()->addHours(24),
                ['payment' => $payment->id]
            ),
        ];
    }

    private function paymentPayload(Payment $payment): array
    {
        $transaction = (string) ($payment->transaction_id ?? '');

        return [
            'payment_reference' => $payment->payment_reference,
            'receipt_number' => $payment->receipt_number,
            'amount' => (float) $payment->amount,
            'currency' => 'UGX',
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'transaction_id_masked' => $transaction === '' ? null : '****'.substr($transaction, -4),
            'package' => $payment->subscription?->group?->name ?? $payment->plan_type,
            'subscription_status' => $payment->subscription?->status,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    private function notLinked(): array
    {
        return [
            'ok' => false,
            'error' => 'account_not_linked',
            'guidance' => 'Explain that this WhatsApp number is not linked to an Almax account. Provide general guidance and ask the customer to sign in to the website chat for account-specific checks.',
        ];
    }

    private function redactArguments(array $arguments): array
    {
        foreach (['password', 'pin', 'otp'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $arguments[$key] = '[REDACTED]';
            }
        }

        return $arguments;
    }
}
