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
        $this->at('12:00');
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(18, 30)->toDateTimeString()]);
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

    public function test_holds_and_next_day_moves_are_on_the_timeline(): void
    {
        $this->at('10:00');
        $id = $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA - Cotton Frock'])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->at('10:30');
        $this->postJson(route('my.editing.act', $id), ['action' => 'hold', 'note' => 'Waiting for client']);
        $this->at('12:00');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->at('18:00');
        $this->postJson(route('my.editing.act', $id), ['action' => 'next']);

        $this->at('19:00');
        $this->actingAs($this->admin)->get(route('editor-updates'))
            ->assertOk()
            ->assertSeeInOrder(['Sanjai', '1 on hold', '1 moved to next day', 'SVA - Cotton Frock', 'Started', 'On hold', 'Waiting for client', 'Resumed', 'Paused', 'Moved to next day'])
            ->assertSee('6h 30m working');
    }

    public function test_one_editors_day_page_shows_their_videos_steps_and_holds(): void
    {
        $this->at('10:00');
        $id = $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'SVA - Cotton Frock'])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $held = $this->postJson(route('my.editing.store'), ['title' => 'TPC - Grooming Ad'])->json('card.id');
        $this->postJson(route('my.editing.act', $held), ['action' => 'start']);
        $this->postJson(route('my.editing.act', $held), ['action' => 'hold', 'note' => 'Waiting for footage']);
        $this->at('11:30');

        // The editor's card on the main page links to their day.
        $this->actingAs($this->admin)->get(route('editor-updates'))->assertOk()
            ->assertSee('editor-updates\\/editor\\/__ID__', false);

        $this->get(route('editor-updates.editor', $this->sanjai))
            ->assertOk()
            ->assertSee('Sanjai')
            ->assertSeeInOrder(['The day so far', 'Every video today', 'SVA - Cotton Frock', 'Started'])
            ->assertSee('On hold')
            ->assertSee('Waiting for footage');

        $this->actingAs($this->sanjai)->get(route('editor-updates.editor', $this->sanjai))->assertForbidden();
    }

    public function test_a_new_day_with_videos_on_the_board_but_no_moves_yet_opens(): void
    {
        $this->at('18:00');
        $this->actingAs($this->sanjai)->postJson(route('my.editing.store'), ['title' => 'Janet - 10 Foods']);

        // Just after midnight: still on the board, nothing touched today.
        $this->travelTo(today()->addDay()->setTime(0, 30));
        $this->actingAs($this->admin)->get(route('editor-updates'))
            ->assertOk()
            ->assertSee('Janet - 10 Foods');
    }

    public function test_it_is_admin_only_and_the_old_eod_link_redirects(): void
    {
        $this->actingAs($this->sanjai)->get(route('editor-updates'))->assertForbidden();
        $this->actingAs($this->admin)->get('/daily-checks')->assertRedirect('/editor-updates');
        $this->actingAs($this->admin)->get(route('editor-updates'))->assertOk()->assertSee('Nothing on anyone')->assertSee(route('editors.index'));
        $this->get('/editors')->assertRedirect('/editor-updates/output');
    }
    public function test_a_manager_sees_only_their_teams_editing_without_the_admin_buttons(): void
    {
        $gokul = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Outsider']);
        $sanjaiManager = $this->sanjai;
        $gokul->managers()->attach($sanjaiManager->id);

        $this->at('10:00');
        $mine = $this->actingAs($gokul)->postJson(route('my.editing.store'), ['title' => 'Gokul - Team video'])->json('card.id');
        $this->postJson(route('my.editing.act', $mine), ['action' => 'start']);
        $this->postJson(route('my.editing.act', $mine), ['action' => 'done']);
        $theirs = $this->actingAs($other)->postJson(route('my.editing.store'), ['title' => 'Outsider - Not yours'])->json('card.id');
        $this->postJson(route('my.editing.act', $theirs), ['action' => 'start']);

        $this->at('11:00');
        $this->actingAs($sanjaiManager)->get(route('editor-updates'))
            ->assertOk()
            ->assertSee('Gokul - Team video')
            ->assertDontSee('Outsider - Not yours')
            ->assertDontSee('✓ Video ready')
            ->assertDontSee(route('editors.index'));
        $this->getJson(route('editor-updates.live'))->assertOk()->assertJsonMissing(['name' => 'Outsider']);
        $this->get(route('editor-updates.editor', $gokul))->assertOk();
        $this->get(route('editor-updates.editor', $other))->assertForbidden();

        // An admin still sees everyone, with the buttons.
        $this->actingAs($this->admin)->get(route('editor-updates'))->assertSee('Outsider - Not yours')->assertSee('✓ Video ready');
    }
}
