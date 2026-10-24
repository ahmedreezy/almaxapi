<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeveloperAnalyticsTest extends TestCase
{
    private function developerHeaders(): array
    {
        $dev = AdminUser::create([
            'username' => 'almaxdev-test',
            'role' => 'developer',
            'password_hash' => Hash::make('DevPassword@2026'),
        ]);

        $token = $dev->createToken('dev-token', ['role:developer'])->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_today_revenue_uses_confirmation_time_and_exposes_auditable_attempt_totals(): void
    {
        Config::set('app.timezone', 'Africa/Kampala');
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Africa/Kampala'));

        try {
            $user = User::create([
                'username' => 'Revenue Audit Member',
                'phone' => '0700777010',
                'password_hash' => Hash::make('password123'),
            ]);
            $sub = Subscription::create([
                'user_id' => $user->id,
                'odds_type' => '2',
                'plan_type' => 'daily',
                'payment_method' => 'airtel',
                'phone' => $user->phone,
                'amount' => 1000,
                'status' => 'active',
            ]);

            $confirmed = Payment::create([
                'subscription_id' => $sub->id,
                'user_id' => $user->id,
                'amount' => 1000,
                'plan_type' => 'daily',
                'payment_method' => 'airtel',
                'phone' => $user->phone,
                'status' => 'confirmed',
                'transaction_id' => 'TX-CONFIRMED-TODAY',
                'confirmed_at' => now(),
            ]);
            $confirmed->forceFill(['created_at' => now()->subDay()])->save();
            $failed = Payment::create([
                'subscription_id' => $sub->id,
                'user_id' => $user->id,
                'amount' => 5000,
                'plan_type' => 'daily',
                'payment_method' => 'airtel',
                'phone' => $user->phone,
                'status' => 'failed',
                'failed_at' => now(),
                'failure_category' => 'provider',
            ]);
            $failed->forceFill(['created_at' => now()])->save();

            $response = $this->withHeaders($this->developerHeaders())
                ->getJson('/api/analytics/developer')
                ->assertStatus(200);

            $response->assertJsonPath('finance.revenue_today', 1000)
                ->assertJsonPath('finance.today.confirmed_count', 1)
                ->assertJsonPath('finance.today.confirmed_amount', 1000)
                ->assertJsonPath('finance.today.attempted_count', 1)
                ->assertJsonPath('finance.today.attempted_amount', 5000)
                ->assertJsonPath('finance.today.failed_count', 1)
                ->assertJsonPath('finance.audit.status_count_difference', 0)
                ->assertJsonPath('finance.audit.status_amount_difference', 0)
                ->assertJsonPath('finance.audit.confirmed_missing_timestamp_count', 0)
                ->assertJsonCount(2, 'finance.recent_payments');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_commission_analytics_reports_current_rate_and_preserves_historical_amounts(): void
    {
        Config::set('services.mobile_money.agent_commission.enabled', true);
        Config::set('services.mobile_money.agent_commission.ratio', 0.2);

        $user = User::create([
            'username' => 'Commission Member',
            'phone' => '0700777001',
            'password_hash' => Hash::make('password123'),
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'amount' => 10000,
            'status' => 'active',
        ]);

        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 10000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'agent_commission_amount' => 1000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'sent',
        ]);

        // Historical confirmed payment from before commission tracking existed.
        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 520000,
            'plan_type' => 'weekly',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
        ]);

        // Confirmed payment whose commission transfer failed: keep it visible so it can be retried.
        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 20000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'agent_commission_amount' => 2000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'failed',
        ]);

        // Failed payment rows never contribute to commission totals.
        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 30000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'failed',
            'agent_commission_amount' => 3000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'sent',
        ]);

        $response = $this->withHeaders($this->developerHeaders())
            ->getJson('/api/analytics/developer')
            ->assertStatus(200);

        $response->assertJsonPath('finance.total_revenue', 550000)
            ->assertJsonPath('finance.attempted_count', 4)
            ->assertJsonPath('finance.attempted_amount', 580000)
            ->assertJsonPath('finance.reconciliation_difference', 0)
            ->assertJsonPath('commission.ratio', 0.2)
            ->assertJsonPath('commission.total_earned', 3000)
            ->assertJsonPath('commission.total_paid', 1000)
            ->assertJsonPath('commission.outstanding', 2000)
            ->assertJsonPath('commission.tracked_payment_count', 2)
            ->assertJsonPath('commission.untracked_payment_count', 1)
            ->assertJsonPath('commission.untracked_payment_revenue', 520000)
            ->assertJsonPath('commission.reconciliation_difference', 0)
            ->assertJsonPath('commission.by_status.completed.amount', 1000)
            ->assertJsonPath('commission.by_status.failed.amount', 2000)
            ->assertJsonCount(2, 'commission.recent');
    }

    public function test_owner_admin_token_cannot_access_developer_analytics(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/analytics/developer')
            ->assertStatus(403);
    }

    public function test_legacy_jpesa_withdrawals_are_read_only_and_remain_visible(): void
    {
        Config::set('services.mobile_money.agent_commission.enabled', true);
        Config::set('services.mobile_money.agent_commission.ratio', 0.1);
        Config::set('services.mobile_money.agent_commission.recipient_type', 'business');
        Config::set('services.mobile_money.agent_commission.recipient_email', 'prof.markdemo@gmail.com');

        $user = User::create([
            'username' => 'Withdrawal Commission Member',
            'phone' => '0700777003',
            'password_hash' => Hash::make('password123'),
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'weekly',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 50000,
            'status' => 'active',
        ]);

        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 50000,
            'plan_type' => 'weekly',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'agent_commission_amount' => 5000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'sent',
        ]);

        $headers = $this->developerHeaders();

        DB::table('commission_withdrawals')->insert([
            'amount' => 2000,
            'reference' => 'JPESA-WD-001',
            'wallet_account' => 'prof.markdemo@gmail.com',
            'withdrawn_at' => now(),
            'created_at' => now(),
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/analytics/developer/commission-withdrawals', [
                'amount' => 2000,
                'reference' => 'JPESA-WD-001',
                'withdrawn_at' => now()->toDateString(),
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('commission_withdrawals', [
            'reference' => 'JPESA-WD-001',
            'wallet_account' => 'prof.markdemo@gmail.com',
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/analytics/developer')
            ->assertStatus(200)
            ->assertJsonPath('commission.total_earned', 5000)
            ->assertJsonPath('commission.overall_total', 5000)
            ->assertJsonPath('commission.total_paid', 5000)
            ->assertJsonPath('commission.wallet_received', 5000)
            ->assertJsonPath('commission.total_withdrawn', 2000)
            ->assertJsonPath('commission.available', 3000)
            ->assertJsonPath('commission.current_total', 3000)
            ->assertJsonPath('commission.wallet_account', 'prof.markdemo@gmail.com')
            ->assertJsonCount(1, 'commission.withdrawals');
    }

    public function test_developer_can_retry_failed_agent_commission(): void
    {
        Config::set('services.mobile_money.api_url', 'https://my.jpesa.com/api/');
        Config::set('services.mobile_money.api_key', 'source-key');
        Config::set('services.mobile_money.callback_url', 'https://example.test/api/payments/webhook');
        Config::set('services.mobile_money.agent_commission', [
            'enabled' => true,
            'ratio' => 0.2,
            'recipient_type' => 'business',
            'recipient_email' => 'prof.markdemo@gmail.com',
            'recipient_mobile' => '0704045918',
            'transfer_action' => 'debit',
            'transfer_pt' => 'gwallet',
            'currency' => 'UGX',
        ]);

        Http::fake(fn () => Http::response(['status' => 'success', 'tid' => 'RETRY-COM-123'], 200));

        $user = User::create([
            'username' => 'Retry Commission Member',
            'phone' => '0700777002',
            'password_hash' => Hash::make('password123'),
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'amount' => 20000,
            'status' => 'active',
            'payment_reference' => 'ALX-RETRY-TEST',
        ]);

        $payment = Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 20000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'payment_reference' => 'ALX-RETRY-TEST',
            'transaction_id' => 'ALX-RETRY-TEST',
            'agent_commission_amount' => 2000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'failed',
            'agent_commission_error' => '[[E000008]] Account has insufficient balance',
        ]);

        $this->withHeaders($this->developerHeaders())
            ->postJson("/api/analytics/developer/payments/{$payment->id}/retry-commission")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment.agent_commission_status', 'completed');

        $payment->refresh();
        $this->assertSame('sent', $payment->agent_commission_status);
        $this->assertEquals(4000, $payment->agent_commission_amount);
        $this->assertEquals(0.2, $payment->agent_commission_ratio);
        $this->assertSame('RETRY-COM-123', $payment->agent_commission_transaction_id);
        $this->assertNull($payment->agent_commission_error);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, '<action>debit</action>')
                && str_contains($body, '<pt>gwallet</pt>')
                && str_contains($body, '<business>prof.markdemo@gmail.com</business>')
                && str_contains($body, '<amount>4000</amount>');
        });
    }
}
