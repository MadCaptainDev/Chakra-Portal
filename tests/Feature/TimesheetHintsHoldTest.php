<?php

namespace Tests\Feature;

use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Time a video sits on Hold is not editing time: the timesheet hint offers
 * one row per working stretch, so start–end never spans the hold.
 */
class TimesheetHintsHoldTest extends TestCase
{
    use RefreshDatabase;

    private function editedAroundAHold(User $editor): void
    {
        $this->travelTo(today()->setTime(10, 0));
        $id = $this->actingAs($editor)->postJson(route('my.editing.store'), ['title' => 'Squad - Site Walk'])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start'])->assertOk();
        $this->travelTo(today()->setTime(11, 20));
        $this->postJson(route('my.editing.act', $id), ['action' => 'hold', 'note' => 'Waiting for client footage'])->assertOk();
        $this->travelTo(today()->setTime(14, 5));
        $this->postJson(route('my.editing.act', $id), ['action' => 'start'])->assertOk();
        $this->travelTo(today()->setTime(15, 0));
        $this->postJson(route('my.editing.act', $id), ['action' => 'done'])->assertOk();
    }

    public function test_hold_splits_the_video_into_two_working_rows(): void
    {
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->editedAroundAHold($editor);

        $rows = array_map(fn ($h) => [$h['start'], $h['end'], $h['minutes']], TimesheetHints::for($editor, today()));

        $this->assertSame([['10:00', '11:20', 80], ['14:05', '15:00', 55]], $rows);
        $this->assertSame(135, TimesheetEntry::minutesBetween('10:00', '11:20') + TimesheetEntry::minutesBetween('14:05', '15:00'));
    }

    public function test_logging_the_first_stretch_still_offers_the_second(): void
    {
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->editedAroundAHold($editor);

        TimesheetEntry::create([
            'user_id' => $editor->id, 'worked_on' => today(), 'task' => 'Edit — Squad - Site Walk',
            'task_type' => TimesheetEntry::TASK_EDITING, 'started_at' => '10:00', 'ended_at' => '11:20', 'minutes' => 80,
        ]);

        $hints = TimesheetHints::for($editor, today());

        $this->assertTrue($hints[0]['logged']);
        $this->assertFalse($hints[1]['logged']);
    }

    public function test_an_untimed_entry_for_the_video_still_counts_as_logged(): void
    {
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->editedAroundAHold($editor);

        TimesheetEntry::create([
            'user_id' => $editor->id, 'worked_on' => today(), 'task' => 'Edit — Squad - Site Walk',
            'task_type' => TimesheetEntry::TASK_EDITING, 'minutes' => 135,
        ]);

        $this->assertSame([true, true], array_column(TimesheetHints::for($editor, today()), 'logged'));
    }
}
