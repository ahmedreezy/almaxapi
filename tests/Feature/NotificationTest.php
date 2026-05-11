<?php

namespace Tests\Feature;

use App\Models\StatusCheck;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests for /api/notifications endpoints.
 * StatusChecks are the notification source.
 */
class NotificationTest extends TestCase
{
    // ─── Status check (public) ────────────────────────────────────────────

    // statusCheck controller returns 200 {"logged":true} — not 201
    public function test_anyone_can_submit_status_check(): void
    {
        $this->postJson('/api/notifications/status-check', [
            'userId' => null,
            'phone'  => '0799000001',
        ])->assertStatus(200)->assertJsonPath('logged', true);
    }

    public function test_status_check_can_include_user_id(): void
    {
        $user = User::create([
            'username'      => 'NotifUser',
            'phone'         => '0730000001',
            'password_hash' => Hash::make('pass'),
        ]);

        $this->postJson('/api/notifications/status-check', [
            'userId' => $user->id,
            'phone'  => $user->phone,
        ])->assertStatus(200)->assertJsonPath('logged', true);

        $this->assertDatabaseHas('status_checks', ['user_id' => $user->id]);
    }

    // ─── Admin: list, unread count, mark read ─────────────────────────────

    public function test_admin_can_list_notifications(): void
    {
        $ctx = $this->createAdmin();
        // StatusCheck fields: user_id, phone, username, plan_type, sub_status, is_read
        StatusCheck::create(['phone' => '0799000099', 'is_read' => false]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'is_read']]);
    }

    public function test_listing_notifications_requires_admin(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_admin_can_get_unread_count(): void
    {
        $ctx = $this->createAdmin();
        StatusCheck::create(['phone' => '0799000010', 'is_read' => false]);
        StatusCheck::create(['phone' => '0799000011', 'is_read' => false]);
        StatusCheck::create(['phone' => '0799000012', 'is_read' => true]);

        $response = $this->withHeaders($ctx['headers'])
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonStructure(['count']);

        $this->assertGreaterThanOrEqual(2, $response->json('count'));
    }

    public function test_admin_can_mark_notification_as_read(): void
    {
        $ctx   = $this->createAdmin();
        $notif = StatusCheck::create(['phone' => '0799000020', 'is_read' => false]);

        $this->withHeaders($ctx['headers'])
            ->patchJson("/api/notifications/{$notif->id}/read")
            ->assertStatus(200);

        $this->assertDatabaseHas('status_checks', ['id' => $notif->id, 'is_read' => true]);
    }

    public function test_admin_can_mark_all_notifications_as_read(): void
    {
        $ctx = $this->createAdmin();
        StatusCheck::create(['phone' => '0799000030', 'is_read' => false]);
        StatusCheck::create(['phone' => '0799000031', 'is_read' => false]);

        $this->withHeaders($ctx['headers'])
            ->patchJson('/api/notifications/read-all')
            ->assertStatus(200);

        $this->assertEquals(0, StatusCheck::where('is_read', false)->count());
    }

    public function test_user_token_cannot_access_admin_notifications(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/notifications')
            ->assertStatus(403);
    }
}
