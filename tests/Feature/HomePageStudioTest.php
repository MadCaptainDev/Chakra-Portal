<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\StudioNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage's What we do explorer and "Where the hours go" pipeline.
 */
class HomePageStudioTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $type, string $task, int $minutes, string $day = '2026-09-10'): void
    {
        TimesheetEntry::create([
            'user_id' => $this->user->id,
            'worked_on' => $day,
            'task' => $task,
            'task_type' => $type,
            'minutes' => $minutes,
        ]);
    }

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_hours_are_bucketed_from_task_types_and_free_text(): void
    {
        $this->log('shooting', 'Shoot', 600);
        $this->log('editing', 'Reel cut', 900);
        $this->log('posting', 'Posting', 30);
        // "Other" tasks are sorted by what they say.
        $this->log('other', 'DM checking', 30);
        $this->log('other', 'Excel sheet update', 120);
        $this->log('other', 'Learning', 300);

        $numbers = StudioNumbers::get();

        $this->assertSame(['shoot' => 10, 'edit' => 15, 'post' => 1, 'track' => 2], $numbers['hours']);
        $this->assertSame(1, $numbers['shoot']['days']);
    }

    public function test_the_page_shows_the_explorer_and_the_real_hours(): void
    {
        $this->log('shooting', 'Shoot', 1_742 * 60, now()->subWeek()->toDateString());
        $this->log('editing', 'Edit', 2_707 * 60, now()->subMonth()->toDateString());
        ContentItem::factory()->count(3)->create(['source' => ContentItem::SOURCE_REEL, 'status' => 'Published']);

        $this->get('/')
            ->assertOk()
            ->assertSee('What we do')
            ->assertSee('Tap a service and watch it work.')
            ->assertSee('Short-form video')
            ->assertSee('How we work')
            ->assertSee('Where the hours go.')
            ->assertSee('1,742 h')
            ->assertSee('2,707 h')
            ->assertSee('Research is not on the clock', false);
    }

    public function test_it_works_with_no_records_at_all(): void
    {
        $this->get('/')->assertOk()->assertSee('Where the hours go.');
    }
}
