<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use App\Notifications\VideoNotPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * After review a video is Video ready with the time it is to be published;
 * 30 minutes past that time with nothing posted, the editor and the admins
 * get a push -- once.
 */
class VideoReadyTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(today()->setTime(10, 0));
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Boss']);
    }

    private function inReview(string $title = 'SVA - Cotton Frock'): int
    {
        $id = $this->actingAs($this->editor)->postJson(route('my.editing.store'), ['title' => $title])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->postJson(route('my.editing.act', $id), ['action' => 'done']);

        return $id;
    }

    public function test_ready_needs_a_publishing_time_and_a_finished_review(): void
    {
        $id = $this->actingAs($this->editor)->postJson(route('my.editing.store'), ['title' => 'Not done yet'])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => '2026-10-14 18:00'])->assertStatus(422);

        $id = $this->inReview();
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready'])->assertStatus(422)->assertJsonValidationErrors('publish_at');

        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(18, 0)->format('Y-m-d\TH:i')])
            ->assertOk()
            ->assertJsonPath('card.ready', true)
            ->assertJsonPath('card.publish_label', 'Today 6:00 PM');

        $todo = Todo::find($id);
        $this->assertTrue($todo->isReady());
        $this->assertSame('ready', $todo->stage());
    }

    public function test_a_correction_takes_a_ready_video_back_to_working(): void
    {
        $id = $this->inReview();
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(18, 0)->toDateTimeString()]);

        $this->actingAs($this->admin)->postJson(route('my.editing.correct', $id), ['title' => 'Wrong price on screen'])
            ->assertOk()->assertJsonPath('card.status', Todo::STATUS_STARTED)->assertJsonPath('card.ready', false);
        $this->assertNull(Todo::find($id)->publish_at);
    }

    public function test_thirty_minutes_late_pushes_the_editor_and_admins_once(): void
    {
        Notification::fake();
        $id = $this->inReview();
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(18, 0)->toDateTimeString()]);

        $this->travelTo(today()->setTime(18, 20));
        $this->artisan('editing:publish-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(today()->setTime(18, 31));
        $this->artisan('editing:publish-reminders')->assertSuccessful();
        Notification::assertSentTo([$this->editor, $this->admin], VideoNotPublished::class);

        $this->artisan('editing:publish-reminders');
        Notification::assertSentToTimes($this->editor, VideoNotPublished::class, 1);

        // A new time clears the reminder, so it can warn again for that time.
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(19, 0)->toDateTimeString()]);
        $this->assertNull(Todo::find($id)->publish_reminded_at);
    }

    public function test_posted_in_time_is_never_reminded(): void
    {
        Notification::fake();
        $id = $this->inReview();
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(18, 0)->toDateTimeString()]);
        $this->travelTo(today()->setTime(18, 5));
        $this->postJson(route('my.editing.act', $id), ['action' => 'post', 'kind' => 'reel'])->assertOk();

        $this->travelTo(today()->setTime(19, 0));
        $this->artisan('editing:publish-reminders');
        Notification::assertNothingSent();
    }

    public function test_editor_updates_lists_ready_videos_and_the_live_timeline(): void
    {
        $id = $this->inReview();
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(9, 30)->toDateTimeString()]);
        $working = $this->postJson(route('my.editing.store'), ['title' => 'Janet - 10 Foods'])->json('card.id');
        $this->postJson(route('my.editing.act', $working), ['action' => 'start']);
        $this->travelTo(today()->setTime(11, 0));

        $this->actingAs($this->admin)->get(route('editor-updates'))
            ->assertOk()
            ->assertSee('Ready to publish')
            ->assertSee('1 late')
            ->assertSee('Live timeline');

        $live = $this->getJson(route('editor-updates.live'))->assertOk()->json();
        $this->assertSame(['review' => 0, 'ready' => 1, 'late' => 1], $live['waiting']);
        $lane = $live['lanes'][0];
        $this->assertSame('Sanjai', $lane['name']);
        $this->assertSame('working', $lane['state']);
        $this->assertSame('Janet - 10 Foods', $lane['doing']);
        $this->assertContains('Video ready', array_column($lane['marks'], 'label'));
        $open = collect($lane['blocks'])->firstWhere('open', true);
        $this->assertSame('Janet - 10 Foods', $open['title']);
        $this->assertSame(60, (int) (($open['to'] - $open['from']) / 60000));

        $this->actingAs($this->editor)->getJson(route('editor-updates.live'))->assertForbidden();
    }
}
