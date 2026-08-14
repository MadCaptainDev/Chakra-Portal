<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodoTrackerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Studio Admin']);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Manager Person']);
    }

    private function staff(?User $manager = null, string $name = 'Staff Person'): User
    {
        $staff = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => $name,
        ]);

        if ($manager) {
            $staff->managers()->attach($manager);
        }

        return $staff;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function todo(User $user, array $overrides = []): Todo
    {
        $todo = Todo::create(array_merge([
            'user_id' => $user->id,
            'assigned_by_id' => $user->id,
            'title' => 'Edit the teaser',
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ], $overrides));

        TodoUpdate::record($todo, $user, TodoUpdate::CREATED, ['to_status' => $todo->status]);

        return $todo->fresh();
    }

    // ——— Who may read the board ———

    public function test_an_employee_who_manages_nobody_cannot_open_the_tracker(): void
    {
        $this->actingAs($this->staff())->get(route('todos.index'))->assertForbidden();
    }

    public function test_a_manager_sees_only_their_own_reports_on_the_tracker(): void
    {
        $manager = $this->manager();
        $mine = $this->staff($manager, 'Reports To Me');
        $theirs = $this->staff(null, 'Somebody Else');

        $this->todo($mine, ['title' => 'On my team']);
        $this->todo($theirs, ['title' => 'Not my team']);

        $response = $this->actingAs($manager)->get(route('todos.index'));

        $response->assertOk();
        $response->assertSee('On my team');
        $response->assertDontSee('Not my team');
        $this->assertSame([$mine->id], $response->viewData('team')->pluck('id')->all());
    }

    public function test_an_admin_sees_every_employee_on_the_tracker(): void
    {
        $admin = $this->admin();
        $one = $this->staff(null, 'First Person');
        $two = $this->staff(null, 'Second Person');

        $this->todo($one, ['title' => 'First job']);
        $this->todo($two, ['title' => 'Second job']);

        $response = $this->actingAs($admin)->get(route('todos.index'));

        $response->assertOk();
        $response->assertSee('First job');
        $response->assertSee('Second job');
        $this->assertEqualsCanonicalizing(
            [$one->id, $two->id],
            $response->viewData('team')->pluck('id')->all()
        );
    }

    public function test_a_manager_cannot_change_a_reports_to_do_from_the_tracker(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->todo($staff);

        // Reading the board grants nothing; the write routes are the owner's.
        $this->actingAs($manager)
            ->post(route('my.todos.status', $todo), ['status' => Todo::STATUS_COMPLETED])
            ->assertNotFound();

        $this->actingAs($manager)->post(route('my.todos.defer', $todo))->assertNotFound();

        $this->assertSame(Todo::STATUS_WAITING, $todo->refresh()->status);
    }

    public function test_the_tracker_renders_no_way_to_change_somebody_elses_to_do(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $this->todo($staff);

        $response = $this->actingAs($manager)->get(route('todos.index'));

        $response->assertDontSee(route('my.todos.status', 1), false);
        $response->assertDontSee(route('my.todos.defer', 1), false);
    }

    // ——— The day, and the filters ———

    public function test_the_tracker_opens_on_today_when_no_date_is_given(): void
    {
        $response = $this->actingAs($this->admin())->get(route('todos.index'));

        $this->assertTrue($response->viewData('day')->isToday());
    }

    public function test_an_unreadable_date_in_the_url_falls_back_to_today(): void
    {
        $response = $this->actingAs($this->admin())->get(route('todos.index', ['date' => 'the-day-before']));

        $response->assertOk();
        $this->assertTrue($response->viewData('day')->isToday());
    }

    public function test_narrowing_the_tracker_to_one_person_leaves_the_rest_off(): void
    {
        $admin = $this->admin();
        $one = $this->staff(null, 'First Person');
        $two = $this->staff(null, 'Second Person');

        $this->todo($one, ['title' => 'First job']);
        $this->todo($two, ['title' => 'Second job']);

        $response = $this->actingAs($admin)->get(route('todos.index', ['user' => $one->id]));

        $response->assertOk();
        $response->assertSee('First job');
        $response->assertDontSee('Second job');
    }

    public function test_a_manager_cannot_narrow_the_tracker_to_somebody_outside_their_team(): void
    {
        $manager = $this->manager();
        $this->staff($manager, 'Reports To Me');
        $outsider = $this->staff(null, 'Somebody Else');

        $this->actingAs($manager)
            ->get(route('todos.index', ['user' => $outsider->id]))
            ->assertNotFound();
    }

    public function test_filtering_the_tracker_by_status_leaves_the_other_statuses_off(): void
    {
        $admin = $this->admin();
        $staff = $this->staff(null, 'Filter Person');

        $started = $this->todo($staff, ['title' => 'Already going']);
        $started->moveTo(Todo::STATUS_STARTED, $staff);

        $this->todo($staff, ['title' => 'Not picked up']);

        $response = $this->actingAs($admin)
            ->get(route('todos.index', ['status' => Todo::STATUS_STARTED]));

        $response->assertOk();
        $response->assertSee('Already going');
        $response->assertDontSee('Not picked up');
    }

    public function test_an_unknown_status_in_the_url_is_ignored_rather_than_emptying_the_board(): void
    {
        $admin = $this->admin();
        $staff = $this->staff(null, 'Filter Person');

        $this->todo($staff, ['title' => 'Still listed']);

        $response = $this->actingAs($admin)->get(route('todos.index', ['status' => 'almost']));

        $response->assertOk();
        $response->assertSee('Still listed');
        $this->assertNull($response->viewData('status'));
    }

    public function test_filtering_the_tracker_by_client_leaves_the_other_clients_off(): void
    {
        $admin = $this->admin();
        $staff = $this->staff(null, 'Client Person');

        $client = \App\Models\Client::factory()->create(['name' => 'Vellore Silks', 'notion_venture' => null]);

        $this->todo($staff, ['title' => 'For that one client', 'venture' => $client->name]);
        $this->todo($staff, ['title' => 'For everybody', 'venture' => TimesheetVenture::ALL_CLIENTS]);

        $response = $this->actingAs($admin)
            ->get(route('todos.index', ['venture' => $client->name]));

        $response->assertOk();
        $response->assertSee('For that one client');
        $response->assertDontSee('For everybody');
    }

    public function test_a_client_the_studio_no_longer_has_is_ignored_rather_than_emptying_the_board(): void
    {
        $admin = $this->admin();
        $staff = $this->staff(null, 'Client Person');

        $this->todo($staff, ['title' => 'Still listed']);

        $response = $this->actingAs($admin)->get(route('todos.index', ['venture' => 'Long Gone Ltd']));

        $response->assertOk();
        $response->assertSee('Still listed');
        $this->assertNull($response->viewData('venture'));
    }

    public function test_the_tracker_says_who_asked_for_work_somebody_did_not_write_themselves(): void
    {
        $admin = $this->admin();
        $producer = $this->staff(null, 'The Producer');
        $editor = $this->staff(null, 'The Editor');

        $this->todo($editor, ['assigned_by_id' => $producer->id, 'title' => 'Cut the teaser']);

        $this->actingAs($admin)->get(route('todos.index'))->assertSee('for The Editor');
    }

    // ——— A manager's verdict ———

    private function finished(User $user, array $overrides = []): Todo
    {
        $todo = $this->todo($user, $overrides);
        $todo->moveTo(Todo::STATUS_COMPLETED, $user);

        return $todo->fresh();
    }

    public function test_a_manager_can_check_off_a_report_s_finished_to_do(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->finished($staff);

        $this->assertTrue($todo->awaitsReview());

        $this->actingAs($manager)
            ->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED])
            ->assertRedirect();

        $todo->refresh();

        $this->assertTrue($todo->isApproved());
        $this->assertSame($manager->id, $todo->reviewed_by_id);
        $this->assertNotNull($todo->reviewed_at);
    }

    public function test_sending_a_to_do_back_needs_a_reason(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->finished($staff);

        $this->actingAs($manager)
            ->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_REJECTED])
            ->assertSessionHasErrors('review_note');

        $this->assertNull($todo->refresh()->review_state);
    }

    public function test_sending_a_to_do_back_puts_it_on_the_board_again_as_started(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->finished($staff);

        $this->actingAs($manager)->post(route('todos.review', $todo), [
            'review_state' => Todo::REVIEW_REJECTED,
            'review_note' => 'The grade is off in the second half.',
        ])->assertRedirect();

        $todo->refresh();

        // Left in the settled pile with a red flag, it is work nobody scrolls
        // far enough to find.
        $this->assertSame(Todo::STATUS_STARTED, $todo->status);
        $this->assertNull($todo->closed_on);
        $this->assertTrue($todo->wasSentBack());
        $this->assertSame('The grade is off in the second half.', $todo->review_note);
    }

    public function test_a_verdict_is_written_to_the_to_dos_history(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->finished($staff);

        $this->actingAs($manager)->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED]);

        $this->assertDatabaseHas('todo_updates', [
            'todo_id' => $todo->id,
            'user_id' => $manager->id,
            'action' => TodoUpdate::REVIEWED,
        ]);
    }

    public function test_changing_a_to_do_after_it_was_checked_clears_the_verdict(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->finished($staff);

        $this->actingAs($manager)->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED]);
        $this->assertTrue($todo->refresh()->isApproved());

        // The verdict was about the work as it stood; reopening makes it a
        // statement about something that has not happened yet.
        $this->actingAs($staff)->post(route('my.todos.status', $todo), ['status' => Todo::STATUS_STARTED]);

        $todo->refresh();

        $this->assertNull($todo->review_state);
        $this->assertNull($todo->reviewed_by_id);
        $this->assertNull($todo->reviewed_at);
    }

    public function test_somebody_who_does_not_manage_the_person_cannot_give_a_verdict(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $stranger = $this->staff(null, 'Nobody In Particular');

        $todo = $this->finished($staff);

        $this->actingAs($stranger)
            ->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED])
            ->assertForbidden();

        $this->assertNull($todo->refresh()->review_state);
    }

    public function test_an_employee_cannot_check_off_their_own_work(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->finished($staff);

        // Marking your own work done is a claim; somebody else saying so is
        // what makes the board worth reading.
        $this->actingAs($staff)
            ->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED])
            ->assertForbidden();

        $this->assertNull($todo->refresh()->review_state);
    }

    public function test_any_of_an_employees_managers_can_give_the_verdict(): void
    {
        $producer = $this->manager();
        $lead = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'The Studio Lead']);

        $staff = $this->staff($producer);
        $staff->managers()->attach($lead);

        $todo = $this->finished($staff);

        $this->actingAs($lead)
            ->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED])
            ->assertRedirect();

        $this->assertSame($lead->id, $todo->refresh()->reviewed_by_id);
    }

    public function test_an_employee_with_two_managers_shows_on_both_of_their_trackers(): void
    {
        $producer = $this->manager();
        $lead = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'The Studio Lead']);

        $staff = $this->staff($producer, 'Shared Report');
        $staff->managers()->attach($lead);

        $this->todo($staff, ['title' => 'Shared job']);

        $this->actingAs($producer)->get(route('todos.index'))->assertSee('Shared job');
        $this->actingAs($lead)->get(route('todos.index'))->assertSee('Shared job');
    }

    public function test_an_unfinished_to_do_cannot_be_checked_off(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $todo = $this->todo($staff);

        $this->actingAs($manager)
            ->post(route('todos.review', $todo), ['review_state' => Todo::REVIEW_APPROVED])
            ->assertRedirect();

        $this->assertNull($todo->refresh()->review_state);
    }

    public function test_a_multi_day_to_do_appears_on_the_tracker_on_every_day_it_spans(): void
    {
        $admin = $this->admin();
        $staff = $this->staff(null, 'Span Person');

        $this->todo($staff, [
            'title' => 'Three day edit',
            'starts_on' => '2026-08-12',
            'due_on' => '2026-08-14',
        ]);

        foreach (['2026-08-12', '2026-08-13', '2026-08-14'] as $date) {
            $this->actingAs($admin)
                ->get(route('todos.index', ['date' => $date]))
                ->assertSee('Three day edit');
        }
    }
}
