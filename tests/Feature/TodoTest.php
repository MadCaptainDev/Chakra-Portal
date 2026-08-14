<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodoTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $name = 'Todo Person'): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function todo(User $user, array $overrides = []): Todo
    {
        $todo = Todo::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Edit the teaser',
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ], $overrides));

        TodoUpdate::record($todo, $user, TodoUpdate::CREATED, ['to_status' => $todo->status]);

        return $todo->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Edit the teaser',
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ], $overrides);
    }

    /** The board as the controller builds it, for one person on one day. */
    private function boardFor(User $user, ?string $date = null): \Illuminate\Support\Collection
    {
        return $this->actingAs($user)
            ->get(route('my.todos', array_filter(['date' => $date])))
            ->viewData('todos');
    }

    // ——— What is on the board for a day ———

    public function test_a_to_do_whose_first_day_is_the_day_being_viewed_is_on_the_board(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-14', 'due_on' => '2026-08-14']);

        $this->assertTrue($this->boardFor($user, '2026-08-14')->contains('id', $todo->id));
    }

    public function test_a_to_do_starting_tomorrow_is_not_on_todays_board(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-15', 'due_on' => '2026-08-15']);

        $this->assertFalse($this->boardFor($user, '2026-08-14')->contains('id', $todo->id));
    }

    public function test_a_to_do_that_spans_three_days_shows_on_every_one_of_them_until_it_is_finished(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-12', 'due_on' => '2026-08-14']);

        foreach (['2026-08-12', '2026-08-13', '2026-08-14'] as $date) {
            $this->assertTrue(
                $this->boardFor($user, $date)->contains('id', $todo->id),
                "Expected the to-do on {$date}."
            );
        }
    }

    public function test_a_to_do_finished_on_tuesday_shows_on_tuesday_and_not_on_wednesday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00'));

        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-11', 'due_on' => '2026-08-11']);
        $todo->moveTo(Todo::STATUS_COMPLETED, $user);

        $this->assertTrue($this->boardFor($user, '2026-08-11')->contains('id', $todo->id));
        $this->assertFalse($this->boardFor($user, '2026-08-12')->contains('id', $todo->id));

        Carbon::setTestNow();
    }

    public function test_a_to_do_that_was_open_on_monday_still_shows_on_monday_after_it_is_finished_on_friday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-10', 'due_on' => '2026-08-14']);
        $todo->moveTo(Todo::STATUS_COMPLETED, $user);

        // It genuinely was open that day; a board that hides it loses the work.
        $this->assertTrue($this->boardFor($user, '2026-08-10')->contains('id', $todo->id));

        Carbon::setTestNow();
    }

    public function test_a_to_do_left_unfinished_still_shows_on_todays_board_days_after_it_was_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00'));

        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-10', 'due_on' => '2026-08-11']);

        $board = $this->boardFor($user);

        $this->assertTrue($board->contains('id', $todo->id));
        $this->assertTrue($board->firstWhere('id', $todo->id)->isOverdueOn(today()));

        Carbon::setTestNow();
    }

    public function test_a_to_do_starting_on_the_last_day_of_a_month_is_still_on_the_board_the_next_morning(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-31', 'due_on' => '2026-08-31']);

        $this->assertTrue($this->boardFor($user, '2026-08-31')->contains('id', $todo->id));
        $this->assertTrue($this->boardFor($user, '2026-09-01')->contains('id', $todo->id));
    }

    public function test_a_past_day_shows_the_status_a_to_do_had_that_day_and_not_the_one_it_has_now(): void
    {
        $user = $this->employee();

        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00'));
        $todo = $this->todo($user, ['starts_on' => '2026-08-12', 'due_on' => '2026-08-14']);

        Carbon::setTestNow(Carbon::parse('2026-08-14 15:00:00'));
        $todo->moveTo(Todo::STATUS_STARTED, $user);

        $onTwelfth = $this->boardFor($user, '2026-08-12')->firstWhere('id', $todo->id);
        $onFourteenth = $this->boardFor($user, '2026-08-14')->firstWhere('id', $todo->id);

        $this->assertSame(Todo::STATUS_WAITING, $onTwelfth->statusOn(Carbon::parse('2026-08-12')));
        $this->assertSame(Todo::STATUS_STARTED, $onFourteenth->statusOn(Carbon::parse('2026-08-14')));

        Carbon::setTestNow();
    }

    // ——— Writing one down ———

    public function test_an_employee_can_write_down_a_to_do_and_it_starts_out_waiting(): void
    {
        $user = $this->employee();

        $this->actingAs($user)->post(route('my.todos.store'), $this->payload())->assertRedirect();

        $this->assertDatabaseHas('todos', [
            'user_id' => $user->id,
            'title' => 'Edit the teaser',
            'status' => Todo::STATUS_WAITING,
        ]);
    }

    public function test_a_to_do_with_no_last_day_given_finishes_on_the_day_it_starts(): void
    {
        $user = $this->employee();

        $this->actingAs($user)
            ->post(route('my.todos.store'), $this->payload(['starts_on' => '2026-08-20', 'due_on' => null]))
            ->assertSessionHasNoErrors();

        $todo = Todo::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('2026-08-20', $todo->due_on->toDateString());
    }

    public function test_a_to_do_cannot_finish_before_it_starts(): void
    {
        $user = $this->employee();

        $this->actingAs($user)
            ->post(route('my.todos.store'), $this->payload([
                'starts_on' => '2026-08-20',
                'due_on' => '2026-08-19',
            ]))
            ->assertSessionHasErrors('due_on');
    }

    public function test_writing_a_to_do_down_is_itself_recorded(): void
    {
        $user = $this->employee();

        $this->actingAs($user)->post(route('my.todos.store'), $this->payload());

        $todo = Todo::where('user_id', $user->id)->firstOrFail();

        $this->assertDatabaseHas('todo_updates', [
            'todo_id' => $todo->id,
            'user_id' => $user->id,
            'action' => TodoUpdate::CREATED,
            'to_status' => Todo::STATUS_WAITING,
        ]);
    }

    // ——— Moving to the next day ———

    public function test_moving_a_to_do_to_the_next_day_pushes_its_due_date_and_leaves_the_day_it_started_alone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 11:00:00'));

        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-14', 'due_on' => '2026-08-14']);

        $this->actingAs($user)->post(route('my.todos.defer', $todo))->assertRedirect();

        $todo->refresh();

        $this->assertSame('2026-08-15', $todo->due_on->toDateString());
        $this->assertSame('2026-08-14', $todo->starts_on->toDateString());

        Carbon::setTestNow();
    }

    public function test_moving_an_overdue_to_do_lands_it_on_tomorrow_rather_than_on_another_day_already_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 11:00:00'));

        $user = $this->employee();
        $todo = $this->todo($user, ['starts_on' => '2026-08-10', 'due_on' => '2026-08-12']);

        $this->actingAs($user)->post(route('my.todos.defer', $todo));

        $this->assertSame('2026-08-15', $todo->refresh()->due_on->toDateString());

        Carbon::setTestNow();
    }

    public function test_a_started_to_do_is_still_started_after_it_is_moved_to_the_next_day(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user);
        $todo->moveTo(Todo::STATUS_STARTED, $user);

        $this->actingAs($user)->post(route('my.todos.defer', $todo));

        $this->assertSame(Todo::STATUS_STARTED, $todo->refresh()->status);
    }

    public function test_a_finished_to_do_cannot_be_moved_to_the_next_day(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user);
        $todo->moveTo(Todo::STATUS_COMPLETED, $user);

        $was = $todo->refresh()->due_on->toDateString();

        $this->actingAs($user)->post(route('my.todos.defer', $todo))->assertRedirect();

        $this->assertSame($was, $todo->refresh()->due_on->toDateString());
        $this->assertDatabaseMissing('todo_updates', [
            'todo_id' => $todo->id,
            'action' => TodoUpdate::MOVED,
        ]);
    }

    public function test_a_to_do_moved_three_times_says_so_without_a_column_counting_it(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->post(route('my.todos.defer', $todo));
        }

        $this->assertArrayNotHasKey('deferrals_count', $todo->refresh()->getAttributes());

        $onBoard = $this->boardFor($user)->firstWhere('id', $todo->id);

        $this->assertSame(3, (int) $onBoard->deferrals_count);
    }

    // ——— The history ———

    public function test_every_status_change_is_written_to_the_to_dos_history_with_the_time_it_happened(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:42:00'));

        $user = $this->employee();
        $todo = $this->todo($user);

        $this->actingAs($user)->post(route('my.todos.status', $todo), ['status' => Todo::STATUS_STARTED]);

        $update = TodoUpdate::where('todo_id', $todo->id)->where('action', TodoUpdate::STATUS)->firstOrFail();

        $this->assertSame(Todo::STATUS_WAITING, $update->from_status);
        $this->assertSame(Todo::STATUS_STARTED, $update->to_status);
        $this->assertSame('2026-08-14 10:42:00', $update->created_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_the_history_of_a_to_do_reads_as_one_group_per_day(): void
    {
        $user = $this->employee();

        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00'));
        $todo = $this->todo($user, ['starts_on' => '2026-08-12', 'due_on' => '2026-08-16']);
        $todo->moveTo(Todo::STATUS_STARTED, $user);

        Carbon::setTestNow(Carbon::parse('2026-08-13 17:30:00'));
        $todo->moveTo(Todo::STATUS_BLOCKED, $user, 'Waiting on the client');

        $byDay = $todo->fresh()->updates->groupBy(fn (TodoUpdate $u) => $u->created_at->toDateString());

        $this->assertSame(['2026-08-12', '2026-08-13'], $byDay->keys()->all());
        $this->assertCount(2, $byDay['2026-08-12']);
        $this->assertCount(1, $byDay['2026-08-13']);

        Carbon::setTestNow();
    }

    public function test_marking_a_to_do_blocked_requires_a_reason(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user);

        $this->actingAs($user)
            ->post(route('my.todos.status', $todo), ['status' => Todo::STATUS_BLOCKED])
            ->assertSessionHasErrors('note');

        $this->assertSame(Todo::STATUS_WAITING, $todo->refresh()->status);
    }

    public function test_reopening_a_finished_to_do_puts_it_back_on_the_board(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00'));

        $user = $this->employee();
        $todo = $this->todo($user);
        $todo->moveTo(Todo::STATUS_COMPLETED, $user);

        $this->assertNotNull($todo->refresh()->closed_on);

        $this->actingAs($user)->post(route('my.todos.status', $todo), ['status' => Todo::STATUS_STARTED]);

        $this->assertNull($todo->refresh()->closed_on);
        $this->assertTrue($this->boardFor($user, '2026-08-15')->contains('id', $todo->id));

        Carbon::setTestNow();
    }

    public function test_deleting_the_person_who_wrote_a_history_entry_keeps_the_entry(): void
    {
        $owner = $this->employee('Owner');
        $other = $this->employee('Someone Else');

        $todo = $this->todo($owner);
        $todo->moveTo(Todo::STATUS_STARTED, $other);

        $other->delete();

        $this->assertDatabaseHas('todo_updates', [
            'todo_id' => $todo->id,
            'to_status' => Todo::STATUS_STARTED,
            'user_id' => null,
        ]);
    }

    // ——— Whose to-do it is ———

    public function test_an_employee_cannot_open_or_change_somebody_elses_to_do(): void
    {
        $owner = $this->employee('Owner');
        $intruder = $this->employee('Intruder');

        $todo = $this->todo($owner);

        $this->actingAs($intruder)
            ->post(route('my.todos.status', $todo), ['status' => Todo::STATUS_COMPLETED])
            ->assertNotFound();

        $this->actingAs($intruder)->post(route('my.todos.defer', $todo))->assertNotFound();
        $this->actingAs($intruder)->delete(route('my.todos.destroy', $todo))->assertNotFound();
        $this->actingAs($intruder)
            ->put(route('my.todos.update', $todo), $this->payload(['title' => 'Mine now']))
            ->assertNotFound();

        $this->assertSame(Todo::STATUS_WAITING, $todo->refresh()->status);
        $this->assertSame('Edit the teaser', $todo->title);
    }

    public function test_an_employee_only_sees_their_own_to_dos_on_their_board(): void
    {
        $mine = $this->employee('Mine');
        $theirs = $this->employee('Theirs');

        $this->todo($mine, ['title' => 'My own job']);
        $this->todo($theirs, ['title' => 'Their job']);

        $board = $this->boardFor($mine);

        $this->assertCount(1, $board);
        $this->assertSame('My own job', $board->first()->title);
    }

    public function test_an_employee_cannot_set_a_status_the_system_does_not_know(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user);

        $this->actingAs($user)
            ->post(route('my.todos.status', $todo), ['status' => 'nearly_done'])
            ->assertSessionHasErrors('status');

        $this->assertSame(Todo::STATUS_WAITING, $todo->refresh()->status);
    }

    public function test_an_employee_cannot_set_the_day_a_to_do_was_finished_by_posting_it(): void
    {
        $user = $this->employee();
        $todo = $this->todo($user);

        $this->actingAs($user)->put(route('my.todos.update', $todo), $this->payload([
            'status' => Todo::STATUS_COMPLETED,
            'closed_on' => '2026-01-01',
        ]));

        $todo->refresh();

        $this->assertSame(Todo::STATUS_WAITING, $todo->status);
        $this->assertNull($todo->closed_on);
    }

    public function test_an_employee_cannot_hand_their_to_do_to_somebody_else(): void
    {
        $user = $this->employee('Mine');
        $other = $this->employee('Theirs');
        $todo = $this->todo($user);

        $this->actingAs($user)->put(route('my.todos.update', $todo), $this->payload([
            'user_id' => $other->id,
        ]));

        $this->assertSame($user->id, $todo->refresh()->user_id);
    }

    public function test_an_unreadable_date_in_the_url_falls_back_to_today(): void
    {
        $user = $this->employee();

        $response = $this->actingAs($user)->get(route('my.todos', ['date' => 'not-a-day']));

        $response->assertOk();
        $this->assertTrue($response->viewData('day')->isToday());
    }

    public function test_the_board_opens_on_today_when_no_date_is_given(): void
    {
        $user = $this->employee();

        $this->assertTrue(
            $this->actingAs($user)->get(route('my.todos'))->viewData('day')->isToday()
        );
    }
}
