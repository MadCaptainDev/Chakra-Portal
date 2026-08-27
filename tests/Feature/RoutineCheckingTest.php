<?php

namespace Tests\Feature;

use App\Http\Controllers\RoutineController;
use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\RoutineOccurrenceGenerator;
use App\Support\RoutineDutyList;
use Database\Seeders\RoutineDutyPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The human half of routines: a duty that needs no account, a routine that
 * says when it has stopped working, and a checking screen that answers
 * "who still owes what".
 */
class RoutineCheckingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_routine_can_be_created_without_touching_accounts(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('routines.store'), [
                'title' => 'Clean the office',
                'schedule_type' => Routine::SCHEDULE_EVERY_N_DAYS,
                'schedule_interval' => 2,
                'completion_mode' => Routine::MODE_SHARED,
                'subject_scope' => RoutineController::SCOPE_NONE,
                'starts_on' => today()->toDateString(),
                'is_active' => 1,
                'user_ids' => [$admin->id],
            ])
            ->assertRedirect(route('routines.index'));

        $routine = Routine::query()->where('title', 'Clean the office')->firstOrFail();

        $this->assertNull($routine->subject_type);
        $this->assertFalse($routine->isAccountScoped());
        $this->assertNull($routine->generationWarning());
    }

    /**
     * The silent-death case, refused at the door.
     */
    public function test_account_scope_without_any_account_is_rejected(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('routines.store'), [
                'title' => 'Scoped but empty',
                'schedule_type' => Routine::SCHEDULE_DAILY,
                'completion_mode' => Routine::MODE_SHARED,
                'subject_scope' => RoutineController::SCOPE_ACCOUNTS,
                'starts_on' => today()->toDateString(),
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('social_account_ids');

        $this->assertDatabaseMissing('routines', ['title' => 'Scoped but empty']);
    }

    /**
     * Ticking account boxes on a routine set to "just a duty" must not
     * quietly turn it into an account routine.
     */
    public function test_plain_duty_ignores_posted_account_ids(): void
    {
        $admin = User::factory()->create();
        $content = ContentAccount::factory()->create(['name' => 'Venture Desk']);

        $this->actingAs($admin)
            ->post(route('routines.store'), [
                'title' => 'Move final output to hard disk',
                'schedule_type' => Routine::SCHEDULE_DAILY,
                'completion_mode' => Routine::MODE_SHARED,
                'subject_scope' => RoutineController::SCOPE_NONE,
                'starts_on' => today()->toDateString(),
                'is_active' => 1,
                'content_account_ids' => [$content->id],
            ])
            ->assertRedirect(route('routines.index'));

        $routine = Routine::query()->where('title', 'Move final output to hard disk')->firstOrFail();

        $this->assertNull($routine->subject_type);
        $this->assertSame(0, $routine->subjects()->count());
    }

    public function test_seeded_dm_routine_reports_that_it_is_not_generating(): void
    {
        User::factory()->create();
        $this->seed(RoutineDutyPlansSeeder::class);

        $dm = Routine::query()->where('title', 'Venture Direct Messages and Comments')->firstOrFail();

        $this->assertNotNull($dm->generationWarning());
        $this->assertStringContainsString('no accounts are selected', $dm->generationWarning());

        // Attaching a live account clears it.
        $account = ContentAccount::factory()->create(['name' => 'Venture Desk']);
        $dm->subjects()->create([
            'subject_type' => Routine::SUBJECT_CONTENT,
            'subject_id' => $account->id,
        ]);

        $this->assertNull($dm->fresh()->generationWarning());
    }

    public function test_warning_fires_when_every_account_has_been_revoked(): void
    {
        $routine = Routine::factory()->create(['subject_type' => Routine::SUBJECT_ACCOUNTS]);
        $account = $this->socialAccount('gone');
        $routine->subjects()->create([
            'subject_type' => Routine::SUBJECT_SOCIAL,
            'subject_id' => $account->id,
        ]);

        $this->assertNull($routine->fresh()->generationWarning());

        $account->forceFill(['status' => SocialAccount::STATUS_REVOKED, 'access_token' => null])->save();

        $this->assertStringContainsString(
            'deleted or revoked',
            (string) $routine->fresh()->generationWarning(),
        );
    }

    public function test_employee_can_tick_several_duties_in_one_request(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $employee = User::factory()->employee()->create();

        foreach (['Clean the office', 'Move output to disk', 'Verify training'] as $title) {
            $routine = Routine::factory()->create([
                'title' => $title,
                'starts_on' => '2026-08-25',
                'catch_up_days' => 0,
            ]);
            $routine->users()->attach($employee);
        }

        app(RoutineOccurrenceGenerator::class)->run();
        $this->assertSame(3, RoutineOccurrence::count());

        $keys = RoutineOccurrence::with('routine')->get()
            ->map(fn (RoutineOccurrence $o) => RoutineDutyList::keyFor($o))
            ->all();

        $this->actingAs($employee)
            ->post(route('my.routines.complete-many'), ['duties' => $keys])
            ->assertRedirect(route('my.routines'));

        $this->assertSame(3, RoutineOccurrence::where('status', RoutineOccurrence::STATUS_DONE)->count());
        $this->assertSame(0, RoutineOccurrence::where('status', RoutineOccurrence::STATUS_OPEN)->count());
    }

    /**
     * You cleaned the office. You did not clean it once per missed day, but
     * the backlog is settled and the record says who settled it.
     */
    public function test_ticking_a_late_duty_closes_its_whole_backlog(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $employee = User::factory()->employee()->create();
        $routine = Routine::factory()->create([
            'title' => 'Clean the office',
            'starts_on' => '2026-08-21',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach($employee);

        app(RoutineOccurrenceGenerator::class)->run();
        $this->assertSame(5, RoutineOccurrence::count());

        $key = RoutineDutyList::keyFor(RoutineOccurrence::with('routine')->firstOrFail());

        $this->actingAs($employee)
            ->post(route('my.routines.complete-many'), ['duties' => [$key]])
            ->assertRedirect(route('my.routines'));

        $this->assertSame(0, RoutineOccurrence::where('status', RoutineOccurrence::STATUS_OPEN)->count());
        $this->assertSame(
            5,
            RoutineOccurrence::where('completed_by', $employee->id)->count(),
        );
    }

    public function test_a_forged_duty_key_completes_nothing(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $employee = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();

        $routine = Routine::factory()->create(['starts_on' => '2026-08-25', 'catch_up_days' => 0]);
        $routine->users()->attach($other);

        app(RoutineOccurrenceGenerator::class)->run();

        $key = RoutineDutyList::keyFor(RoutineOccurrence::with('routine')->firstOrFail());

        // A person with no permission on the routine posts its real key.
        $this->actingAs($employee)
            ->post(route('my.routines.complete-many'), ['duties' => [$key]])
            ->assertRedirect(route('my.routines'));

        $this->assertSame(1, RoutineOccurrence::where('status', RoutineOccurrence::STATUS_OPEN)->count());
    }

    public function test_checking_board_groups_shared_and_individual_duties(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $priya = User::factory()->employee()->create(['name' => 'Priya Editor']);

        $shared = Routine::factory()->create([
            'title' => 'Clean the office',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $shared->users()->attach($priya);

        $individual = Routine::factory()->individual()->create([
            'title' => 'Verify training',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $individual->users()->attach($priya);

        app(RoutineOccurrenceGenerator::class)->run();

        $this->actingAs($admin)
            ->get(route('routines.checking'))
            ->assertOk()
            ->assertSee('Anyone on the team')
            ->assertSee('Priya Editor')
            ->assertSee('Clean the office')
            ->assertSee('Verify training');
    }

    /**
     * The actual ask: an account-scoped routine shows once, as one task with
     * its accounts nested underneath -- not once per account.
     */
    public function test_account_scoped_routine_shows_as_one_task_on_both_screens(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $employee = User::factory()->employee()->create();

        $routine = Routine::factory()->create([
            'title' => 'Checking Venture Messages',
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);
        $routine->users()->attach($employee);

        foreach (['Venture A', 'Venture B', 'Venture C'] as $name) {
            $account = ContentAccount::factory()->create(['name' => $name]);
            $routine->subjects()->create([
                'subject_type' => Routine::SUBJECT_CONTENT,
                'subject_id' => $account->id,
            ]);
        }

        app(RoutineOccurrenceGenerator::class)->run();
        $this->assertSame(3, RoutineOccurrence::count());

        $myRoutines = $this->actingAs($employee)->get(route('my.routines'));
        $myRoutines->assertOk();
        // Once as the due task's own headline, once more in "Coming up"
        // (tomorrow's occurrence, a separate section) -- not a third time,
        // which is what one card per account would produce.
        $this->assertSame(
            2,
            substr_count($myRoutines->getContent(), 'Checking Venture Messages'),
            'the routine title should appear once as the task headline, not once per account',
        );
        foreach (['Venture A', 'Venture B', 'Venture C'] as $name) {
            $myRoutines->assertSee($name);
        }

        $checking = $this->actingAs($admin)->get(route('routines.checking'));
        $checking->assertOk();
        $this->assertSame(
            1,
            substr_count($checking->getContent(), 'Checking Venture Messages'),
            'the admin board should also show it once, not once per account',
        );
        foreach (['Venture A', 'Venture B', 'Venture C'] as $name) {
            $checking->assertSee($name);
        }
    }

    /**
     * The three numbers the redesigned board leads with -- Outstanding
     * counts every open row regardless of how late, Late is the subset
     * actually overdue as of the day being viewed (not real today, since a
     * manager can browse the day-nav to a past day), Settled is what
     * actually got closed that day.
     */
    public function test_the_boards_stat_row_counts_outstanding_late_and_settled(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $employee = User::factory()->employee()->create();

        $onTime = Routine::factory()->create(['title' => 'Due today', 'starts_on' => '2026-08-25', 'catch_up_days' => 0]);
        $onTime->users()->attach($employee);

        $late = Routine::factory()->create(['title' => 'Two days late', 'starts_on' => '2026-08-23', 'catch_up_days' => 5]);
        $late->users()->attach($employee);

        app(RoutineOccurrenceGenerator::class)->run();

        // 1 (due today) + 3 (23rd, 24th, 25th) = 4 open rows; the late
        // routine's 23rd and 24th are the 2 that are actually overdue.
        $this->assertSame(4, RoutineOccurrence::where('status', RoutineOccurrence::STATUS_OPEN)->count());

        $response = $this->actingAs($admin)->get(route('routines.checking'))->assertOk();

        $response->assertViewHas('outstandingCount', 4);
        $response->assertViewHas('lateCount', 2);
    }

    public function test_checking_board_shows_a_routine_that_has_stopped_generating(): void
    {
        $admin = User::factory()->create();

        Routine::factory()->create([
            'title' => 'Venture Direct Messages and Comments',
            'subject_type' => Routine::SUBJECT_ACCOUNTS,
        ]);

        $this->actingAs($admin)
            ->get(route('routines.checking'))
            ->assertOk()
            ->assertSee('Venture Direct Messages and Comments')
            ->assertSee('no accounts are selected');
    }

    public function test_admin_can_close_a_whole_backlog_from_the_board(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-22',
            'catch_up_days' => 10,
        ]);
        $routine->users()->attach(User::factory()->employee()->create());

        app(RoutineOccurrenceGenerator::class)->run();
        $this->assertSame(4, RoutineOccurrence::count());

        $oldest = RoutineOccurrence::orderBy('due_on')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('routines.checking.complete', $oldest), ['all' => 1])
            ->assertRedirect();

        $this->assertSame(0, RoutineOccurrence::where('status', RoutineOccurrence::STATUS_OPEN)->count());
    }

    /**
     * Generation belongs to the once-a-day middleware. Before this, both
     * My Routines and the calendar ran a full generate on every page view.
     */
    public function test_pages_do_not_generate_when_the_day_is_already_claimed(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $admin = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $routine = Routine::factory()->create([
            'starts_on' => '2026-08-25',
            'catch_up_days' => 0,
        ]);
        $routine->users()->attach($employee);

        // Pretend the scheduler already ran today.
        Cache::add('routines-generated-on-'.today()->toDateString(), true, now()->addDay());

        $this->actingAs($employee)->get(route('my.routines'))->assertOk();
        $this->actingAs($admin)->get(route('routines.calendar'))->assertOk();

        $this->assertSame(0, RoutineOccurrence::count());
    }

    private function socialAccount(string $username, string $platformUserId = '10'): SocialAccount
    {
        $client = Client::factory()->create();

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => $username,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-test', 'connected_at' => now()])->save();

        return $account->fresh();
    }
}
