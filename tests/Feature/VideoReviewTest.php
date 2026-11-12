<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\ReviewComment;
use App\Models\ReviewVideo;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\VideoReviewUpdate;
use App\Services\Ai\GeminiBudget;
use App\Services\Ai\VideoCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Video review inside the portal: upload, AI check, comments, verdict, cleanup. */
class VideoReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $admin;

    private Todo $card;

    protected function setUp(): void
    {
        parent::setUp();
        GeminiBudget::reset();
        $this->withoutVite();
        config(['ai.review.enabled' => false]); // the AI check has its own test
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Keerthi']);
        $this->card = new Todo;
        $this->card->forceFill(['user_id' => $this->editor->id, 'title' => 'Janet - Superfetation', 'venture' => 'Janet', 'status' => Todo::STATUS_STARTED, 'starts_on' => today(), 'due_on' => today()])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/reviews/'.$this->card->id));
        parent::tearDown();
    }

    /** Upload $bytes in two pieces the way the browser does; returns the finish response. */
    private function uploadAs(User $user, string $bytes = '', array $finish = [])
    {
        $bytes = $bytes ?: str_repeat('v', 1000);
        $this->actingAs($user);
        $start = $this->postJson(route('reviews.upload.start', $this->card), ['name' => 'cut.mp4', 'size' => strlen($bytes), 'type' => 'video/mp4'])->assertOk();
        $id = $start->json('upload');

        $half = intdiv(strlen($bytes), 2);
        $this->call('POST', route('reviews.upload.chunk', ['upload' => $id, 'offset' => 0]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, 0, $half))->assertOk();
        // The same piece again (a retry) changes nothing.
        $this->call('POST', route('reviews.upload.chunk', ['upload' => $id, 'offset' => 0]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, 0, $half))->assertOk()->assertJsonPath('received', $half);
        $this->call('POST', route('reviews.upload.chunk', ['upload' => $id, 'offset' => $half]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, $half))->assertOk();

        return $this->postJson(route('reviews.upload.finish', ['upload' => $id]), $finish + ['duration' => 42.5, 'width' => 1080, 'height' => 1920]);
    }

    public function test_an_editor_uploads_a_cut_in_pieces_and_it_goes_for_review(): void
    {
        Notification::fake();

        $this->uploadAs($this->editor, str_repeat('x', 5000), ['note' => 'Music is a placeholder'])->assertCreated()->assertJsonPath('video.version', 1);

        $video = ReviewVideo::first();
        $this->assertSame(5000, filesize($video->filePath()));
        $this->assertSame(Todo::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame('Editor: Music is a placeholder', $video->comments()->first()->body);
        Notification::assertSentTo($this->admin, VideoReviewUpdate::class);
        Notification::assertNotSentTo($this->editor, VideoReviewUpdate::class);

        // The reviewer can play it; a stranger cannot even see it.
        $this->actingAs($this->admin)->get(route('reviews.show', $video))->assertOk()->assertSee('Janet - Superfetation')->assertSee('videoReview', false);
        $this->get(route('reviews.file', $video))->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->get(route('reviews.show', $video))->assertNotFound();
        $this->get(route('reviews.file', $video))->assertNotFound();
    }

    public function test_a_file_bigger_than_it_said_or_not_a_video_is_refused(): void
    {
        $this->actingAs($this->editor)->postJson(route('reviews.upload.start', $this->card), ['name' => 'notes.pdf', 'size' => 10, 'type' => 'application/pdf'])->assertStatus(422);
        $id = $this->postJson(route('reviews.upload.start', $this->card), ['name' => 'cut.mp4', 'size' => 10, 'type' => 'video/mp4'])->json('upload');
        $this->call('POST', route('reviews.upload.chunk', ['upload' => $id, 'offset' => 0]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], str_repeat('x', 50))->assertStatus(422);
        // Someone else cannot write into this upload.
        $this->actingAs($this->admin)->call('POST', route('reviews.upload.chunk', ['upload' => $id, 'offset' => 0]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'x')->assertNotFound();
    }

    public function test_sending_back_turns_open_comments_into_corrections_and_the_next_version_clears_them(): void
    {
        Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $v1 = ReviewVideo::first();

        $this->actingAs($this->admin)->postJson(route('reviews.comment', $v1), ['body' => 'Spelling: Trichy', 'at_seconds' => 12.4])->assertCreated();
        $this->postJson(route('reviews.comment', $v1), ['body' => 'Music too loud at the end'])->assertCreated();
        $this->postJson(route('reviews.changes', $v1), ['note' => 'Two small things'])->assertOk()->assertJsonPath('status', 'changes');

        $card = $this->card->fresh();
        $this->assertSame(Todo::STATUS_STARTED, $card->status);
        $this->assertTrue($card->wasSentBack());
        $this->assertSame(['0:12 — Spelling: Trichy', 'Music too loud at the end'], $card->corrections()->orderBy('id')->pluck('title')->all());
        Notification::assertSentTo($this->editor, VideoReviewUpdate::class, fn ($n) => str_starts_with($n->title, 'Changes asked'));

        // v2 fixes them: the corrections close, the card is done again, waiting for review.
        $this->uploadAs($this->editor)->assertCreated()->assertJsonPath('video.version', 2);
        $card->refresh();
        $this->assertSame(0, $card->corrections()->open()->count());
        $this->assertSame(Todo::STATUS_COMPLETED, $card->status);
        $this->assertTrue($card->awaitsReview());
        $this->assertSame(2, ReviewComment::query()->whereNotNull('resolved_at')->count());

        // Approve v2.
        $v2 = ReviewVideo::where('version', 2)->first();
        $this->actingAs($this->admin)->postJson(route('reviews.approve', $v2), ['note' => 'Lovely'])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertTrue($card->fresh()->isApproved());
    }

    public function test_sending_back_needs_something_to_change_and_an_editor_cannot_judge_their_own_cut(): void
    {
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();

        $this->actingAs($this->editor)->postJson(route('reviews.approve', $video))->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('reviews.changes', $video))->assertStatus(422);
    }

    public function test_the_ai_check_flags_issues_from_the_video(): void
    {
        config(['ai.review.enabled' => true]);
        AiSetting::current()->forceFill(['gemini_api_key' => 'AIza-test', 'gemini_monthly_cap_usd' => 5])->save();
        Http::fake([
            'generativelanguage.googleapis.com/upload/*' => Http::response(['file' => ['name' => 'files/abc', 'uri' => 'https://g/files/abc', 'state' => 'ACTIVE', 'mimeType' => 'video/mp4']], 200, ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session/1']),
            'generativelanguage.googleapis.com/v1beta/files/*' => Http::response([]),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'verdict' => 'minor_fixes', 'score' => 8, 'summary' => 'Good hook, one typo.',
                    'issues' => [['at' => 12.3, 'type' => 'spelling', 'severity' => 'high', 'note' => '"Tirchy" should be "Trichy".']],
                    'good' => ['Strong hook'],
                ])]]]]],
                'usageMetadata' => ['promptTokenCount' => 20000, 'candidatesTokenCount' => 400],
            ]),
        ]);
        Notification::fake();

        // The check runs straight after the upload answers.
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();

        $this->assertSame('done', $video->ai_status);
        $this->assertSame('minor_fixes', $video->ai_verdict);
        $this->assertSame('spelling', $video->ai_report['issues'][0]['type']);
        $this->assertSame(12.3, $video->ai_report['issues'][0]['at']);
        $this->assertSame('review', DB::table('ai_usage')->value('feature'));
        Http::assertSent(fn ($r) => str_contains($r->url(), ':generateContent') && str_contains(json_encode($r->data()), 'Janet - Superfetation'));

        // A flag becomes a comment in one tap.
        $this->actingAs($this->admin)->postJson(route('reviews.comment', $video), ['body' => '"Tirchy" should be "Trichy".', 'at_seconds' => 12.3])->assertCreated();
    }

    public function test_the_ai_check_is_skipped_when_the_monthly_limit_is_used_up(): void
    {
        config(['ai.review.enabled' => true]);
        AiSetting::current()->forceFill(['gemini_api_key' => 'AIza-test', 'gemini_monthly_cap_usd' => 5])->save();
        DB::table('ai_usage')->insert(['provider' => 'gemini', 'model' => 'm', 'cost_usd' => 5, 'created_at' => now()]);
        Http::fake([
            'generativelanguage.googleapis.com/upload/*' => Http::response(['file' => ['name' => 'files/abc', 'uri' => 'u', 'state' => 'ACTIVE']], 200, ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session/1']),
            '*' => Http::response([]),
        ]);
        Notification::fake();

        $this->uploadAs($this->editor)->assertCreated();
        VideoCheck::run(ReviewVideo::first());

        $this->assertSame('skipped', ReviewVideo::first()->ai_status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), ':generateContent'));
    }

    public function test_review_copies_are_deleted_a_week_after_posting(): void
    {
        Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $path = $video->filePath();

        $this->artisan('reviews:housekeeping')->assertSuccessful();
        $this->assertFileExists($path);

        $this->card->fresh()->forceFill(['posted_at' => now()->subDays(8)])->save();
        $this->artisan('reviews:housekeeping')->assertSuccessful();

        $this->assertFileDoesNotExist($path);
        $this->assertNotNull($video->fresh()->deleted_file_at);
        $this->actingAs($this->admin)->get(route('reviews.show', $video))->assertOk()->assertSee('deleted after the video went out');
    }

    public function test_the_queue_shows_what_waits_and_the_editing_card_links_to_it(): void
    {
        Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();

        $this->actingAs($this->admin)->get(route('reviews.index'))->assertOk()->assertSee('Waiting for you')->assertSee('Janet - Superfetation');
        $this->actingAs($this->editor)->get(route('reviews.index'))->assertForbidden();
    }
}
