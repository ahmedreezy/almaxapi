<?php

namespace Tests\Feature;

use App\Mail\PaymentFailureAlertMail;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentFailureAlertService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentFailureAlertTest extends TestCase
{
    public function test_five_system_or_provider_failures_send_one_email_to_both_recipients(): void
    {
        Mail::fake();
        Config::set('services.payment_failure_alert.enabled', true);
        Config::set('services.payment_failure_alert.threshold', 5);
        Config::set('services.payment_failure_alert.recipients', [
            'ahmedkasolexd@gmail.com',
            'prof.markdemo@gmail.com',
        ]);

        $service = app(PaymentFailureAlertService::class);
        foreach (range(1, 5) as $index) {
            $service->record($this->makePayment($index), $index % 2 ? 'system' : 'provider', 'Gateway unavailable');
        }

        Mail::assertSent(PaymentFailureAlertMail::class, function (PaymentFailureAlertMail $mail) {
            return $mail->hasTo('ahmedkasolexd@gmail.com')
                && $mail->hasTo('prof.markdemo@gmail.com');
        });
        $this->assertSame(5, Payment::whereNotNull('failure_alerted_at')->count());
    }

    public function test_customer_and_manual_failures_do_not_trigger_system_alert(): void
    {
        Mail::fake();
        Config::set('services.payment_failure_alert.threshold', 5);

        $service = app(PaymentFailureAlertService::class);
        foreach (range(1, 5) as $index) {
            $service->record($this->makePayment($index), $index % 2 ? 'customer' : 'manual', 'Customer declined');
        }

        Mail::assertNothingSent();
        $this->assertSame(0, Payment::whereNotNull('failure_alerted_at')->count());
    }

    public function test_test_email_command_uses_the_configured_recipients(): void
    {
        Mail::fake();
        Config::set('mail.default', 'smtp');
        Config::set('services.payment_failure_alert.recipients', [
            'ahmedkasolexd@gmail.com',
            'prof.markdemo@gmail.com',
        ]);

        $this->artisan('payments:test-alert-email')->assertSuccessful();
        Mail::assertSent(PaymentFailureAlertMail::class, function (PaymentFailureAlertMail $mail) {
            return $mail->bodyText === 'test almax email system.'
                && $mail->hasTo('ahmedkasolexd@gmail.com')
                && $mail->hasTo('prof.markdemo@gmail.com')
                && $mail->hasFrom('alerts@almaxpredictions.com', 'Almax Payment Alerts');
        });
    }

    private function makePayment(int $index): Payment
    {
        $user = User::create([
            'username' => "Failure User {$index}",
            'phone' => '07000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make('password123'),
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_type' => 'daily',
            'odds_type' => '2',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'amount' => 1000,
            'status' => 'pending',
        ]);

        return Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 1000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'pending',
        ]);
    }
}
