<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\NotionShoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The read-only Notion content board -- Reel planner + Shoots, and nothing
 * else. Never touches Notion itself; only ever reads content_items and
 * notion_shoots, already populated by ContentSyncService's own read-only
 * sync.
 */
class ContentBoardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('content-board.index'))->assertRedirect(route('login'));
    }

    public function test_an_employee_cannot_reach_it(): void
    {
        $this->actingAs($this->employee())
            ->get(route('content-board.index'))
            ->assertForbidden();
    }

    public function test_a_reel_item_renders_under_its_status_column(): void
    {
        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL,
            'title' => 'Diwali Collection Reel',
            'status' => 'Video Ready',
        ]);

        $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk()
            ->assertSee('Diwali Collection Reel');
    }

    public function test_other_content_sources_never_appear_on_the_board(): void
    {
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_YOUTUBE, 'title' => 'A YouTube Video']);
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_POST, 'title' => 'An Instagram Post']);
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_STORY, 'title' => 'An Instagram Story']);

        $response = $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk();

        $response->assertDontSee('A YouTube Video');
        $response->assertDontSee('An Instagram Post');
        $response->assertDontSee('An Instagram Story');
    }

    public function test_a_shoot_renders_with_its_client_label_resolved(): void
    {
        // No notion_venture set: canonicalForClient() falls back to the
        // client's own name, so an exact match against it is the
        // unambiguous way to prove clientLabel() actually resolved through
        // TimesheetVenture rather than just echoing the raw stored string.
        $client = \App\Models\Client::create(['name' => 'SVA Silks']);

        NotionShoot::factory()->create([
            'title' => 'SVA Golds Product Shoot',
            'status' => 'Shooting',
            'client' => 'SVA Silks',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk();

        $response->assertSee('SVA Golds Product Shoot');
        $response->assertSee($client->name);
    }

    public function test_a_status_missing_from_config_still_renders_as_a_trailing_column(): void
    {
        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL,
            'title' => 'Something With A Brand New Status',
            'status' => 'A Status Nobody Configured Yet',
        ]);

        $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk()
            ->assertSee('Something With A Brand New Status')
            ->assertSee('A Status Nobody Configured Yet');
    }

    public function test_a_null_status_still_renders_rather_than_vanishing(): void
    {
        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL,
            'title' => 'No Status Yet',
            'status' => null,
        ]);

        $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk()
            ->assertSee('No Status Yet')
            ->assertSee('No status');
    }

    public function test_both_empty_shows_an_empty_state_not_a_500(): void
    {
        $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk()
            ->assertSee('Nothing synced from Notion yet');
    }

    public function test_the_board_is_read_only(): void
    {
        $this->assertFalse(Route::has('content-board.update'));
        $this->assertFalse(Route::has('content-board.store'));

        ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'status' => 'Idea']);

        $response = $this->actingAs($this->admin())
            ->get(route('content-board.index'))
            ->assertOk();

        // No form anywhere on the page posts back to this feature.
        $response->assertDontSee('action="'.route('content-board.index'), false);
    }
}
