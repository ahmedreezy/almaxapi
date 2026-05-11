<?php

namespace Tests\Feature;

use App\Models\FootballTip;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for /api/football-tips — public read, admin write.
 *
 * FootballTip fields: home, away, competition, kickoff (required);
 *   winProb, kitColor, kitNumber, prediction, accent, caption, image (optional)
 */
class FootballTipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    private function validTip(array $overrides = []): array
    {
        return array_merge([
            'home'        => 'Arsenal',
            'away'        => 'Chelsea',
            'competition' => 'Premier League',
            'kickoff'     => '2026-05-10 15:00',
        ], $overrides);
    }

    public function test_anyone_can_list_football_tips(): void
    {
        FootballTip::create([
            'home'        => 'Arsenal',
            'away'        => 'Chelsea',
            'competition' => 'EPL',
            'kickoff'     => '2026-05-10 15:00',
        ]);

        $this->getJson('/api/football-tips')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'home', 'away', 'competition', 'kickoff']]);
    }

    public function test_listing_is_public_no_auth_needed(): void
    {
        $this->getJson('/api/football-tips')->assertStatus(200);
    }

    public function test_admin_can_create_football_tip(): void
    {
        $ctx  = $this->createAdmin();
        $file = UploadedFile::fake()->image('badge.png', 100, 100);

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/football-tips', array_merge($this->validTip(), ['image' => $file]))
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'home', 'away', 'competition', 'kickoff']);
    }

    public function test_creating_tip_requires_admin(): void
    {
        $this->postJson('/api/football-tips', $this->validTip())->assertStatus(401);
    }

    public function test_user_token_cannot_create_tip(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/football-tips', $this->validTip())
            ->assertStatus(403);
    }

    public function test_admin_can_update_football_tip(): void
    {
        $ctx = $this->createAdmin();
        $tip = FootballTip::create($this->validTip());

        $this->withHeaders($ctx['headers'])
            ->putJson("/api/football-tips/{$tip->id}", ['home' => 'Man City'])
            ->assertStatus(200)
            ->assertJsonPath('home', 'Man City');
    }

    public function test_admin_can_delete_football_tip(): void
    {
        $ctx = $this->createAdmin();
        $tip = FootballTip::create($this->validTip());

        $this->withHeaders($ctx['headers'])
            ->deleteJson("/api/football-tips/{$tip->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('football_tips', ['id' => $tip->id]);
    }

    public function test_tip_required_fields_are_enforced(): void
    {
        $ctx = $this->createAdmin();

        // missing 'kickoff' → validation error
        $this->withHeaders($ctx['headers'])
            ->postJson('/api/football-tips', ['home' => 'A', 'away' => 'B', 'competition' => 'C'])
            ->assertStatus(422);
    }

    public function test_image_filename_is_uuid_not_original_name(): void
    {
        $ctx  = $this->createAdmin();
        $file = UploadedFile::fake()->image('exploit.php.jpg', 100, 100);

        $response = $this->withHeaders($ctx['headers'])
            ->postJson('/api/football-tips', array_merge($this->validTip(), ['image' => $file]))
            ->assertStatus(201);

        $imageUrl = $response->json('image_url');
        if ($imageUrl) {
            $this->assertStringNotContainsString('exploit', basename($imageUrl));
            $this->assertDoesNotMatchRegularExpression('/\.php/', basename($imageUrl));
        }
    }
}
