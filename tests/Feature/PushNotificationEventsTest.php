<?php

namespace Tests\Feature;

use App\Mcp\Tools\CreateTodo;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Enquiry;
use App\Models\Shoot;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\BriefSubmitted;
use App\Notifications\EnquiryReceivedPush;
use App\Notifications\ShootCrewAdded;
use App\Notifications\TimesheetDayRejected;
use App\Notifications\TodoAssigned;
use App\Notifications\TodoSentBack;
use App\Support\BrandBrief;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two safest push events, and their guards.
 *
 * "Safest" because neither can silently spam somebody: an announcement fires
 * from exactly one place (store(), never update()), and a to-do only
 * notifies when it genuinely changed hands. Notification::fake() intercepts
 * before the FcmChannel is ever reached, so these tests say nothing about
 * whether Firebase is configured -- that is PushSenderTest's job.
 */
class PushNotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => User::ROLE_EMPLOYEE]);
    }

    public function test_posting_an_announcement_notifies_every_other_member_of_staff(): void
    {
        Notification::fake();

        $author = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $colleague = $this->staff();
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($author)->post(route('announcements.store'), [
            'title' => 'Studio closed Friday',
            'body' => 'Long weekend, back Monday.',
            'is_active' => '1',
        ])->assertRedirect(route('announcements.index'));

        $announcement = Announcement::firstOrFail();

        Notification::assertSentTo($colleague, AnnouncementPosted::class);
        Notification::assertNotSentTo($author, AnnouncementPosted::class);
        Notification::assertNotSentTo($client, AnnouncementPosted::class);
        Notification::assertSentTo($colleague, function (AnnouncementPosted $notification) use ($announcement) {
            return $notification->announcement->is($announcement);
        });
    }

    public function test_an_inactive_announcement_notifies_nobody(): void
    {
        Notification::fake();

        $author = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $colleague = $this->staff();

        $this->actingAs($author)->post(route('announcements.store'), [
            'title' => 'Draft, not ready yet',
            'body' => 'Still writing this.',
            'is_active' => '0',
        ])->assertRedirect(route('announcements.index'));

        Notification::assertNothingSentTo($colleague);
    }

    public function test_editing_an_announcement_does_not_re_notify(): void
    {
        Notification::fake();

        $author = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $colleague = $this->staff();
        $announcement = Announcement::create([
            'title' => 'Original',
            'body' => 'Original body.',
            'is_active' => true,
            'created_by' => $author->id,
        ]);

        $this->actingAs($author)->put(route('announcements.update', $announcement), [
            'title' => 'Fixed a typo',
            'body' => 'Original body.',
            'is_active' => '1',
        ])->assertRedirect(route('announcements.index'));

        Notification::assertNothingSentTo($colleague);
    }

    public function test_the_deep_link_goes_to_the_index_for_a_recipient_who_can_see_it(): void
    {
        Notification::fake();

        $author = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $viewer = $this->staff();
        $viewer->syncPermissions(['announcements' => ['view']]);
        $viewer->refresh();

        $this->actingAs($author)->post(route('announcements.store'), [
            'title' => 'Heads up',
            'body' => 'Body.',
            'is_active' => '1',
        ]);

        $announcement = Announcement::firstOrFail();

        Notification::assertSentTo($viewer, function (AnnouncementPosted $notification) use ($viewer) {
            return $notification->toFcm($viewer)->url === route('announcements.index');
        });
    }

    public function test_the_deep_link_falls_back_to_home_for_a_recipient_who_cannot_see_the_index(): void
    {
        Notification::fake();

        $author = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $noAccess = $this->staff();

        $this->actingAs($author)->post(route('announcements.store'), [
            'title' => 'Heads up',
            'body' => 'Body.',
            'is_active' => '1',
        ]);

        Notification::assertSentTo($noAccess, function (AnnouncementPosted $notification) use ($noAccess) {
            return $notification->toFcm($noAccess)->url === route($noAccess->homeRoute());
        });
    }

    public function test_assigning_a_todo_to_somebody_else_notifies_them(): void
    {
        Notification::fake();

        $manager = $this->staff();
        $editor = $this->staff();

        $this->actingAs($manager)->post(route('my.todos.store'), [
            'title' => 'Cut the teaser',
            'user_id' => $editor->id,
            'venture' => 'All / Multiple Clients',
            'starts_on' => today()->toDateString(),
        ])->assertRedirect();

        $todo = Todo::firstOrFail();

        Notification::assertSentTo($editor, TodoAssigned::class);
        Notification::assertNotSentTo($manager, TodoAssigned::class);
    }

    public function test_a_self_written_todo_notifies_nobody(): void
    {
        Notification::fake();

        $user = $this->staff();

        $this->actingAs($user)->post(route('my.todos.store'), [
            'title' => 'Remember to invoice',
            'user_id' => $user->id,
            'venture' => 'All / Multiple Clients',
            'starts_on' => today()->toDateString(),
        ])->assertRedirect();

        Notification::assertNothingSentTo($user);
    }

    /**
     * The trap the plan calls out by name: two call sites create a Todo the
     * same way, and it is easy to wire the notification into only the web
     * controller. This proves the MCP tool notifies too.
     */
    public function test_a_todo_assigned_through_the_mcp_tool_also_notifies(): void
    {
        Notification::fake();

        $caller = $this->staff(['name' => 'Pat Producer']);
        $editor = $this->staff(['name' => 'Ed Editor']);

        (new CreateTodo)->handle([
            'title' => 'Cut the teaser',
            'person' => 'Ed Editor',
        ], $caller);

        Notification::assertSentTo($editor, TodoAssigned::class);
    }

    public function test_a_todo_the_mcp_tool_assigns_to_the_caller_notifies_nobody(): void
    {
        Notification::fake();

        $caller = $this->staff(['name' => 'Pat Producer']);

        (new CreateTodo)->handle(['title' => 'Note to self'], $caller);

        Notification::assertNothingSentTo($caller);
    }

    private function finishedTodo(User $manager, User $owner): Todo
    {
        $todo = Todo::create([
            'user_id' => $owner->id,
            'assigned_by_id' => $manager->id,
            'title' => 'Cut the teaser',
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);
        $todo->moveTo(Todo::STATUS_COMPLETED, $owner);

        return $todo->fresh();
    }

    public function test_sending_a_todo_back_notifies_the_person_it_belongs_to(): void
    {
        Notification::fake();

        $manager = $this->staff();
        $owner = $this->staff();
        $owner->managers()->attach($manager);
        $todo = $this->finishedTodo($manager, $owner);

        $this->actingAs($manager)->post(route('todos.review', $todo), [
            'review_state' => Todo::REVIEW_REJECTED,
            'review_note' => 'The grade is off in the second half.',
        ])->assertRedirect();

        Notification::assertSentTo($owner, TodoSentBack::class);
    }

    public function test_checking_off_a_todo_does_not_notify(): void
    {
        Notification::fake();

        $manager = $this->staff();
        $owner = $this->staff();
        $owner->managers()->attach($manager);
        $todo = $this->finishedTodo($manager, $owner);

        $this->actingAs($manager)->post(route('todos.review', $todo), [
            'review_state' => Todo::REVIEW_APPROVED,
        ])->assertRedirect();

        Notification::assertNothingSentTo($owner);
    }

    public function test_rejecting_a_timesheet_day_notifies_the_employee(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->staff();
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => TimesheetEntry::TASK_SHOOTING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
        ]);

        $this->actingAs($admin)->post(route('timesheets.day', $employee), [
            'worked_on' => today()->toDateString(),
            'review_state' => TimesheetDay::REJECTED,
            'review_note' => 'Hours look off for this day.',
        ])->assertRedirect();

        Notification::assertSentTo($employee, TimesheetDayRejected::class);
    }

    public function test_approving_a_timesheet_day_does_not_notify(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->staff();

        $this->actingAs($admin)->post(route('timesheets.day', $employee), [
            'worked_on' => today()->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ])->assertRedirect();

        Notification::assertNothingSentTo($employee);
    }

    public function test_a_new_enquiry_notifies_staff_who_can_see_enquiries(): void
    {
        Notification::fake();

        $granted = $this->staff();
        $granted->syncPermissions(['enquiries' => ['view']]);
        $granted->refresh();
        $ungranted = $this->staff();

        $this->post(route('enquiry.store'), [
            'name' => 'Prospective Client',
            'email' => 'prospect@example.com',
            'message' => 'We would like a promo video.',
        ])->assertRedirect();

        $this->assertSame(1, Enquiry::count());
        Notification::assertSentTo($granted, EnquiryReceivedPush::class);
        Notification::assertNothingSentTo($ungranted);
    }

    private function shoot(array $overrides = []): Shoot
    {
        return Shoot::create($overrides + [
            'title' => 'Tea montage',
            'starts_at' => now()->addDays(3),
            'status' => Shoot::STATUS_PLANNED,
        ]);
    }

    private function producer(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['shoots' => ['view', 'edit']]);

        return $user->refresh();
    }

    public function test_being_added_to_a_shoots_crew_notifies_them(): void
    {
        Notification::fake();

        $producer = $this->producer();
        $crewMember = $this->staff();
        $shoot = $this->shoot();

        $this->actingAs($producer)->post(route('shoots.crew.store', $shoot), [
            'user_id' => $crewMember->id,
        ])->assertRedirect();

        Notification::assertSentTo($crewMember, ShootCrewAdded::class);
    }

    public function test_editing_a_crew_members_call_time_does_not_re_notify(): void
    {
        $producer = $this->producer();
        $crewMember = $this->staff();
        $shoot = $this->shoot();

        $this->actingAs($producer)->post(route('shoots.crew.store', $shoot), [
            'user_id' => $crewMember->id,
        ]);

        Notification::fake();

        $this->actingAs($producer)->post(route('shoots.crew.store', $shoot), [
            'user_id' => $crewMember->id,
            'call_time' => '09:00',
        ])->assertRedirect();

        Notification::assertNothingSentTo($crewMember);
    }

    public function test_a_producer_crewing_themselves_does_not_notify(): void
    {
        Notification::fake();

        $producer = $this->producer();
        $shoot = $this->shoot();

        $this->actingAs($producer)->post(route('shoots.crew.store', $shoot), [
            'user_id' => $producer->id,
        ])->assertRedirect();

        Notification::assertNothingSentTo($producer);
    }

    public function test_crewing_a_cancelled_shoot_does_not_notify(): void
    {
        Notification::fake();

        $producer = $this->producer();
        $crewMember = $this->staff();
        $shoot = $this->shoot(['status' => Shoot::STATUS_CANCELLED]);

        $this->actingAs($producer)->post(route('shoots.crew.store', $shoot), [
            'user_id' => $crewMember->id,
        ])->assertRedirect();

        Notification::assertNothingSentTo($crewMember);
    }

    public function test_crewing_a_shoot_already_in_the_past_does_not_notify(): void
    {
        Notification::fake();

        $producer = $this->producer();
        $crewMember = $this->staff();
        $shoot = $this->shoot(['starts_at' => now()->subDay()]);

        $this->actingAs($producer)->post(route('shoots.crew.store', $shoot), [
            'user_id' => $crewMember->id,
        ])->assertRedirect();

        Notification::assertNothingSentTo($crewMember);
    }

    /**
     * Every required question, answered validly. Built from the catalogue
     * rather than hardcoded, matching PublicBriefLinkTest and ClientBriefTest.
     *
     * @return array<string, mixed>
     */
    private function completeBriefAnswers(): array
    {
        $answers = [];

        foreach (BrandBrief::requiredKeys() as $key) {
            $question = BrandBrief::question($key);

            $answers[$key] = match ($question['type']) {
                BrandBrief::TYPE_CONTACT => ['name' => 'Vinupriya', 'phone' => '+91 90000 00000'],
                BrandBrief::TYPE_CHIPS, BrandBrief::TYPE_CHECKS => ($question['multi'] ?? false)
                    ? [$question['options'][0]]
                    : $question['options'][0],
                default => 'An answer.',
            };
        }

        return $answers;
    }

    public function test_submitting_a_public_brief_notifies_staff_who_can_see_clients(): void
    {
        Notification::fake();

        $client = Client::create(['name' => 'SVA Silks']);
        $granted = $this->staff();
        $granted->syncPermissions(['clients' => ['view']]);
        $granted->refresh();
        $ungranted = $this->staff();
        $token = $client->brief()->create([])->issuePublicToken();

        $this->post(route('brief.public.submit', $token), [
            'answers' => $this->completeBriefAnswers(),
            'submitted_name' => 'Vinupriya',
        ])->assertRedirect();

        Notification::assertSentTo($granted, BriefSubmitted::class);
        Notification::assertNothingSentTo($ungranted);
    }

    public function test_submitting_a_signed_in_clients_brief_notifies_staff(): void
    {
        Notification::fake();

        $client = Client::create(['name' => 'SVA Silks']);
        $login = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
        $granted = $this->staff();
        $granted->syncPermissions(['clients' => ['view']]);
        $granted->refresh();

        $this->actingAs($login)->post(route('client.brief.submit'), [
            'answers' => $this->completeBriefAnswers(),
        ])->assertRedirect();

        Notification::assertSentTo($granted, BriefSubmitted::class);
    }

    /**
     * Unlike the public link (which closes on first submit -- see
     * PublicBriefController::submit()'s own 403 on a second write), a
     * signed-in client CAN submit() again after their first submission:
     * submitted_at is preserved rather than reset, so this is the one place
     * that actually exercises the "already submitted" guard.
     */
    public function test_resubmitting_a_signed_in_clients_brief_does_not_re_notify(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);
        $login = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
        $staff = $this->staff();
        $staff->syncPermissions(['clients' => ['view']]);
        $staff->refresh();

        $this->actingAs($login)->post(route('client.brief.submit'), [
            'answers' => $this->completeBriefAnswers(),
        ]);

        Notification::fake();

        $this->actingAs($login)->post(route('client.brief.submit'), [
            'answers' => $this->completeBriefAnswers(),
        ])->assertRedirect();

        Notification::assertNothingSent();
    }
}
