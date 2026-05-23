<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Comprehensive tests for the VIP package (Group) system.
 *
 * Packages under test:
 *  - Daily Odd 5       — 15,000 UGX / daily
 *  - Weekly Odd 5      — 60,000 UGX / weekly
 *  - Big Staker Weekly Odd 2 — 50,000 UGX / weekly
 *  - Monthly Odd 1.5   — 45,000 UGX / monthly
 *  - Special Odds      — admin-set price + odds / is_special=true
 */
class GroupTest extends TestCase
{
    // ─── Seed helpers ──────────────────────────────────────────────────────

    /** Create a regular (non-special) active group. */
    private function makeGroup(array $overrides = []): Group
    {
        return Group::create(array_merge([
            'name'         => 'Test Package ' . uniqid(),
            'odds_type'    => '5',
            'plan_type'    => 'daily',
            'price'        => 15000,
            'betslip_link' => '',
            'betslip_code' => '',
            'is_special'   => false,
            'is_active'    => true,
        ], $overrides));
    }

    /** Create a Special Odds group (hidden until special_price is set). */
    private function makeSpecialGroup(array $overrides = []): Group
    {
        return $this->makeGroup(array_merge([
            'name'          => 'Special Odds ' . uniqid(),
            'odds_type'     => 'special',
            'plan_type'     => 'special',
            'price'         => 0,
            'is_special'    => true,
            'is_active'     => false,
            'special_price' => null,
            'special_odds'  => null,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/groups — Public listing
    // ═══════════════════════════════════════════════════════════════════════

    public function test_public_index_returns_only_active_groups(): void
    {
        $active   = $this->makeGroup(['is_active' => true]);
        $inactive = $this->makeGroup(['is_active' => false]);

        $ids = $this->getJson('/api/groups')
            ->assertStatus(200)
            ->json('*.id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_public_index_hides_special_group_without_price(): void
    {
        $special = $this->makeSpecialGroup(); // no special_price → hidden

        $ids = $this->getJson('/api/groups')
            ->assertStatus(200)
            ->json('*.id');

        $this->assertNotContains($special->id, $ids);
    }

    public function test_public_index_shows_special_group_when_price_is_set(): void
    {
        $special = $this->makeSpecialGroup([
            'is_active'     => true,
            'special_price' => 30000,
            'special_odds'  => '3.5',
        ]);

        $response = $this->getJson('/api/groups')->assertStatus(200);
        $ids = $response->json('*.id');
        $this->assertContains($special->id, $ids);

        // effective_price should reflect special_price, not base price (0)
        $group = collect($response->json())->firstWhere('id', $special->id);
        $this->assertEquals(30000, $group['effectivePrice']);
        $this->assertSame('3.5', $group['specialOdds']);
    }

    public function test_public_index_response_has_expected_fields(): void
    {
        $this->makeGroup();

        $this->getJson('/api/groups')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'name', 'oddsType', 'planType', 'price',
                'betslipLink', 'betslipCode', 'isSpecial', 'isActive',
                'specialPrice', 'specialOdds', 'effectivePrice',
                'subscriptionDeadline', 'isClosed']]);
    }

    public function test_all_five_required_packages_are_present_after_migration(): void
    {
        // These 5 packages must exist after the migration runs (using RefreshDatabase
        // they will be re-seeded by migrations that include the insert logic)
        $required = [
            ['name' => 'Daily Odd 5',            'odds_type' => '5',    'plan_type' => 'daily',   'price' => 15000],
            ['name' => 'Weekly Odd 5',            'odds_type' => '5',    'plan_type' => 'weekly',  'price' => 60000],
            ['name' => 'Big Staker Weekly Odd 2', 'odds_type' => '2',    'plan_type' => 'weekly',  'price' => 50000],
            ['name' => 'Monthly Odd 1.5',         'odds_type' => '1.5',  'plan_type' => 'monthly', 'price' => 45000],
            ['name' => 'Special Odds',            'odds_type' => 'special', 'plan_type' => 'special', 'is_special' => true],
        ];

        foreach ($required as $spec) {
            $query = Group::where('name', $spec['name']);
            $this->assertTrue(
                $query->exists(),
                "Required package '{$spec['name']}' is missing from the database."
            );

            $group = $query->first();
            if (isset($spec['price'])) {
                $this->assertEquals($spec['price'], $group->price,
                    "Package '{$spec['name']}' has wrong price.");
            }
            if (isset($spec['plan_type'])) {
                $this->assertSame($spec['plan_type'], $group->plan_type,
                    "Package '{$spec['name']}' has wrong plan_type.");
            }
            if (isset($spec['is_special'])) {
                $this->assertTrue((bool) $group->is_special,
                    "Package '{$spec['name']}' should be marked is_special.");
            }
        }
    }

    public function test_effective_price_returns_regular_price_for_normal_groups(): void
    {
        $group = $this->makeGroup(['price' => 60000, 'is_special' => false]);
        $this->assertEquals(60000.0, $group->effectivePrice());
    }

    public function test_effective_price_returns_special_price_for_special_groups(): void
    {
        $group = $this->makeSpecialGroup(['special_price' => 35000, 'is_active' => true]);
        $this->assertEquals(35000.0, $group->effectivePrice());
    }

    public function test_effective_price_falls_back_to_base_when_special_price_is_null(): void
    {
        // A special group without a special_price falls back to base price
        $group = $this->makeSpecialGroup(['price' => 0, 'special_price' => null]);
        $this->assertEquals(0.0, $group->effectivePrice());
    }

    // ─── Duration helpers ─────────────────────────────────────────────────

    public function test_duration_seconds_daily(): void
    {
        $group = $this->makeGroup(['plan_type' => 'daily']);
        $this->assertSame(24 * 3600, $group->durationSeconds());
    }

    public function test_duration_seconds_weekly(): void
    {
        $group = $this->makeGroup(['plan_type' => 'weekly']);
        $this->assertSame(7 * 24 * 3600, $group->durationSeconds());
    }

    public function test_duration_seconds_monthly(): void
    {
        $group = $this->makeGroup(['plan_type' => 'monthly', 'odds_type' => '1.5']);
        $this->assertSame(30 * 24 * 3600, $group->durationSeconds());
    }

    public function test_duration_seconds_special(): void
    {
        $group = $this->makeSpecialGroup(['is_active' => true, 'special_price' => 20000]);
        $this->assertSame(7 * 24 * 3600, $group->durationSeconds());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/groups/admin — Admin listing
    // ═══════════════════════════════════════════════════════════════════════

    public function test_admin_index_returns_all_groups_including_hidden(): void
    {
        $active   = $this->makeGroup(['is_active' => true]);
        $inactive = $this->makeGroup(['is_active' => false]);
        $special  = $this->makeSpecialGroup(); // no special_price → hidden from public

        $admin = $this->createAdmin();
        $ids = $this->withHeaders($admin['headers'])
            ->getJson('/api/groups/admin')
            ->assertStatus(200)
            ->json('*.id');

        $this->assertContains($active->id, $ids);
        $this->assertContains($inactive->id, $ids);
        $this->assertContains($special->id, $ids);
    }

    public function test_admin_index_requires_auth(): void
    {
        $this->getJson('/api/groups/admin')->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/groups — Admin create
    // ═══════════════════════════════════════════════════════════════════════

    public function test_admin_can_create_regular_group(): void
    {
        $admin = $this->createAdmin();

        $this->withHeaders($admin['headers'])
            ->postJson('/api/groups', [
                'name'      => 'Platinum Daily',
                'odds_type' => '10',
                'plan_type' => 'daily',
                'price'     => 25000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Platinum Daily')
            ->assertJsonPath('isSpecial', false)
            ->assertJsonPath('isActive', true);

        $this->assertDatabaseHas('groups', ['name' => 'Platinum Daily', 'price' => 25000]);
    }

    public function test_admin_can_create_special_group(): void
    {
        $admin = $this->createAdmin();

        $this->withHeaders($admin['headers'])
            ->postJson('/api/groups', [
                'name'       => 'Weekend Special',
                'odds_type'  => 'special',
                'plan_type'  => 'special',
                'price'      => 0,
                'is_special' => true,
                'is_active'  => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('isSpecial', true)
            ->assertJsonPath('isActive', false);
    }

    public function test_create_group_rejects_duplicate_name(): void
    {
        $admin = $this->createAdmin();
        $this->makeGroup(['name' => 'Existing Package']);

        $this->withHeaders($admin['headers'])
            ->postJson('/api/groups', [
                'name'      => 'Existing Package',
                'odds_type' => '5',
                'plan_type' => 'daily',
                'price'     => 10000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_create_group_rejects_invalid_plan_type(): void
    {
        $admin = $this->createAdmin();

        $this->withHeaders($admin['headers'])
            ->postJson('/api/groups', [
                'name'      => 'Bad Plan',
                'odds_type' => '5',
                'plan_type' => 'quarterly', // not allowed
                'price'     => 10000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_type');
    }

    public function test_unauthenticated_cannot_create_group(): void
    {
        $this->postJson('/api/groups', [
            'name'      => 'Sneaky Package',
            'odds_type' => '5',
            'plan_type' => 'daily',
            'price'     => 1,
        ])->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PATCH /api/groups/:id — Admin update
    // ═══════════════════════════════════════════════════════════════════════

    public function test_admin_can_update_group_price(): void
    {
        $admin = $this->createAdmin();
        $group = $this->makeGroup(['price' => 50000]);

        $this->withHeaders($admin['headers'])
            ->patchJson("/api/groups/{$group->id}", ['price' => 65000])
            ->assertStatus(200);

        $this->assertDatabaseHas('groups', ['id' => $group->id, 'price' => 65000]);
        $fresh = Group::find($group->id);
        $this->assertEquals(65000, $fresh->price);
    }

    public function test_admin_can_activate_special_odds(): void
    {
        $admin   = $this->createAdmin();
        $special = $this->makeSpecialGroup();

        $this->withHeaders($admin['headers'])
            ->patchJson("/api/groups/{$special->id}", [
                'is_active'     => true,
                'special_price' => 40000,
                'special_odds'  => '4.0',
            ])
            ->assertStatus(200)
            ->assertJsonPath('isActive', true)
            ->assertJsonPath('specialOdds', '4.0');

        $this->assertDatabaseHas('groups', [
            'id'        => $special->id,
            'is_active' => true,
        ]);

        $fresh = Group::find($special->id);
        $this->assertEquals(40000, $fresh->special_price);
        $this->assertEquals(40000, $fresh->effectivePrice());
    }

    public function test_admin_can_reset_special_odds(): void
    {
        $admin   = $this->createAdmin();
        $special = $this->makeSpecialGroup([
            'is_active'     => true,
            'special_price' => 30000,
            'special_odds'  => '3.0',
        ]);

        // Reset: clear price and deactivate
        $this->withHeaders($admin['headers'])
            ->patchJson("/api/groups/{$special->id}", [
                'is_active'     => false,
                'special_price' => null,
                'special_odds'  => null,
            ])
            ->assertStatus(200)
            ->assertJsonPath('isActive', false)
            ->assertJsonPath('specialPrice', null);
    }

    public function test_admin_can_update_betslip(): void
    {
        $admin = $this->createAdmin();
        $group = $this->makeGroup();

        $this->withHeaders($admin['headers'])
            ->patchJson("/api/groups/{$group->id}", [
                'betslip_link' => 'https://bet.example.com/slip/123',
                'betslip_code' => 'CODE-ABC',
            ])
            ->assertStatus(200)
            ->assertJsonPath('betslipLink', 'https://bet.example.com/slip/123')
            ->assertJsonPath('betslipCode', 'CODE-ABC');
    }

    public function test_unauthenticated_cannot_update_group(): void
    {
        $group = $this->makeGroup();
        $this->patchJson("/api/groups/{$group->id}", ['price' => 1])->assertStatus(401);
    }

    public function test_update_returns_404_for_missing_group(): void
    {
        $admin = $this->createAdmin();
        $this->withHeaders($admin['headers'])
            ->patchJson('/api/groups/99999', ['price' => 10000])
            ->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DELETE /api/groups/:id — Admin delete
    // ═══════════════════════════════════════════════════════════════════════

    public function test_admin_can_delete_group_with_no_subscriptions(): void
    {
        $admin = $this->createAdmin();
        $group = $this->makeGroup();

        $this->withHeaders($admin['headers'])
            ->deleteJson("/api/groups/{$group->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Group deleted.');

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }

    public function test_cannot_delete_group_with_existing_subscriptions(): void
    {
        $admin = $this->createAdmin();
        $group = $this->makeGroup();
        $user  = User::create([
            'username'      => 'subuser',
            'phone'         => '0799000001',
            'password_hash' => Hash::make('password123'),
        ]);

        // Create a subscription referencing this group
        Subscription::create([
            'user_id'        => $user->id,
            'group_id'       => $group->id,
            'plan_type'      => $group->plan_type,
            'odds_type'      => $group->odds_type,
            'payment_method' => 'mtn',
            'phone'          => $user->phone,
            'amount'         => $group->price,
            'status'         => 'pending',
        ]);

        $this->withHeaders($admin['headers'])
            ->deleteJson("/api/groups/{$group->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
    }

    public function test_unauthenticated_cannot_delete_group(): void
    {
        $group = $this->makeGroup();
        $this->deleteJson("/api/groups/{$group->id}")->assertStatus(401);
    }

    public function test_delete_returns_404_for_missing_group(): void
    {
        $admin = $this->createAdmin();
        $this->withHeaders($admin['headers'])
            ->deleteJson('/api/groups/99999')
            ->assertStatus(404);
    }
}
