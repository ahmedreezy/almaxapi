<?php

namespace App\Services;

use App\Mail\PaymentFailureAlertMail;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentFailureAlertService
{
    private const ALERTABLE_CATEGORIES = ['system', 'provider'];

    public function record(Payment $payment, string $category, string $reason): void
    {
        $category = strtolower(trim($category));
        $reason = trim($reason) ?: 'No failure reason was supplied.';

        $payment->update([
            'status' => 'failed',
            'failure_category' => $category,
            'failure_reason' => mb_substr($reason, 0, 2000),
            'failed_at' => $payment->failed_at ?: now(),
        ]);

        if (! in_array($category, self::ALERTABLE_CATEGORIES, true)) {
            return;
        }

        try {
            $this->sendPendingBatchIfReady();
        } catch (\Throwable $e) {
            Log::error('Payment failure alert could not be sent; failures remain queued.', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendPendingBatchIfReady(): bool
    {
        if (! config('services.payment_failure_alert.enabled', true)) {
            return false;
        }

        $threshold = max(1, (int) config('services.payment_failure_alert.threshold', 5));
        $recipients = $this->recipients();
        if ($recipients === []) {
            Log::warning('Payment failure alert skipped because no recipients are configured.');
            return false;
        }

        return (bool) Cache::lock('almax-payment-failure-alert', 30)->get(function () use ($threshold, $recipients) {
            $payments = Payment::query()
                ->where('status', 'failed')
                ->whereIn('failure_category', self::ALERTABLE_CATEGORIES)
                ->whereNull('failure_alerted_at')
                ->orderBy('failed_at')
                ->orderBy('id')
                ->limit($threshold)
                ->get();

            if ($payments->count() < $threshold) {
                return false;
            }

            $this->mailer()->to($recipients)->send(new PaymentFailureAlertMail(
                "Almax alert: {$threshold} system/payment transaction failures",
                $this->alertBody($payments)
            ));

            Payment::whereKey($payments->pluck('id'))
                ->whereNull('failure_alerted_at')
                ->update(['failure_alerted_at' => now()]);

            Log::warning('Payment failure alert email sent.', [
                'payment_ids' => $payments->pluck('id')->all(),
                'recipients' => $recipients,
            ]);

            return true;
        });
    }

    public function sendTestEmail(): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            throw new \RuntimeException('No payment failure alert recipients are configured.');
        }

        $this->mailer()->to($recipients)->send(new PaymentFailureAlertMail(
            'Almax payment alert email test',
            'test almax email system.'
        ));
    }

    private function mailer()
    {
        $mailer = (string) config('mail.default', 'log');
        if (app()->environment('production') && in_array($mailer, ['log', 'array'], true)) {
            $mailer = 'sendmail';
        }

        return Mail::mailer($mailer);
    }

    private function recipients(): array
    {
        return collect(config('services.payment_failure_alert.recipients', []))
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    private function alertBody($payments): string
    {
        $lines = [
            'Almax detected five payment attempts that failed because of a system or payment-provider problem.',
            '',
            'Please review these transactions:',
        ];

        foreach ($payments as $payment) {
            $phone = (string) $payment->phone;
            $maskedPhone = strlen($phone) > 4 ? str_repeat('*', strlen($phone) - 4).substr($phone, -4) : $phone;
            $lines[] = sprintf(
                '#%d | %s UGX | %s | %s | %s | %s',
                $payment->id,
                number_format((float) $payment->amount),
                strtoupper((string) $payment->payment_method),
                $payment->failure_category,
                $maskedPhone,
                $payment->failure_reason
            );
        }

        $lines[] = '';
        $lines[] = 'This alert is sent once per batch of five qualifying failures. Customer declines and manual failures are excluded.';

        return implode("\n", $lines);
    }
}
