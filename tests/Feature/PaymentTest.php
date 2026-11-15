<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for /api/payments — admin read only.
 */
class PaymentTest extends TestCase
{
    private function seedPayment(): Payment
    {
        $user = User::create([
            'username'      => 'PayUser',
            'phone'         => '0720000001',
            'password_hash' => Hash::make('pass'),
        ]);
        $sub = Subscription::create([
            'user_id'        => $user->id,
            'odds_type'      => '2',
            'plan_type'      => 'daily',
            'payment_method' => 'mtn',
            'phone'          => $user->phone,
            'amount'         => 100,
            'status'         => 'pending',
        ]);
        return Payment::create([
            'subscription_id' => $sub->id,
            'user_id'         => $user->id,
            'amount'          => 100,
            'plan_type'       => 'daily',
            'payment_method'  => 'mtn',
            'phone'           => $user->phone,
            'status'          => 'pending',
        ]);
    }

    public function test_admin_can_list_payments(): void
    {
        $ctx = $this->createAdmin();
        $this->seedPayment();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments')
            ->assertStatus(200)
            ->assertJsonPath('per_page', 10)
            ->assertJsonStructure(['data' => [['id', 'amount', 'status']], 'current_page', 'last_page', 'total']);
    }

    public function test_admin_payment_listing_is_paginated_and_filterable(): void
    {
        $ctx = $this->createAdmin();
        for ($i = 0; $i < 12; $i++) {
            $user = User::create([
                'username' => "Ledger User {$i}",
                'phone' => '07210'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'password_hash' => Hash::make('pass'),
            ]);
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'odds_type' => '2',
                'plan_type' => $i % 2 ? 'weekly' : 'daily',
                'payment_method' => 'mtn',
                'phone' => $user->phone,
                'amount' => 5000 + $i,
                'status' => $i % 3 === 0 ? 'pending' : 'active',
            ]);
            Payment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount' => 5000 + $i,
                'plan_type' => $subscription->plan_type,
                'payment_method' => 'mtn',
                'phone' => $user->phone,
                'status' => $i % 3 === 0 ? 'pending' : 'confirmed',
                'payment_reference' => "LEDGER-{$i}",
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments?per_page=10&page=1')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 2);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments?status=pending&search=Ledger%20User')
            ->assertOk()
            ->assertJsonPath('total', 4);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments?per_page=15')
            ->assertStatus(422);
    }

    public function test_listing_payments_requires_admin(): void
    {
        $this->getJson('/api/payments')->assertStatus(401);
    }

    public function test_user_token_cannot_list_payments(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments')
            ->assertStatus(403);
    }

    public function test_admin_can_view_single_payment(): void
    {
        $ctx     = $this->createAdmin();
        $payment = $this->seedPayment();

        $this->withHeaders($ctx['headers'])
            ->getJson("/api/payments/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $payment->id);
    }

    public function test_single_payment_returns_404_for_unknown(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments/99999')
            ->assertStatus(404);
    }

    public function test_payment_report_separates_attempt_date_from_confirmation_date(): void
    {
        Config::set('app.timezone', 'Africa/Kampala');
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Africa/Kampala'));

        try {
            $ctx = $this->createAdmin();
            $payment = $this->seedPayment();
            $payment->forceFill([
                'status' => 'confirmed',
                'transaction_id' => 'TX-REPORT-TODAY',
                'created_at' => now()->subDay(),
                'confirmed_at' => now(),
            ])->save();

            $this->withHeaders($ctx['headers'])
                ->getJson('/api/payments/report?from=2026-10-22&to=2026-10-22')
                ->assertStatus(200)
                ->assertJsonPath('summary.totalPayments', 0)
                ->assertJsonPath('summary.totalAmount', 0)
                ->assertJsonPath('summary.confirmedPayments', 1)
                ->assertJsonPath('summary.confirmedAmount', 100)
                ->assertJsonPath('period_semantics.attempts', 'created_at')
                ->assertJsonPath('period_semantics.revenue', 'confirmed_at');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_approved_webhook_sends_agent_commission_once(): void
    {
        Config::set('services.mobile_money.api_url', 'https://my.jpesa.com/api/');
        Config::set('services.mobile_money.api_key', 'source-key');
        Config::set('services.mobile_money.callback_url', 'https://example.test/api/payments/webhook');
        Config::set('services.mobile_money.agent_commission', [
            'enabled'          => true,
            'ratio'            => 0.2,
            'recipient_type'   => 'business',
            'recipient_email'  => 'prof.markdemo@gmail.com',
            'recipient_mobile' => '0704045918',
            'transfer_action'  => 'debit',
            'transfer_pt'      => 'gwallet',
            'currency'         => 'UGX',
        ]);

        Http::fake(fn () => Http::response(['status' => 'success', 'tid' => 'COM-123'], 200));

        $user = User::create([
            'username'      => 'CommissionUser',
            'phone'         => '0720000100',
            'password_hash' => Hash::make('pass'),
        ]);
        $reference = 'ALX-COMMISSION-TEST';
        $sub = Subscription::create([
            'user_id'           => $user->id,
            'odds_type'         => '2',
            'plan_type'         => 'daily',
            'payment_method'    => 'mtn',
            'phone'             => $user->phone,
            'amount'            => 5000,
            'status'            => 'pending',
            'payment_reference' => $reference,
        ]);
        $payment = Payment::create([
            'subscription_id'   => $sub->id,
            'user_id'           => $user->id,
            'amount'            => 5000,
            'plan_type'         => 'daily',
            'payment_method'    => 'mtn',
            'phone'             => $user->phone,
            'status'            => 'pending',
            'payment_reference' => $reference,
            'transaction_id'    => $reference,
        ]);

        $this->getJson("/api/payments/webhook?tx={$reference}&tid=JP-123&status=approved")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Processed');

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('sent', $payment->agent_commission_status);
        $this->assertEquals(1000, $payment->agent_commission_amount);
        $this->assertEquals(0.2, $payment->agent_commission_ratio);
        $this->assertSame('COM-123', $payment->agent_commission_transaction_id);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->url() === 'https://my.jpesa.com/api/'
                && str_contains($body, '<cmd>account</cmd>')
                && str_contains($body, '<action>debit</action>')
                && str_contains($body, '<pt>gwallet</pt>')
                && str_contains($body, '<business>prof.markdemo@gmail.com</business>')
                && str_contains($body, '<cur>UGX</cur>')
                && str_contains($body, '<amount>1000</amount>')
                && str_contains($body, '<callback>https://example.test/api/payments/webhook</callback>');
        });

        $this->getJson("/api/payments/webhook?tx={$reference}&tid=JP-123&status=approved")
            ->assertStatus(200);

        Http::assertSentCount(1);
    }

    public function test_serialized_jpesa_error_marks_agent_commission_failed(): void
    {
        Config::set('services.mobile_money.api_url', 'https://my.jpesa.com/api/');
        Config::set('services.mobile_money.api_key', 'source-key');
        Config::set('services.mobile_money.callback_url', 'https://example.test/api/payments/webhook');
        Config::set('services.mobile_money.agent_commission', [
            'enabled'          => true,
            'ratio'            => 0.1,
            'recipient_type'   => 'business',
            'recipient_email'  => 'prof.markdemo@gmail.com',
            'recipient_mobile' => '0704045918',
            'transfer_action'  => 'debit',
            'transfer_pt'      => 'gwallet',
            'currency'         => 'UGX',
        ]);

        Http::fake(fn () => Http::response('a:3:{s:3:"msg";s:88:"[[E000115]] Your network 188.138.39.184 is NOT permitted to this API service or resource";s:10:"api_status";s:5:"error";s:6:"log_id";s:6:"428009";}', 200));

        $user = User::create([
            'username'      => 'CommissionErrorUser',
            'phone'         => '0720000101',
            'password_hash' => Hash::make('pass'),
        ]);
        $reference = 'ALX-COMMISSION-ERROR';
        $sub = Subscription::create([
            'user_id'           => $user->id,
            'odds_type'         => '2',
            'plan_type'         => 'daily',
            'payment_method'    => 'mtn',
            'phone'             => $user->phone,
            'amount'            => 14000,
            'status'            => 'pending',
            'payment_reference' => $reference,
        ]);
        $payment = Payment::create([
            'subscription_id'   => $sub->id,
            'user_id'           => $user->id,
            'amount'            => 14000,
            'plan_type'         => 'daily',
            'payment_method'    => 'mtn',
            'phone'             => $user->phone,
            'status'            => 'pending',
            'payment_reference' => $reference,
            'transaction_id'    => $reference,
        ]);

        $this->getJson("/api/payments/webhook?tx={$reference}&tid=JP-ERR&status=approved")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Processed');

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('failed', $payment->agent_commission_status);
        $this->assertEquals(1400, $payment->agent_commission_amount);
        $this->assertNull($payment->agent_commission_transaction_id);
        $this->assertStringContainsString('NOT permitted', $payment->agent_commission_error);
    }

    public function test_late_approved_webhook_recovers_failed_subscription_and_issues_receipt(): void
    {
        $user = User::create([
            'username' => 'LateApprovalUser',
            'phone' => '0720000102',
            'password_hash' => Hash::make('pass'),
        ]);
        $reference = 'ALX-LATE-APPROVAL';
        $sub = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 5000,
            'status' => 'failed',
            'payment_reference' => $reference,
        ]);
        $payment = Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 5000,
            'plan_type' => 'daily',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'status' => 'failed',
            'payment_reference' => $reference,
        ]);

        $this->getJson("/api/payments/webhook?tx={$reference}&tid=JP-LATE-123&status=approved")
            ->assertOk()
            ->assertJsonPath('message', 'Processed');

        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id, 'status' => 'active']);
        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('JP-LATE-123', $payment->transaction_id);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertNotEmpty($payment->receipt_number);
    }
}
