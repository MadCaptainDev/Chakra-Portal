<?php

namespace Tests\Feature;

use App\Mcp\Tools\CreateTodo;
use App\Models\Announcement;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\TodoAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
