<?php

namespace Tests\Feature;

use App\Models\ReviewVideo;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Instagram picture posts (one picture or a carousel) go through the same
 * review as videos, uploaded from Editor Updates by editors and admins.
 */
class PicturePostReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $admin;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // Never touch the real review files: this host is production too.
        $this->storage = storage_path('framework/testing/picture-posts-'.getmypid());
        File::ensureDirectoryExists($this->storage.'/app/private');
        $this->app->useStoragePath($this->storage);
        $this->withoutVite();
        config(['reviews.enforce' => true]);
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Keerthi']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function pictures(int $n): array
    {
        return collect(range(1, $n))->map(fn ($i) => UploadedFile::fake()->image("slide{$i}.jpg", 1080, 1350))->all();
    }

    public function test_an_editor_uploads_a_carousel_from_editor_updates_as_a_new_post(): void
    {
        $this->actingAs($this->editor)->get(route('editor-updates'))->assertOk()->assertSee('Upload a picture post');

        $response = $this->actingAs($this->editor)->post(route('editor-updates.post'), [
            'title' => 'Zira — Diwali offer carousel', 'pictures' => $this->pictures(3), 'note' => 'Caption: Light up this Diwali',
        ]);

        $review = ReviewVideo::query()->firstOrFail();
        $response->assertRedirect(route('reviews.show', $review));
        $card = $review->todo;
        $this->assertSame('post', $card->kind);
        $this->assertSame($this->editor->id, $card->user_id);
        $this->assertSame(Todo::STATUS_COMPLETED, $card->status); // sent for review
        $this->assertTrue($review->isImages());
        $this->assertCount(3, $review->slides);
        $this->assertSame(1080, $review->slides[0]['w']);
        $this->assertSame('none', $review->ai_status); // no AI check on pictures
        $this->assertFileExists(storage_path('app/private/'.$review->slides[2]['path']));

        $this->actingAs($this->admin)->get(route('reviews.show', $review))->assertOk()->assertSee('Comment on', false);
        $this->actingAs($this->admin)->get(route('reviews.slide', [$review, 1]))->assertOk();
        $this->actingAs($this->admin)->get(route('reviews.slide', [$review, 9]))->assertNotFound();
    }

    public function test_comments_sit_on_a_slide_and_a_new_version_replaces_the_old(): void
    {
        $card = new Todo;
        $card->forceFill(['user_id' => $this->editor->id, 'title' => 'Janet — tips post', 'venture' => 'Janet', 'status' => Todo::STATUS_STARTED, 'starts_on' => today(), 'due_on' => today()])->save();

        $this->actingAs($this->editor)->postJson(route('reviews.pictures', $card), ['pictures' => $this->pictures(2)])->assertCreated();
        $v1 = ReviewVideo::query()->firstOrFail();

        $this->actingAs($this->admin)->postJson(route('reviews.comment', $v1), ['body' => 'Spelling on this slide', 'at_seconds' => 1])->assertCreated();
        $live = $this->actingAs($this->admin)->getJson(route('reviews.live', $v1))->assertOk();
        $this->assertSame('Slide 2', $live->json('comments.0.stamp'));
        $this->assertSame('images', $live->json('media'));
        $this->assertCount(2, $live->json('slides'));
        $this->assertFalse($live->json('ai_applies'));

        // Send back: the comment becomes a correction; v2 fixes it.
        $this->actingAs($this->admin)->postJson(route('reviews.changes', $v1), ['note' => 'Fix the spelling'])->assertOk();
        $this->assertStringStartsWith('Slide 2', $card->corrections()->first()->title);
        $this->actingAs($this->editor)->postJson(route('reviews.pictures', $card), ['pictures' => $this->pictures(2)])->assertCreated();

        $v2 = ReviewVideo::query()->where('version', 2)->firstOrFail();
        $this->assertTrue($card->corrections()->first()->fresh()->status === Todo::STATUS_COMPLETED);
        $this->actingAs($this->admin)->postJson(route('reviews.approve', $v2), ['note' => 'Good'])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_more_than_ten_pictures_or_a_non_picture_is_refused(): void
    {
        $card = new Todo;
        $card->forceFill(['user_id' => $this->editor->id, 'title' => 'x', 'status' => Todo::STATUS_STARTED, 'starts_on' => today(), 'due_on' => today()])->save();

        $this->actingAs($this->editor)->postJson(route('reviews.pictures', $card), ['pictures' => $this->pictures(11)])->assertUnprocessable();
        $this->actingAs($this->editor)->postJson(route('reviews.pictures', $card), ['pictures' => [UploadedFile::fake()->create('cut.mp4', 100, 'video/mp4')]])->assertUnprocessable();
        $this->assertSame(0, ReviewVideo::count());
    }

    public function test_an_editor_sees_only_their_own_work_and_cannot_post_to_someone_elses_card(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
        $theirs = new Todo;
        $theirs->forceFill(['user_id' => $other->id, 'title' => 'Gokul secret reel', 'status' => Todo::STATUS_STARTED, 'starts_on' => today(), 'due_on' => today()])->save();

        $this->actingAs($this->editor)->get(route('editor-updates'))->assertOk()->assertDontSee('Gokul secret reel');
        $this->actingAs($this->editor)->post(route('editor-updates.post'), ['todo_id' => $theirs->id, 'pictures' => $this->pictures(1)])->assertForbidden();
        $this->actingAs($this->admin)->get(route('editor-updates'))->assertOk()->assertSee('Gokul secret reel');
    }

    public function test_a_client_cannot_open_editor_updates(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))->get(route('editor-updates'))->assertForbidden();
    }
}
