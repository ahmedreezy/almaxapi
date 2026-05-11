<?php

namespace Tests\Feature;

use App\Models\VipConfig;
use App\Models\FreeOdd2;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for /api/config endpoints:
 *   GET  /api/config/free-odd2        — public
 *   PUT  /api/config/free-odd2        — admin
 *   GET  /api/config/vip-config       — public
 *   PUT  /api/config/vip-config       — admin
 */
class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');

        // Seed vip_config with known keys
        VipConfig::updateOrCreate(['key' => 'odds_2_daily_price'],   ['value' => '100']);
        VipConfig::updateOrCreate(['key' => 'odds_2_weekly_price'],  ['value' => '500']);
        VipConfig::updateOrCreate(['key' => 'odds_1.5_weekly_price'],['value' => '500']);
        VipConfig::updateOrCreate(['key' => 'odds_5_daily_price'],   ['value' => '150']);
        VipConfig::updateOrCreate(['key' => 'odds_5_weekly_price'],  ['value' => '700']);
    }

    // ─── free-odd2 ────────────────────────────────────────────────────────

    public function test_anyone_can_get_free_odd2(): void
    {
        FreeOdd2::updateOrCreate(['id' => 1], ['content' => '{}']);

        $this->getJson('/api/config/free-odd2')
            ->assertStatus(200);
    }

    public function test_admin_can_update_free_odd2(): void
    {
        FreeOdd2::updateOrCreate(['id' => 1], ['content' => '{}']);
        $ctx  = $this->createAdmin();
        $file = UploadedFile::fake()->image('odd2.png', 200, 100);

        $this->withHeaders($ctx['headers'])
            ->putJson('/api/config/free-odd2', [
                'content' => '{"match":"Arsenal","tip":"Over 2.5"}',
                'image'   => $file,
            ])
            ->assertStatus(200);
    }

    public function test_updating_free_odd2_requires_admin(): void
    {
        $this->putJson('/api/config/free-odd2', ['content' => '{}'])
            ->assertStatus(401);
    }

    public function test_user_token_cannot_update_free_odd2(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->putJson('/api/config/free-odd2', ['content' => '{}'])
            ->assertStatus(403);
    }

    // ─── vip-config ───────────────────────────────────────────────────────

    public function test_anyone_can_get_vip_config(): void
    {
        $this->getJson('/api/config/vip-config')
            ->assertStatus(200)
            ->assertJsonStructure(['odds_2_daily_price']);
    }

    // vip-config PUT expects {key, value} (single key/value pair per request)
    public function test_admin_can_update_whitelisted_vip_config_key(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->putJson('/api/config/vip-config', [
                'key'   => 'odds_2_daily_price',
                'value' => '120',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('vip_config', ['key' => 'odds_2_daily_price', 'value' => '120']);
    }

    public function test_admin_cannot_set_arbitrary_config_key(): void
    {
        // Arbitrary keys not on the whitelist must be rejected with 422
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->putJson('/api/config/vip-config', [
                'key'   => 'arbitrary_malicious_key',
                'value' => 'injected_value',
            ])
            ->assertStatus(422);
    }

    public function test_updating_vip_config_requires_admin(): void
    {
        $this->putJson('/api/config/vip-config', ['odds_2_daily_price' => '100'])
            ->assertStatus(401);
    }
}
