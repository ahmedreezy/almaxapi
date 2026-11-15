<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerAnalyticsTest extends TestCase
{
    public function test_owner_can_view_finance_first_overview(): void
    {
        $ctx = $this->createAdmin();
        $user = User::create([
            'username' => 'Overview Member',
            'phone' => '0708000001',
            'password_hash' => Hash::make('pass'),
        ]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'weekly',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 25000,
            'status' => 'active',
            'expires_at' => now()->addWeek(),
        ]);
        Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 25000,
            'plan_type' => 'weekly',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'payment_reference' => 'OWNER-OVERVIEW-1',
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('finance.revenue_today', 25000)
            ->assertJsonPath('customers.members', 1)
            ->assertJsonPath('customers.active_subscriptions', 1)
            ->assertJsonCount(14, 'chart')
            ->assertJsonCount(1, 'recent_payments');
    }

    public function test_owner_overview_rejects_non_admin_tokens(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        $user = $this->createUser();
        $this->withHeaders($user['headers'])->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_owner_can_view_and_export_financial_report(): void
    {
        $ctx = $this->createAdmin();
        $user = User::create(['username' => 'Finance Member', 'phone' => '0708000002', 'password_hash' => Hash::make('pass')]);
        Payment::create([
            'user_id' => $user->id, 'amount' => 18000, 'plan_type' => 'daily', 'payment_method' => 'mtn',
            'provider' => 'relworx', 'phone' => $user->phone, 'status' => 'confirmed',
            'payment_reference' => 'FINANCE-REPORT-1', 'confirmed_at' => now(), 'created_at' => now(),
        ]);

        $this->withHeaders($ctx['headers'])->getJson('/api/admin/financial-report?period=today')
            ->assertOk()
            ->assertJsonPath('summary.attempted_count', 1)
            ->assertJsonPath('summary.confirmed_revenue', 18000)
            ->assertJsonPath('summary.confirmation_rate', 100)
            ->assertJsonPath('breakdowns.gateway.0.label', 'relworx');

        $this->withHeaders($ctx['headers'])->get('/api/admin/financial-report/export?period=today')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_financial_report_validates_ranges_and_requires_admin(): void
    {
        $this->getJson('/api/admin/financial-report')->assertUnauthorized();
        $ctx = $this->createAdmin();
        $this->withHeaders($ctx['headers'])->getJson('/api/admin/financial-report?period=custom&from=2026-11-15')
            ->assertUnprocessable();
    }
}
