<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use App\Models\VideoRequest;
use App\Notifications\VentureUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Cancelling a video (and moving it in the planner) needs an admin. */
class VideoRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
    }

    private function card(string $status = Todo::STATUS_STARTED, $created = null): Todo
    {
        $t = new Todo;
        $t->forceFill(['user_id' => $this->editor->id, 'title' => 'Janet - Diwali', 'venture' => 'Janet', 'status' => $status,
            'starts_on' => today(), 'due_on' => today()])->save();
        if ($created) {
            $t->forceFill(['created_at' => $created])->save();
        }

        return $t;
    }

    public function test_an_editors_cancel_waits_for_an_admin_who_approves_it(): void
    {
        $card = $this->card(Todo::STATUS_STARTED, now()->subDays(2));

        $this->actingAs($this->editor)->postJson(route('my.editing.act', $card), ['action' => 'remove'])
            ->assertStatus(202)->assertJsonPath('pending', true);
        $this->assertSame(Todo::STATUS_STARTED, $card->fresh()->status);
        $req = VideoRequest::firstOrFail();
        $this->assertSame('cancel', $req->kind);
        Notification::assertSentTo($this->admin, VentureUpdate::class);

        $this->actingAs($this->admin)->get(route('editor-updates'))->assertOk()->assertSee('Waiting for your approval')->assertSee('Gokul');
        $this->actingAs($this->editor)->post(route('video-requests.approve', $req))->assertForbidden();
        $this->actingAs($this->admin)->post(route('video-requests.approve', $req))->assertSessionHas('status');

        $this->assertSame(Todo::STATUS_CANCELLED, $card->fresh()->status);
        $this->assertSame('approved', $req->fresh()->status);
        Notification::assertSentTo($this->editor, VentureUpdate::class, fn ($n) => str_contains($n->title, 'approved'));
    }

    public function test_a_refused_cancel_leaves_the_video_and_tells_the_editor(): void
    {
        $card = $this->card(Todo::STATUS_STARTED, now()->subDay());
        $this->actingAs($this->editor)->postJson(route('my.editing.act', $card), ['action' => 'remove'])->assertStatus(202);

        $this->actingAs($this->admin)->post(route('video-requests.refuse', VideoRequest::first()), ['note' => 'Client still wants it'])->assertSessionHas('status');

        $this->assertSame(Todo::STATUS_STARTED, $card->fresh()->status);
        Notification::assertSentTo($this->editor, VentureUpdate::class, fn ($n) => str_contains($n->body, 'Client still wants it'));
    }

    public function test_a_card_added_by_mistake_today_is_still_removed_freely_and_admins_act_directly(): void
    {
        $mistake = $this->card(Todo::STATUS_WAITING);
        $this->actingAs($this->editor)->postJson(route('my.editing.act', $mistake), ['action' => 'remove'])->assertOk()->assertJsonPath('removed', $mistake->id);
        $this->assertSame(Todo::STATUS_CANCELLED, $mistake->fresh()->status);

        $other = $this->card(Todo::STATUS_STARTED, now()->subDays(3));
        $this->actingAs($this->admin)->postJson(route('my.editing.act', $other), ['action' => 'remove'])->assertOk();
        $this->assertSame(Todo::STATUS_CANCELLED, $other->fresh()->status);
        $this->assertSame(0, VideoRequest::count());
    }
}
