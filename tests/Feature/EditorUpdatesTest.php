<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editor Updates: each person's to-dos for a day and how they moved, read
 * from the history My Editing writes.
 */
class EditorUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private User $sanjai;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanjai = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Boss']);
    }

    private function at(string $time): void
    {
        $this->travelTo(today()->setTimeFromTimeString($time));
    }

    public function test_a_video_moving_through_the_day_reads_as_a_timeline(): void
    {
        $this->at('10:00');
        $id = $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA - Cotton Frock'])->json('card.id');
        $this->at('10:15');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->at('11:00');
        $fixId = $this->postJson(route('my.editing.correct', $id), ['title' => 'Logo cut at the end'])->json('card.corrections.0.id');
        $this->at('11:20');
        $this->postJson(route('my.editing.act', $fixId), ['action' => 'done']);
        $this->at('11:45');
        $this->postJson(route('my.editing.act', $id), ['action' => 'done']);
        $this->at('18:42');
        $this->postJson(route('my.editing.act', $id), ['action' => 'post', 'kind' => 'reel']);

        $this->assertSame(1, TodoUpdate::where('action', TodoUpdate::POSTED)->count(), 'posting is now in the history');

        $this->at('19:00');
        $this->actingAs($this->admin)->get(route('editor-updates'))
            ->assertOk()
            ->assertSeeInOrder(['Sanjai', 'SVA - Cotton Frock', 'Added', 'Started', 'Correction', 'Logo cut at the end', 'Fixed', 'Done', 'Posted', 'Reel'])
            ->assertSee('1h 30m working')
            ->assertSee('1 posted');
    }

    public function test_untouched_to_dos_are_listed_and_past_days_show_where_things_stood(): void
    {
        $this->at('09:00');
        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'Janet - 10 Foods']);
        $this->at('10:00');
        $id = $this->postJson(route('my.editing.store'), ['title' => 'TPC - Grooming Ad'])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);

        // Next day: TPC is finished; Janet is never touched.
        $this->travelTo(today()->addDay()->setTime(12, 0));
        $this->postJson(route('my.editing.act', $id), ['action' => 'done']);

        $this->actingAs($this->admin)->get(route('editor-updates'))
            ->assertOk()
            ->assertSee('Still on the board, no movement today (1)')
            ->assertSee('Janet - 10 Foods')
            ->assertSee('on the board since');

        // Yesterday's page shows TPC as it stood at the end of yesterday.
        $this->get(route('editor-updates', ['date' => today()->subDay()->toDateString()]))
            ->assertOk()
            ->assertSeeInOrder(['TPC - Grooming Ad', 'Working', 'Started']);
    }

    public function test_it_is_admin_only_and_the_old_eod_link_redirects(): void
    {
        $this->actingAs($this->sanjai)->get(route('editor-updates'))->assertForbidden();
        $this->actingAs($this->admin)->get('/daily-checks')->assertRedirect('/editor-updates');
        $this->actingAs($this->admin)->get(route('editor-updates'))->assertOk()->assertSee('Nothing on anyone');
    }
}
