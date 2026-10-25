<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use App\Notifications\VideoNotPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * My Editing: a Video ready can be scheduled (Reel or YouTube) and then
 * counts as posted at its time; a video can be handed to another editor;
 * everyone sees the team's videos in review.
 */
class EditingScheduleReassignTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(today()->setTime(10, 0));
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $this->other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
    }

    private function video(string $title = 'SVA - Cotton Frock', bool $review = true): int
    {
        $id = $this->actingAs($this->editor)->postJson(route('my.editing.store'), ['title' => $title])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        if ($review) {
            $this->postJson(route('my.editing.act', $id), ['action' => 'done']);
        }

        return $id;
    }

    public function test_a_ready_video_is_scheduled_as_youtube_and_goes_out_on_its_own(): void
    {
        Notification::fake();
        $id = $this->video();
        $at = today()->setTime(18, 0)->format('Y-m-d\TH:i');
        $this->postJson(route('my.editing.act', $id), ['action' => 'schedule', 'kind' => 'youtube', 'publish_at' => $at])->assertStatus(422);
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => $at])->assertOk();

        // Anyone on the team can mark it scheduled.
        $this->actingAs($this->other)->postJson(route('my.editing.act', $id), ['action' => 'schedule', 'kind' => 'youtube', 'publish_at' => $at])
            ->assertOk()->assertJsonPath('card.scheduled', true)->assertJsonPath('card.kind', 'youtube');

        // Past its time it is not late and no one is nagged; it is posted, by whoever scheduled it.
        $this->travelTo(today()->setTime(19, 0));
        $this->assertFalse(Todo::find($id)->isLateToPublish());
        $this->artisan('editing:publish-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $todo = Todo::find($id);
        $this->assertSame('18:00', $todo->posted_at->format('H:i'));
        $this->assertSame($this->other->id, $todo->posted_by_id);
        $this->assertSame('posted', $todo->stage());
    }

    public function test_unscheduling_or_a_new_time_puts_it_back_to_be_posted_by_hand(): void
    {
        $id = $this->video();
        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(18, 0)->toDateTimeString()]);
        $this->postJson(route('my.editing.act', $id), ['action' => 'schedule', 'publish_at' => today()->setTime(18, 0)->toDateTimeString()])->assertJsonPath('card.scheduled', true);

        $this->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => today()->setTime(20, 0)->toDateTimeString()])->assertJsonPath('card.scheduled', false);
        $this->postJson(route('my.editing.act', $id), ['action' => 'schedule', 'publish_at' => today()->setTime(20, 0)->toDateTimeString()]);
        $this->postJson(route('my.editing.act', $id), ['action' => 'unschedule'])->assertJsonPath('card.scheduled', false);
    }

    public function test_a_video_is_handed_to_another_editor_with_its_corrections(): void
    {
        $id = $this->video(review: false);
        $this->postJson(route('my.editing.correct', $id), ['title' => 'Fix the logo']);

        $this->postJson(route('my.editing.act', $id), ['action' => 'reassign', 'user_id' => $this->other->id])
            ->assertOk()->assertJsonPath('to', 'Gokul')->assertJsonPath('card', null);

        $todo = Todo::find($id);
        $this->assertSame($this->other->id, $todo->user_id);
        // Paused, so Sanjai's clock stopped.
        $this->assertSame(Todo::STATUS_WAITING, $todo->status);
        $this->assertSame([$this->other->id], $todo->corrections()->pluck('user_id')->unique()->values()->all());

        // Someone neither on it nor managing it cannot hand it on.
        $stranger = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->actingAs($stranger)->postJson(route('my.editing.act', $id), ['action' => 'reassign', 'user_id' => $stranger->id])->assertNotFound();
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($this->other)->postJson(route('my.editing.act', $id), ['action' => 'reassign', 'user_id' => $client->id])->assertStatus(422);
    }

    public function test_other_editors_see_the_teams_videos_in_review(): void
    {
        $this->video('SVA - In review');
        $this->video('SVA - Still working', review: false);

        $this->actingAs($this->other)->get(route('my.editing'))->assertOk()
            ->assertSee('In review · team')->assertSee('SVA - In review')->assertDontSee('SVA - Still working');
        $this->actingAs($this->other)->getJson(route('my.editing.board'))->assertJsonPath('team_review.0.title', 'SVA - In review');
    }
}
