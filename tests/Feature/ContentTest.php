<?php

namespace Tests\Feature;

use App\Models\Testimonial;
use App\Models\RecentWin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for /api/testimonials and /api/recent-wins — public read, admin write.
 */
class ContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    // ─── Testimonials ────────────────────────────────────────────────────
    // Fields: caption, memberName, image (all optional in controller)

    public function test_anyone_can_list_testimonials(): void
    {
        Testimonial::create(['caption' => 'Great service!', 'member_name' => 'Alice']);

        $this->getJson('/api/testimonials')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'caption', 'member_name']]);
    }

    public function test_admin_can_create_testimonial_with_image(): void
    {
        $ctx  = $this->createAdmin();
        $file = UploadedFile::fake()->image('avatar.jpg', 80, 80);

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/testimonials', [
                'caption'    => 'Won 50k!',
                'memberName' => 'Bob',
                'image'      => $file,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'caption', 'member_name']);
    }

    public function test_creating_testimonial_requires_admin(): void
    {
        $this->postJson('/api/testimonials', ['caption' => 'X'])->assertStatus(401);
    }

    public function test_admin_can_delete_testimonial(): void
    {
        $ctx  = $this->createAdmin();
        $item = Testimonial::create(['caption' => 'to delete', 'member_name' => 'Del']);

        $this->withHeaders($ctx['headers'])
            ->deleteJson("/api/testimonials/{$item->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('testimonials', ['id' => $item->id]);
    }

    // ─── Recent Wins ─────────────────────────────────────────────────────
    // Fields: betType, date, staked, returned, odds (required); memberName, image (optional)

    public function test_anyone_can_list_recent_wins(): void
    {
        RecentWin::create([
            'bet_type' => 'Double',
            'date'     => '2026-05-01',
            'staked'   => '500',
            'returned' => '25000',
            'odds'     => '50.0',
        ]);

        $this->getJson('/api/recent-wins')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'bet_type', 'staked', 'returned']]);
    }

    public function test_admin_can_create_recent_win_with_image(): void
    {
        $ctx  = $this->createAdmin();
        $file = UploadedFile::fake()->image('win.jpg', 150, 150);

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/recent-wins', [
                'betType'    => 'Treble',
                'date'       => '2026-05-08',
                'staked'     => '1000',
                'returned'   => '10000',
                'odds'       => '10.0',
                'memberName' => 'Dave',
                'image'      => $file,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'bet_type', 'staked', 'returned']);
    }

    public function test_creating_recent_win_requires_admin(): void
    {
        $this->postJson('/api/recent-wins', [
            'betType'  => 'Double',
            'date'     => '2026-05-01',
            'staked'   => '500',
            'returned' => '5000',
            'odds'     => '10.0',
        ])->assertStatus(401);
    }

    public function test_admin_can_delete_recent_win(): void
    {
        $ctx = $this->createAdmin();
        $win = RecentWin::create([
            'bet_type' => 'Single',
            'date'     => '2026-05-01',
            'staked'   => '200',
            'returned' => '1000',
            'odds'     => '5.0',
        ]);

        $this->withHeaders($ctx['headers'])
            ->deleteJson("/api/recent-wins/{$win->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('recent_wins', ['id' => $win->id]);
    }
}
