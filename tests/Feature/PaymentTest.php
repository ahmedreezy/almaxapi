<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Payment;
use App\Models\User;
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
            ->assertJsonStructure([['id', 'amount', 'status']]);
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

    public function test_approved_webhook_sends_agent_commission_once(): void
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
        $this->assertEquals(500, $payment->agent_commission_amount);
        $this->assertEquals(0.1, $payment->agent_commission_ratio);
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
                && str_contains($body, '<amount>500</amount>')
                && str_contains($body, '<callback>https://example.test/api/payments/webhook</callback>');
        });

        $this->getJson("/api/payments/webhook?tx={$reference}&tid=JP-123&status=approved")
            ->assertStatus(200);

        Http::assertSentCount(1);
    }
}
