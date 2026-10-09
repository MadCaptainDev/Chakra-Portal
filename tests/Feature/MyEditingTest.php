<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\DailyCheck;
use App\Models\Todo;
use App\Models\User;
use App\Services\Notion\ContentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $sanjai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setTime(10, 0));
        $this->sanjai = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        Client::factory()->create(['name' => 'SVA Silks', 'notion_venture' => 'SVA Silks']);
    }

    private function plannerItem(array $attributes = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'source' => ContentItem::SOURCE_REEL,
            'notion_page_id' => uniqid('page'),
            'title' => 'SVA - Sharara Kurti Set',
            'venture' => 'SVA Silks',
            'status' => 'To Be Edited',
            'published_date' => today()->addDay(),
            'editor' => 'Sanjai',
            'assigned_user_id' => $this->sanjai->id,
        ], $attributes));
    }

    private function act(Todo $todo, string $action, array $extra = [])
    {
        return $this->actingAs($this->sanjai)->postJson(route('my.editing.act', $todo), ['action' => $action, ...$extra]);
    }

    public function test_the_page_offers_my_upcoming_planner_videos_and_my_usual_clients(): void
    {
        $this->plannerItem();
        $this->plannerItem(['title' => 'Somebody else’s', 'editor' => 'Gokul', 'assigned_user_id' => null]);
        $this->plannerItem(['title' => 'Already published', 'status' => 'Published']);
        Todo::create(['user_id' => $this->sanjai->id, 'title' => 'Suryas - Cream Bun Montage', 'venture' => 'All / Multiple Clients', 'starts_on' => today()->subDays(3), 'due_on' => today()->subDays(3)]);

        $this->actingAs($this->sanjai)->get(route('my.editing'))
            ->assertOk()
            ->assertSee('SVA - Sharara Kurti Set')
            ->assertSee('Tomorrow')
            ->assertDontSee('Somebody else')
            ->assertDontSee('Already published')
            ->assertSee('Suryas', false);
    }

    public function test_one_tap_from_the_planner_adds_a_linked_reel_and_twice_is_still_one(): void
    {
        $item = $this->plannerItem();

        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['content_item_id' => $item->id])
            ->assertOk()->assertJsonPath('card.publishes', 'Tomorrow')->assertJsonPath('card.kind', 'reel');
        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['content_item_id' => $item->id])
            ->assertJsonPath('existing', true);

        $todo = Todo::sole();
        $this->assertSame($item->id, $todo->content_item_id);
        $this->assertSame('SVA Silks', $todo->venture);
        $this->assertSame(1, $todo->updates()->count(), 'created, in the history like any to-do');
    }

    public function test_typing_a_title_finds_the_client_from_how_it_starts(): void
    {
        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA Tier 2 - Boys Collection'])
            ->assertOk()->assertJsonPath('card.client', 'SVA Silks');
    }

    public function test_a_video_from_start_to_posted_with_a_correction_on_the_way(): void
    {
        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA - Chettinad Cotton Frock']);
        $video = Todo::sole();

        $this->act($video, 'start')->assertJsonPath('card.status', 'started');

        $this->actingAs($this->sanjai)->postJson(route('my.editing.correct', $video), ['title' => 'Change the music'])
            ->assertJsonPath('card.corrections.0.title', 'Change the music');
        $fix = Todo::where('parent_id', $video->id)->sole();

        $this->act($video, 'done')->assertStatus(422);

        $this->act($fix, 'done')->assertJsonPath('card.corrections.0.status', 'completed');
        $this->travel(30)->minutes();
        $this->act($video, 'done')->assertJsonPath('card.status', 'completed')->assertJsonPath('card.working_minutes', 30);

        $this->act($video, 'post', ['kind' => 'reel', 'post_url' => 'https://instagram.com/reel/abc'])
            ->assertJsonPath('card.kind', 'reel')->assertJsonPath('card.post_url', 'https://instagram.com/reel/abc');
        $this->assertTrue($video->fresh()->isPosted());
    }

    public function test_a_correction_on_a_finished_video_puts_it_back_to_working(): void
    {
        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA - Frock']);
        $video = Todo::sole();
        $this->act($video, 'start');
        $this->act($video, 'post', ['kind' => 'reel']);

        $this->actingAs($this->sanjai)->postJson(route('my.editing.correct', $video), ['title' => 'Logo at 0:12'])
            ->assertJsonPath('card.status', 'started')->assertJsonPath('card.posted_at', null);
    }

    public function test_somebody_elses_video_is_not_mine_to_move(): void
    {
        $gokul = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $theirs = Todo::create(['user_id' => $gokul->id, 'assigned_by_id' => $gokul->id, 'title' => 'Theirs', 'venture' => 'SVA Silks', 'starts_on' => today(), 'due_on' => today()]);

        $this->act($theirs, 'start')->assertNotFound();
    }

    public function test_the_small_refresh_pulls_the_reel_planner(): void
    {
        $this->mock(ContentSyncService::class)->shouldReceive('syncSource')->once()->with(ContentItem::SOURCE_REEL)->andReturn(12);

        $this->actingAs($this->sanjai)->postJson(route('my.editing.refresh'))
            ->assertOk()->assertJsonPath('message', 'Reel Planner refreshed — 12 items.');
    }

    public function test_the_eod_page_is_for_admins_and_shows_what_was_crossed(): void
    {
        DailyCheck::create(['date' => today(), 'team' => 'EDITING TEAM', 'items' => [
            ['label' => 'Videos Posted', 'ok' => true, 'note' => null],
            ['label' => 'Final Outputs Moved to Main Disk', 'ok' => false, 'note' => 'SANJAI BRO out pending'],
        ], 'status' => 'Pending', 'message' => 'EDITING TEAM | EOD CHECK']);

        $this->actingAs($this->sanjai)->get(route('daily-checks'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get(route('daily-checks'))
            ->assertOk()->assertSee('1 of 2 done')->assertSee('SANJAI BRO out pending')->assertSee('Pending');
    }
}
