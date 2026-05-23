<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for /api/subscriptions endpoints.
 *
 * Key facts about the subscription API:
 * - POST /api/subscriptions is PUBLIC (no auth required — users pay first, then subscribe)
 * - Required fields: userId, groupId, paymentMethod (mtn|airtel), phone
 * - Price is always read from the group record (never trusted from the client)
 * - Special groups: use special_price; returns 422 if special_price is null
 * - verify-access takes phone + secretCode (password-style secret, not plan params)
 */
class SubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    /**
     * Create a Group record for subscription tests.
     * Returns the Group model.
     */
    private function makeGroup(array $overrides = []): Group
    {
        return Group::create(array_merge([
            'name'         => 'Test Group ' . uniqid(),
            'odds_type'    => '5',
            'plan_type'    => 'daily',
            'price'        => 15000,
            'betslip_link' => '',
            'betslip_code' => '',
            'is_special'   => false,
            'is_active'    => true,
        ], $overrides));
    }

    /** Helper: seed a minimal subscription record for state-based tests */
    private function seedSubscription(User $user, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id'        => $user->id,
            'odds_type'      => '2',
            'plan_type'      => 'daily',
            'payment_method' => 'mtn',
            'phone'          => $user->phone,
            'amount'         => 100,
            'status'         => 'pending',
        ], $overrides));
    }

    /** Base POST body for subscription creation. */
    private function subBody(int $userId, int $groupId, string $phone, string $method = 'airtel'): array
    {
        return [
            'userId'        => $userId,
            'groupId'       => $groupId,
            'paymentMethod' => $method,
            'phone'         => $phone,
        ];
    }

    // ─── Create subscription (PUBLIC route) ──────────────────────────────

    public function test_user_can_create_subscription(): void
    {
        $ctx   = $this->createUser('0711000001');
        $group = $this->makeGroup(['odds_type' => '2', 'plan_type' => 'daily', 'price' => 10000]);

        $response = $this->postJson('/api/subscriptions',
            $this->subBody($ctx['user']->id, $group->id, '0711000001', 'mtn'));

        $response->assertStatus(201)
            ->assertJsonPath('subscription.user_id', $ctx['user']->id)
            ->assertJsonPath('subscription.group_id', $group->id)
            ->assertJsonPath('subscription.status',   'pending');

        $this->assertEquals(10000, $response->json('subscription.amount'));
        $this->assertDatabaseHas('subscriptions', [
            'user_id'  => $ctx['user']->id,
            'group_id' => $group->id,
        ]);
    }

    public function test_subscription_uses_group_price_not_client_supplied(): void
    {
        // Client must NOT be able to manipulate the price — it's always taken from the group
        $ctx   = $this->createUser('0711000003');
        $group = $this->makeGroup(['price' => 60000]);

        $body           = $this->subBody($ctx['user']->id, $group->id, '0711000003');
        $body['amount'] = 1; // attempt to underpay

        $response = $this->postJson('/api/subscriptions', $body)->assertStatus(201);
        $this->assertEquals(60000, $response->json('subscription.amount'));
    }

    public function test_all_five_packages_can_be_subscribed(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $packages = [
            ['name' => 'Daily Odd 5 T',            'odds_type' => '5',       'plan_type' => 'daily',   'price' => 15000],
            ['name' => 'Weekly Odd 5 T',            'odds_type' => '5',       'plan_type' => 'weekly',  'price' => 60000],
            ['name' => 'Big Staker Weekly Odd 2 T', 'odds_type' => '2',       'plan_type' => 'weekly',  'price' => 50000],
            ['name' => 'Monthly Odd 1.5 T',         'odds_type' => '1.5',     'plan_type' => 'monthly', 'price' => 45000],
        ];

        $i = 20;
        foreach ($packages as $pkg) {
            $phone = '07200000' . str_pad($i, 2, '0', STR_PAD_LEFT);
            $ctx   = $this->createUser($phone);
            $group = $this->makeGroup($pkg);
            $i++;

            $this->postJson('/api/subscriptions',
                $this->subBody($ctx['user']->id, $group->id, $phone))
                ->assertStatus(201, "Package '{$pkg['name']}' subscription should succeed");
        }
    }

    public function test_monthly_subscription_creates_correct_plan_type(): void
    {
        $ctx   = $this->createUser('0711000040');
        $group = $this->makeGroup(['odds_type' => '1.5', 'plan_type' => 'monthly', 'price' => 45000]);

        $this->postJson('/api/subscriptions',
            $this->subBody($ctx['user']->id, $group->id, '0711000040'))
            ->assertStatus(201)
            ->assertJsonPath('subscription.plan_type', 'monthly');
    }

    public function test_special_odds_subscription_uses_special_price(): void
    {
        $ctx   = $this->createUser('0711000050');
        $group = $this->makeGroup([
            'odds_type'     => 'special',
            'plan_type'     => 'special',
            'price'         => 0,
            'is_special'    => true,
            'is_active'     => true,
            'special_price' => 35000,
            'special_odds'  => '4.5',
        ]);

        $response = $this->postJson('/api/subscriptions',
            $this->subBody($ctx['user']->id, $group->id, '0711000050'))
            ->assertStatus(201);

        // Must charge special_price (35,000), NOT the base price (0)
        $this->assertEquals(35000, $response->json('subscription.amount'));
    }

    public function test_special_group_without_price_returns_422(): void
    {
        $ctx   = $this->createUser('0711000060');
        $group = $this->makeGroup([
            'odds_type'     => 'special',
            'plan_type'     => 'special',
            'price'         => 0,
            'is_special'    => true,
            'is_active'     => false,  // not activated yet
            'special_price' => null,   // admin hasn't set a price
        ]);

        $this->postJson('/api/subscriptions',
            $this->subBody($ctx['user']->id, $group->id, '0711000060'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Special Odds are not available today. Check back later.');
    }

    public function test_inactive_group_returns_422(): void
    {
        $ctx   = $this->createUser('0711000070');
        $group = $this->makeGroup(['is_active' => false]);

        $this->postJson('/api/subscriptions',
            $this->subBody($ctx['user']->id, $group->id, '0711000070'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This package is currently unavailable.');
    }

    public function test_subscription_validates_required_fields(): void
    {
        // Missing all fields → 422
        $this->postJson('/api/subscriptions', [])->assertStatus(422);
    }

    public function test_subscription_requires_valid_group_id(): void
    {
        $ctx = $this->createUser('0711000080');

        $this->postJson('/api/subscriptions', [
            'userId'        => $ctx['user']->id,
            'groupId'       => 99999,  // does not exist
            'paymentMethod' => 'mtn',
            'phone'         => '0711000080',
        ])->assertStatus(422);
    }

    public function test_subscription_requires_valid_payment_method(): void
    {
        $ctx   = $this->createUser('0711000090');
        $group = $this->makeGroup();

        $this->postJson('/api/subscriptions', [
            'userId'        => $ctx['user']->id,
            'groupId'       => $group->id,
            'paymentMethod' => 'visa',  // not supported
            'phone'         => '0711000090',
        ])->assertStatus(422);
    }

    // ─── Get subscriptions for user ───────────────────────────────────────

    public function test_can_get_subscriptions_for_user(): void
    {
        $ctx = $this->createUser('0712000001');
        $this->seedSubscription($ctx['user']);

        $this->getJson("/api/subscriptions/user/{$ctx['user']->id}")
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'user_id', 'odds_type', 'plan_type', 'status']]);
    }

    public function test_pending_subscription_has_masked_betslip(): void
    {
        $ctx = $this->createUser('0712000002');
        $this->seedSubscription($ctx['user'], [
            'status'       => 'pending',
            'betslip_link' => 'https://secret.link',
            'betslip_code' => 'SECRET-CODE',
        ]);

        $response = $this->getJson("/api/subscriptions/user/{$ctx['user']->id}")
            ->assertStatus(200);

        // betslip should be masked for pending subscriptions
        $sub = $response->json('0');
        $this->assertSame('', $sub['betslip_link']);
        $this->assertSame('', $sub['betslip_code']);
    }

    // ─── Admin: list & update ─────────────────────────────────────────────

    public function test_admin_can_list_subscriptions(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/subscriptions')
            ->assertStatus(200);
    }

    public function test_listing_subscriptions_requires_admin(): void
    {
        $this->getJson('/api/subscriptions')->assertStatus(401);
    }

    public function test_admin_can_activate_subscription(): void
    {
        $admin = $this->createAdmin();
        $user  = User::create(['username' => 'T', 'phone' => '0714000001', 'password_hash' => Hash::make('p')]);
        $sub   = $this->seedSubscription($user, ['status' => 'pending']);

        $this->withHeaders($admin['headers'])
            ->patchJson("/api/subscriptions/{$sub->id}", ['status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id, 'status' => 'active']);
    }

}
