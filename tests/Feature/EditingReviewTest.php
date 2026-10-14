<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Done sends a video to review; from review it gets a correction (back to
 * Working) or goes out -- posted as a Reel/Post/Story, or delivered.
 */
class EditingReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
        // An admin who keeps no timesheet: reviews from Editor Updates.
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Boss']);
    }

    private function finished(string $title = 'SVA - Cotton Frock'): int
    {
        $id = $this->actingAs($this->editor)->postJson(route('my.editing.store'), ['title' => $title])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->postJson(route('my.editing.act', $id), ['action' => 'done'])->assertOk();

        return $id;
    }

    public function test_done_waits_in_review_and_shows_in_the_admin_queue(): void
    {
        $id = $this->finished();
        $todo = Todo::find($id);
        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertNull($todo->posted_at);

        $this->actingAs($this->admin)->get(route('editor-updates'))
            ->assertOk()
            ->assertSee('Waiting for your review')
            ->assertSee('SVA - Cotton Frock')
            ->assertSee('Done → review');
    }

    public function test_an_admin_sends_a_correction_back_to_working_from_review(): void
    {
        $id = $this->finished();

        $this->actingAs($this->admin)->postJson(route('my.editing.correct', $id), ['title' => 'Logo cut at the end'])
            ->assertOk()->assertJsonPath('card.status', Todo::STATUS_STARTED);

        // It cannot go out while the correction is open.
        $this->postJson(route('my.editing.act', $id), ['action' => 'deliver'])->assertStatus(422);
        $this->postJson(route('my.editing.act', $id), ['action' => 'post', 'kind' => 'reel'])->assertStatus(422);
    }

    public function test_review_ends_in_posted_or_delivered(): void
    {
        $reel = $this->finished('SVA - Reel one');
        $film = $this->finished('Janet - Doctor interview');

        // Posting needs Video ready, with its publishing time, first.
        $this->actingAs($this->admin)->postJson(route('my.editing.act', $reel), ['action' => 'post', 'kind' => 'story'])->assertStatus(422);
        $this->postJson(route('my.editing.act', $reel), ['action' => 'ready', 'publish_at' => now()->addHour()->toDateTimeString()])->assertOk()->assertJsonPath('card.ready', true);
        $this->postJson(route('my.editing.act', $reel), ['action' => 'post', 'kind' => 'story'])
            ->assertOk()->assertJsonPath('card.kind', 'story');
        $this->postJson(route('my.editing.act', $film), ['action' => 'deliver'])
            ->assertOk()->assertJsonPath('card.kind', Todo::KIND_DELIVERED);

        $this->assertNotNull(Todo::find($film)->posted_at);
        $this->assertSame(1, TodoUpdate::where('action', TodoUpdate::DELIVERED)->count());

        $this->get(route('editor-updates'))->assertOk()
            ->assertSee('Nothing waiting for review')
            ->assertSee('Delivered')
            ->assertSee('Story');

        // Undoing a delivery puts it back in review.
        $this->postJson(route('my.editing.act', $film), ['action' => 'unpost'])->assertOk()->assertJsonPath('card.kind', null);
        $this->assertNull(Todo::find($film)->posted_at);
    }

    public function test_someone_unrelated_cannot_review(): void
    {
        $id = $this->finished();
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($other)->postJson(route('my.editing.act', $id), ['action' => 'deliver'])->assertNotFound();
    }
}
