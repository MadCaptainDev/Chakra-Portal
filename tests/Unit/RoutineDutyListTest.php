<?php

namespace Tests\Unit;

use App\Models\ContentAccount;
use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Models\User;
use App\Services\RoutineOccurrenceGenerator;
use App\Support\RoutineDutyList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Grouping open occurrences into duties. One duty four days behind is one
 * row saying it is four days behind -- not four rows.
 */
class RoutineDutyListTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_backlog_collapses_to_one_row_with_the_oldest_first(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'title' => 'Clean the office',
            'starts_on' => '2026-08-21',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        app(RoutineOccurrenceGenerator::class)->run();

        $duties = RoutineDutyList::group($this->openOccurrences());

        $this->assertCount(1, $duties);

        $duty = $duties->first();
        $this->assertSame(5, $duty['outstanding']);          // 21st..25th
        $this->assertTrue($duty['is_overdue']);
        $this->assertSame(4, $duty['days_late']);
        $this->assertSame('2026-08-21', $duty['oldest']->due_on->toDateString());
    }

    public function test_checkpoints_and_subjects_stay_separate_duties(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());
        $routine->checkpoints()->create(['name' => 'Messages', 'sort_order' => 0]);
        $routine->checkpoints()->create(['name' => 'Comments', 'sort_order' => 1]);

        foreach (['Desk A', 'Desk B'] as $name) {
            $account = ContentAccount::factory()->create(['name' => $name]);
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_CONTENT,
                'subject_id' => $account->id,
            ]);
        }

        app(RoutineOccurrenceGenerator::class)->run();

        // 2 checkpoints x 2 accounts, each its own duty -- collapsing happens
        // across dates only, never across what the duty is about.
        $this->assertCount(4, RoutineDutyList::group($this->openOccurrences()));
    }

    public function test_individual_duties_do_not_merge_across_people(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->individual()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $routine->users()->attach([
            User::factory()->employee()->create()->id,
            User::factory()->employee()->create()->id,
        ]);

        app(RoutineOccurrenceGenerator::class)->run();

        $duties = RoutineDutyList::group($this->openOccurrences());

        $this->assertCount(2, $duties);
        $this->assertCount(2, $duties->pluck('assigned_user.id')->unique());
    }

    public function test_late_duties_sort_before_duties_due_today(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $late = Routine::factory()->create([
            'title' => 'Zebra late duty',
            'starts_on' => '2026-08-22',
            'catch_up_days' => 10,
        ]);
        $today = Routine::factory()->create([
            'title' => 'Alpha today duty',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);

        $employee = User::factory()->employee()->create();
        $late->users()->attach($employee);
        $today->users()->attach($employee);

        app(RoutineOccurrenceGenerator::class)->run();

        $duties = RoutineDutyList::group($this->openOccurrences());

        // Alphabetically second, but late, so it leads.
        $this->assertSame('Zebra late duty', $duties->first()['routine']->title);
    }

    /**
     * The actual ask: "Checking Venture Messages" over fifteen accounts is
     * one task with a checklist, not fifteen identical cards.
     */
    public function test_nest_collapses_one_routines_accounts_into_one_task(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'title' => 'Checking Venture Messages',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        foreach (['Venture A', 'Venture B', 'Venture C'] as $name) {
            $account = ContentAccount::factory()->create(['name' => $name]);
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_CONTENT,
                'subject_id' => $account->id,
            ]);
        }

        app(RoutineOccurrenceGenerator::class)->run();

        $duties = RoutineDutyList::group($this->openOccurrences());
        $this->assertCount(3, $duties, 'still three separate completable rows underneath');

        $tasks = RoutineDutyList::nest($duties);

        $this->assertCount(1, $tasks, 'one task on screen');
        $task = $tasks->first();
        $this->assertSame('Checking Venture Messages', $task['routine']->title);
        $this->assertCount(3, $task['subtasks']);
        $this->assertSame(3, $task['total']);
    }

    public function test_nest_keeps_a_plain_duty_as_a_single_subtask_task(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'title' => 'Clean the office',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        app(RoutineOccurrenceGenerator::class)->run();

        $tasks = RoutineDutyList::nest(RoutineDutyList::group($this->openOccurrences()));

        $this->assertCount(1, $tasks);
        $this->assertCount(1, $tasks->first()['subtasks']);
    }

    public function test_nest_keeps_different_checkpoints_as_separate_tasks(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());
        $routine->checkpoints()->create(['name' => 'Messages', 'sort_order' => 0]);
        $routine->checkpoints()->create(['name' => 'Comments', 'sort_order' => 1]);

        foreach (['Desk A', 'Desk B'] as $name) {
            $account = ContentAccount::factory()->create(['name' => $name]);
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_CONTENT,
                'subject_id' => $account->id,
            ]);
        }

        app(RoutineOccurrenceGenerator::class)->run();

        $tasks = RoutineDutyList::nest(RoutineDutyList::group($this->openOccurrences()));

        // Messages and Comments are two different tasks, two accounts each.
        $this->assertCount(2, $tasks);
        $this->assertTrue($tasks->every(fn (array $t) => $t['subtasks']->count() === 2));
    }

    /**
     * @return Collection<int, RoutineOccurrence>
     */
    private function openOccurrences()
    {
        return RoutineOccurrence::query()
            ->with(['routine', 'checkpoint', 'subject', 'assignedUser'])
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->get();
    }
}
