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
        config(['reviews.enforce' => true]);
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
        // Back in the editor's To do, not straight into Editing.
        $this->assertSame(Todo::STATUS_WAITING, $card->status);
        $this->assertTrue($card->wasSentBack());
        $this->assertSame([Todo::STATUS_WAITING, Todo::STATUS_WAITING], $card->corrections()->pluck('status')->all());
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

        // The upload starts it straight after answering, in steps; it does
        // not wait for the every-minute job.
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
        $this->artisan('reviews:housekeeping')->assertSuccessful();

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

    public function test_two_reviewers_see_each_other_reply_and_agree(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $second = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Vasanth']);

        // Keerthi watches at 0:08; Vasanth sees her there.
        $this->actingAs($this->admin)->getJson(route('reviews.live', ['video' => $video, 't' => 8.2, 'p' => 1]))->assertOk();
        $this->actingAs($second)->getJson(route('reviews.live', ['video' => $video, 't' => 3]))->assertOk()
            ->assertJsonPath('watching.0.name', 'Keerthi')->assertJsonPath('watching.0.at', 8.2)->assertJsonPath('watching.0.playing', true);

        // Keerthi comments; Vasanth agrees and replies; the same comment twice is one.
        $this->actingAs($this->admin)->postJson(route('reviews.comment', $video), ['body' => 'Logo too small', 'at_seconds' => 4])->assertCreated();
        $this->postJson(route('reviews.comment', $video), ['body' => 'Logo too small', 'at_seconds' => 4])->assertCreated();
        $this->assertSame(1, $video->comments()->count());
        $comment = $video->comments()->first();
        \Illuminate\Support\Facades\Notification::assertSentTo($this->editor, \App\Notifications\VideoReviewUpdate::class, fn ($n) => str_starts_with($n->title, 'Comment from Keerthi'));

        $this->actingAs($second)->postJson(route('reviews.comment.like', $comment))->assertOk()
            ->assertJsonPath('comments.0.likes', 1)->assertJsonPath('comments.0.liked', true);
        $this->postJson(route('reviews.comment', $video), ['body' => 'Agree, and move it left', 'parent_id' => $comment->id])->assertCreated()
            ->assertJsonPath('comments.0.replies.0.body', 'Agree, and move it left')
            ->assertJsonPath('comments.0.replies.0.at', null);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->admin, \App\Notifications\VideoReviewUpdate::class, fn ($n) => str_starts_with($n->title, 'Reply from Vasanth'));

        // Liking again takes it back.
        $this->postJson(route('reviews.comment.like', $comment))->assertOk()->assertJsonPath('comments.0.likes', 0);
        $this->postJson(route('reviews.comment.like', $comment));

        // Either reviewer can send it back; the agreement travels with the correction, replies do not become corrections.
        $this->postJson(route('reviews.changes', $video))->assertOk()->assertJsonPath('status', 'changes');
        $this->assertSame(['0:04 — Logo too small (+1)'], $this->card->fresh()->corrections()->pluck('title')->all());

        // The editor sees the whole thread and can reply too.
        $this->actingAs($this->editor)->getJson(route('reviews.live', $video))->assertOk()->assertJsonPath('comments.0.replies.0.by', 'Vasanth');
        $this->postJson(route('reviews.comment', $video), ['body' => 'Fixed in v2', 'parent_id' => $comment->id])->assertCreated();
    }

    public function test_a_reply_cannot_hang_off_another_videos_comment(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $other = new ReviewVideo;
        $other->forceFill(['todo_id' => $this->card->id, 'version' => 9, 'status' => 'pending'])->save();
        $foreign = ReviewComment::create(['review_video_id' => $other->id, 'user_id' => $this->admin->id, 'body' => 'x']);

        $this->actingAs($this->admin)->postJson(route('reviews.comment', $video), ['body' => 'y', 'parent_id' => $foreign->id])->assertNotFound();
    }

    public function test_a_busy_gemini_is_tried_again_later_but_not_forever(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $video->forceFill(['ai_status' => 'failed', 'ai_attempts' => 1, 'ai_error' => 'Gemini could not check the video: This model is currently experiencing high demand.', 'updated_at' => now()->subMinutes(4)])->saveQuietly();
        config(['ai.review.enabled' => true]);
        AiSetting::current()->forceFill(['gemini_api_key' => null])->save(); // so the retry ends quickly as skipped

        $this->artisan('reviews:housekeeping')->assertSuccessful();
        $this->assertSame('skipped', $video->fresh()->ai_status);

        $video->fresh()->forceFill(['ai_status' => 'failed', 'ai_attempts' => 4, 'updated_at' => now()->subHour()])->saveQuietly();
        $this->artisan('reviews:housekeeping')->assertSuccessful();
        $this->assertSame('failed', $video->fresh()->ai_status, 'After four tries it is left for a person.');
    }

    public function test_people_fix_their_own_comments_only(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $this->actingAs($this->admin)->postJson(route('reviews.comment', $video), ['body' => 'Speling', 'at_seconds' => 3])->assertCreated();
        $comment = ReviewComment::query()->where('body', 'Speling')->first();

        $this->putJson(route('reviews.comment.edit', $comment), ['body' => 'Spelling: Trichy', 'at_seconds' => 3.5])->assertOk()
            ->assertJsonFragment(['body' => 'Spelling: Trichy']);
        $this->assertSame(3.5, $comment->fresh()->at_seconds);

        $this->actingAs($this->editor)->putJson(route('reviews.comment.edit', $comment), ['body' => 'hijack'])->assertForbidden();
    }

    public function test_the_page_offers_the_comment_bar_and_device_copy(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();

        $this->actingAs($this->admin)->get(route('reviews.show', ReviewVideo::first()))->assertOk()
            ->assertSee('Comment on this moment', false)
            ->assertSee('Saved on this device', false)
            ->assertSee('Press and hold', false);
    }

    public function test_every_required_approver_must_approve_before_it_is_approved_and_ready(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $annamalai = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Annamalai']);
        \App\Models\ReviewSetting::current()->update(['approver_ids' => [$this->admin->id, $annamalai->id], 'require_for_ready' => true]);
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();

        // Keerthi approves: recorded, still waiting; Annamalai is asked.
        $this->actingAs($this->admin)->postJson(route('reviews.approve', $video))->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('sign_off.done', 1)->assertJsonPath('sign_off.needed', 2)
            ->assertJsonPath('i_approved', true);
        \Illuminate\Support\Facades\Notification::assertSentTo($annamalai, \App\Notifications\VideoReviewUpdate::class, fn ($n) => str_starts_with($n->title, 'Your OK needed'));
        $this->assertFalse($this->card->fresh()->isApproved());

        // Not ready yet.
        $this->actingAs($this->editor)->postJson(route('my.editing.act', $this->card), ['action' => 'ready', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Waiting for approval from Annamalai (1 of 2 done).']);

        // Keerthi changes her mind, then approves again; Annamalai approves: done.
        $this->actingAs($this->admin)->deleteJson(route('reviews.unapprove', $video))->assertOk()->assertJsonPath('sign_off.done', 0);
        $this->postJson(route('reviews.approve', $video))->assertOk();
        $this->actingAs($annamalai)->postJson(route('reviews.approve', $video))->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('sign_off.complete', true);
        $this->assertTrue($this->card->fresh()->isApproved());
        \Illuminate\Support\Facades\Notification::assertSentTo($this->editor, \App\Notifications\VideoReviewUpdate::class, fn ($n) => $n->body === 'Approved by Keerthi and Annamalai. You can make it Video ready with its publishing time now.');

        // Now it can be made ready.
        $this->actingAs($this->editor)->postJson(route('my.editing.act', $this->card), ['action' => 'ready', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertOk();
    }

    public function test_one_approver_who_is_not_on_the_list_does_not_complete_it(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $annamalai = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Annamalai']);
        $other = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Vasanth']);
        \App\Models\ReviewSetting::current()->update(['approver_ids' => [$annamalai->id]]);
        $this->uploadAs($this->editor)->assertCreated();

        $this->actingAs($other)->postJson(route('reviews.approve', ReviewVideo::first()))->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('sign_off.extra.0.name', 'Vasanth');
    }

    public function test_a_card_with_no_upload_cannot_be_made_ready_unless_the_rule_is_off(): void
    {
        $this->card->forceFill(['status' => Todo::STATUS_COMPLETED])->save();
        \App\Models\ReviewSetting::current()->update(['approver_ids' => [], 'require_for_ready' => true]);

        $this->actingAs($this->editor)->postJson(route('my.editing.act', $this->card), ['action' => 'ready', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Upload the cut for review first. It can be made ready once a reviewer has approved it.']);

        $this->actingAs($this->admin)->put(route('reviews.settings'), ['approver_ids' => [], 'require_for_ready' => 0])->assertRedirect();
        $this->actingAs($this->editor)->postJson(route('my.editing.act', $this->card), ['action' => 'ready', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertOk();
    }

    public function test_the_ai_check_reports_its_steps(): void
    {
        config(['ai.review.enabled' => true]);
        AiSetting::current()->forceFill(['gemini_api_key' => 'AIza-test', 'gemini_monthly_cap_usd' => 5])->save();
        $stages = [];
        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response([], 200, ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session/1']),
            'generativelanguage.googleapis.com/upload/session/*' => function () use (&$stages) {
                $stages[] = ReviewVideo::first()?->ai_stage;

                return Http::response(['file' => ['name' => 'files/abc', 'uri' => 'u', 'state' => 'ACTIVE', 'mimeType' => 'video/mp4']]);
            },
            'generativelanguage.googleapis.com/v1beta/files/*' => Http::response([]),
            'generativelanguage.googleapis.com/*' => function () use (&$stages) {
                $stages[] = ReviewVideo::first()?->ai_stage;

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => "```json\n".json_encode(['verdict' => 'good_to_go', 'score' => 9, 'summary' => 'Fine', 'issues' => []])."\n```"]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
            },
        ]);
        \Illuminate\Support\Facades\Notification::fake();

        $this->uploadAs($this->editor)->assertCreated();
        $this->artisan('reviews:housekeeping')->assertSuccessful();

        $video = ReviewVideo::first();
        $this->assertSame('done', $video->ai_status, (string) $video->ai_error);
        $this->assertSame('good_to_go', $video->ai_verdict, 'Fenced JSON is read.');
        // The steps, in order, each seen at least once.
        $order = array_values(array_unique(array_filter($stages)));
        $this->assertSame(['uploading', 'watching'], array_values(array_intersect($order, ['uploading', 'watching'])));
        $this->assertNull($video->ai_stage);
    }

    public function test_an_ai_flag_or_comment_cannot_be_added_twice(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();

        $this->actingAs($this->admin)->postJson(route('reviews.comment', $video), ['body' => 'EXCERCISE should be EXERCISE', 'at_seconds' => 21])->assertCreated()
            ->assertJsonFragment(['adopted' => ['EXCERCISE should be EXERCISE|21.0']]);
        $this->travel(5)->minutes();
        $second = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($second)->postJson(route('reviews.comment', $video), ['body' => 'EXCERCISE should be EXERCISE', 'at_seconds' => 21])->assertCreated();

        $this->assertSame(1, $video->comments()->count());
        // The same words at another moment are a different comment.
        $this->postJson(route('reviews.comment', $video), ['body' => 'EXCERCISE should be EXERCISE', 'at_seconds' => 40])->assertCreated();
        $this->assertSame(2, $video->comments()->count());
    }

    public function test_after_one_approver_sends_it_back_the_others_comments_still_reach_the_editor(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $annamalai = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Annamalai']);
        \App\Models\ReviewSetting::current()->update(['approver_ids' => [$this->admin->id, $annamalai->id]]);
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();

        $this->actingAs($this->admin)->postJson(route('reviews.comment', $video), ['body' => 'Too dark', 'at_seconds' => 2])->assertCreated();
        $this->postJson(route('reviews.changes', $video))->assertOk()->assertJsonPath('status', 'changes');

        // Annamalai can no longer approve this version...
        $this->actingAs($annamalai)->postJson(route('reviews.approve', $video))->assertStatus(422);
        // ...but a comment from him becomes one more change in the editor's To do.
        $this->postJson(route('reviews.comment', $video), ['body' => 'Music too loud', 'at_seconds' => 30])->assertCreated()
            ->assertJsonPath('latest', true);

        $this->assertSame(['0:02 — Too dark', '0:30 — Music too loud'], $this->card->fresh()->corrections()->orderBy('id')->pluck('title')->all());
        $this->assertSame(Todo::STATUS_WAITING, $this->card->fresh()->status);

        // The editor's own reply-style note on the old version is just a note.
        $this->actingAs($this->editor)->postJson(route('reviews.comment', $video), ['body' => 'On it'])->assertCreated();
        $this->assertSame(2, $this->card->fresh()->corrections()->count());

        // Once v2 is up, comments on v1 are notes only.
        $this->uploadAs($this->editor)->assertCreated();
        $this->actingAs($annamalai)->postJson(route('reviews.comment', $video), ['body' => 'Late note', 'at_seconds' => 5])->assertCreated()
            ->assertJsonPath('latest', false);
        $this->assertSame(0, $this->card->fresh()->corrections()->open()->count());
    }

    public function test_on_version_two_reviewers_see_and_check_the_changes_from_version_one(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $v1 = ReviewVideo::first();
        $this->actingAs($this->admin)->postJson(route('reviews.comment', $v1), ['body' => 'Logo too small', 'at_seconds' => 4])->assertCreated();
        $this->postJson(route('reviews.comment', $v1), ['body' => 'Too dark', 'at_seconds' => 9])->assertCreated();
        $this->postJson(route('reviews.changes', $v1))->assertOk();

        $this->uploadAs($this->editor)->assertCreated();
        $v2 = ReviewVideo::where('version', 2)->first();

        $state = $this->actingAs($this->admin)->getJson(route('reviews.live', $v2))->assertOk()
            ->assertJsonPath('versions.0.version', 1)->assertJsonPath('versions.1.current', true)
            ->assertJsonCount(2, 'previous_changes')
            ->assertJsonPath('previous_changes.0.body', 'Logo too small')
            ->assertJsonPath('previous_changes.0.editor_done', true)
            ->assertJsonPath('previous_changes.0.state', null);

        $logo = ReviewComment::where('body', 'Logo too small')->first();
        $dark = ReviewComment::where('body', 'Too dark')->first();
        $this->postJson(route('reviews.comment.fix', $logo), ['state' => 'confirmed', 'on' => $v2->id])->assertOk()
            ->assertJsonPath('previous_changes.0.state', 'confirmed')->assertJsonPath('previous_changes.0.checked_by', 'Keerthi');

        // Not fixed: raised again on v2 at the same moment.
        $this->postJson(route('reviews.comment.fix', $dark), ['state' => 'not_fixed', 'on' => $v2->id])->assertOk()
            ->assertJsonPath('previous_changes.1.state', 'not_fixed');
        $this->assertSame(['Still not fixed: Too dark'], $v2->comments()->pluck('body')->all());
        $this->assertSame(9.0, $v2->comments()->first()->at_seconds);
        // Twice is still once.
        $this->postJson(route('reviews.comment.fix', $dark), ['state' => 'not_fixed', 'on' => $v2->id])->assertOk();
        $this->assertSame(1, $v2->comments()->count());

        // The editor sees it, but cannot check fixes.
        $this->actingAs($this->editor)->postJson(route('reviews.comment.fix', $logo), ['state' => 'clear', 'on' => $v2->id])->assertForbidden();
        $this->actingAs($this->admin)->get(route('reviews.show', $v2))->assertOk()->assertSee('Changes from earlier versions');
    }

    /** Gemini answering every step of a check, counting the checks it is asked for. */
    private function fakeGemini(int &$asked, array &$pieces = []): void
    {
        config(['ai.review.enabled' => true]);
        AiSetting::current()->forceFill(['gemini_api_key' => 'AIza-test', 'gemini_monthly_cap_usd' => 5])->save();
        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response([], 200, ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session/1']),
            'generativelanguage.googleapis.com/upload/session/*' => function ($r) use (&$pieces) {
                if ($r->header('X-Goog-Upload-Command')[0] === 'query') {
                    return Http::response('', 200, ['X-Goog-Upload-Status' => 'active', 'X-Goog-Upload-Size-Received' => '500']);
                }
                $pieces[] = [(int) $r->header('X-Goog-Upload-Offset')[0], strlen($r->body())];

                return Http::response(['file' => ['name' => 'files/abc', 'uri' => 'https://g/files/abc', 'state' => 'ACTIVE', 'mimeType' => 'video/mp4']]);
            },
            'generativelanguage.googleapis.com/v1beta/files/*' => Http::response([]),
            'generativelanguage.googleapis.com/*' => function ($r) use (&$asked) {
                if (! str_contains($r->url(), ':generateContent')) {
                    return Http::response([]);
                }
                $asked++;

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['verdict' => 'good_to_go', 'score' => 9, 'summary' => 'Fine', 'issues' => []])]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
            },
        ]);
    }

    public function test_only_the_first_version_gets_the_ai_check_later_ones_open_on_what_to_check(): void
    {
        Notification::fake();
        $asked = 0;
        $this->fakeGemini($asked);

        $this->uploadAs($this->editor)->assertCreated();
        $v1 = ReviewVideo::first();
        $this->assertSame('done', $v1->fresh()->ai_status);
        $this->actingAs($this->admin)->postJson(route('reviews.comment', $v1), ['body' => 'Logo too small', 'at_seconds' => 4])->assertCreated();
        $this->postJson(route('reviews.changes', $v1))->assertOk();

        $this->uploadAs($this->editor)->assertCreated();
        $v2 = ReviewVideo::where('version', 2)->first();
        $this->artisan('reviews:housekeeping')->assertSuccessful();

        $this->assertSame('none', $v2->fresh()->ai_status);
        $this->assertSame(1, $asked, 'Gemini checked v1 only.');
        $this->actingAs($this->admin)->getJson(route('reviews.live', $v2))->assertOk()
            ->assertJsonPath('ai_applies', false)
            ->assertJsonPath('previous_changes.0.body', 'Logo too small');
        $this->postJson(route('reviews.recheck', $v2))->assertStatus(422);
        $this->get(route('reviews.show', $v2))->assertOk()->assertSee('To check')->assertSee('What to check in v');
        $this->assertSame(1, $asked);
    }

    public function test_version_three_checks_the_changes_from_version_two_and_older_ones_never_checked(): void
    {
        Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $v1 = ReviewVideo::first();
        $this->actingAs($this->admin)->postJson(route('reviews.comment', $v1), ['body' => 'Logo too small', 'at_seconds' => 4])->assertCreated();
        $this->postJson(route('reviews.comment', $v1), ['body' => 'Too dark', 'at_seconds' => 9])->assertCreated();
        $this->postJson(route('reviews.comment', $v1), ['body' => 'Music loud'])->assertCreated();
        $this->postJson(route('reviews.changes', $v1))->assertOk();

        $this->uploadAs($this->editor)->assertCreated();
        $v2 = ReviewVideo::where('version', 2)->first();
        $logo = ReviewComment::where('body', 'Logo too small')->first();
        $dark = ReviewComment::where('body', 'Too dark')->first();
        $this->actingAs($this->admin)->postJson(route('reviews.comment.fix', $logo), ['state' => 'confirmed', 'on' => $v2->id])->assertOk();
        $this->postJson(route('reviews.comment.fix', $dark), ['state' => 'not_fixed', 'on' => $v2->id])->assertOk();
        $this->postJson(route('reviews.comment', $v2), ['body' => 'Wrong phone number', 'at_seconds' => 20])->assertCreated();
        $this->postJson(route('reviews.changes', $v2))->assertOk();

        $this->uploadAs($this->editor)->assertCreated();
        $v3 = ReviewVideo::where('version', 3)->first();

        // v2's changes (the re-raised one included), then v1's never checked;
        // v1's already checked ones are settled.
        $rows = collect($this->actingAs($this->admin)->getJson(route('reviews.live', $v3))->assertOk()->json('previous_changes'));
        $this->assertSame(['Still not fixed: Too dark', 'Wrong phone number', 'Music loud'], $rows->pluck('body')->all());
        $this->assertSame([2, 2, 1], $rows->pluck('version')->all());
    }

    public function test_a_check_cut_off_part_way_is_carried_on_from_where_it_got_to(): void
    {
        Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $asked = 0;
        $pieces = [];
        $this->fakeGemini($asked, $pieces);
        // A worker sent half the file, then was cut off; its lease ran out.
        $video->forceFill(['ai_status' => 'running', 'ai_stage' => 'uploading', 'ai_attempts' => 1, 'ai_started_at' => now()->subMinutes(2),
            'ai_upload_url' => 'https://generativelanguage.googleapis.com/upload/session/1', 'ai_upload_offset' => 500, 'ai_lease_until' => now()->subSecond()])->saveQuietly();

        $this->artisan('reviews:housekeeping')->assertSuccessful();

        $video->refresh();
        $this->assertSame('done', $video->ai_status, (string) $video->ai_error);
        $this->assertSame([[500, 500]], $pieces, 'Only the second half was sent.');
        $this->assertSame(1, $video->ai_attempts, 'Carrying on is not a new try.');
        $this->assertNull($video->ai_lease_until);
    }

    public function test_a_worker_on_a_check_is_not_doubled_and_a_check_past_its_time_fails_visibly(): void
    {
        Notification::fake();
        $this->uploadAs($this->editor)->assertCreated();
        $video = ReviewVideo::first();
        $asked = 0;
        $this->fakeGemini($asked);

        // Someone is on it (lease held): the page and the job leave it alone.
        $video->forceFill(['ai_status' => 'running', 'ai_stage' => 'watching', 'ai_attempts' => 1, 'ai_started_at' => now()->subMinutes(16),
            'ai_file' => ['name' => 'files/abc', 'uri' => 'u', 'state' => 'ACTIVE'], 'ai_lease_until' => now()->addMinute()])->saveQuietly();
        $this->actingAs($this->admin)->getJson(route('reviews.live', $video))->assertOk()->assertJsonPath('ai.status', 'running');
        $this->artisan('reviews:housekeeping')->assertSuccessful();
        $this->assertSame('running', $video->fresh()->ai_status);
        $this->assertSame(0, $asked);

        // Its worker died and the 15 minutes are up: failed, said so, not retried by itself.
        $video->forceFill(['ai_lease_until' => now()->subSecond()])->saveQuietly();
        $this->getJson(route('reviews.live', $video))->assertOk();
        $video->refresh();
        $this->assertSame('failed', $video->ai_status);
        $this->assertStringContainsString('longer than 15 minutes', $video->ai_error);
        $this->assertSame(0, $asked);
        $this->artisan('reviews:housekeeping')->assertSuccessful();
        $this->assertSame('failed', $video->fresh()->ai_status);
        $this->getJson(route('reviews.live', $video))->assertJsonPath('ai.status', 'failed')->assertJsonPath('ai.stage', null);

        // The job alone fails one nobody has open either.
        $video->forceFill(['ai_status' => 'running', 'ai_stage' => 'uploading', 'ai_started_at' => now()->subMinutes(20), 'ai_lease_until' => null])->saveQuietly();
        $this->artisan('reviews:housekeeping')->assertSuccessful();
        $this->assertSame('failed', $video->fresh()->ai_status);

        // Check again starts over, and finishes.
        $this->postJson(route('reviews.recheck', $video))->assertOk();
        $this->assertSame('done', $video->fresh()->ai_status);
        $this->assertSame(1, $asked);
    }
}
