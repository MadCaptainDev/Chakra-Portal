<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Support\DashboardWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_dashboard_shows_content_pipeline_for_selected_account(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Main IG', 'target_reel' => 10]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);

        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'status' => 'Published',
            'published_date' => '2026-08-10',
            'title' => 'Launch reel',
        ]);
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'status' => 'To Be Edited',
            'published_date' => '2026-08-12',
            'title' => 'Edit me',
        ]);

        Carbon::setTestNow('2026-08-15');

        $this->actingAs($this->admin())
            ->get(route('dashboard', ['account' => $account->id]))
            ->assertOk()
            ->assertSee('Content pipeline')
            ->assertSee('Launch reel')
            ->assertSee('Edit me')
            ->assertSee('Published');
    }

    public function test_staff_dashboard_shows_their_upcoming_shoots(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['shoots' => ['view']]);

        $client = Client::create(['name' => 'SVA Silks']);
        $shoot = Shoot::create([
            'title' => 'Warehouse day',
            'client_id' => $client->id,
            'starts_at' => now()->addDays(3),
            'status' => Shoot::STATUS_CONFIRMED,
        ]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $user->id, 'role' => 'Camera']);

        $this->actingAs($user->fresh())
            ->get(route('my.dashboard'))
            ->assertOk()
            ->assertSee('My upcoming shoots')
            ->assertSee('Warehouse day');
    }

    public function test_content_pipeline_counts_match_helper(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Main IG']);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);

        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'status' => 'Edit in Progress',
            'published_date' => '2026-08-05',
        ]);

        $month = Carbon::parse('2026-08-01');
        $pipeline = DashboardWidgets::contentPipeline($account, $month);

        $this->assertSame(1, $pipeline['sections']['edit_in_progress']['count']);
    }
}
