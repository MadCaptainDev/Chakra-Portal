<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\CompetitorAccount;
use App\Models\CompetitorBrainLog;
use App\Models\CompetitorBrainNote;
use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use App\Models\User;
use App\Services\Competitors\ApifyClient;
use App\Services\Competitors\BrainQueue;
use App\Services\Competitors\CompetitorBrain;
use App\Services\Competitors\CompetitorScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The competitor brain: every reel's script, where people like and share it,
 * and a memory that learns (and corrects itself) reel by reel, with a log.
 */
class CompetitorBrainTest extends TestCase
{
    use RefreshDatabase;

    private function account(): CompetitorAccount
    {
        return CompetitorAccount::create(['username' => 'sandra', 'platform' => CompetitorAccount::PLATFORM_INSTAGRAM, 'avg_views_30d' => 100000]);
    }

    private function reel(CompetitorAccount $account, array $extra = []): CompetitorReel
    {
        return CompetitorReel::create($extra + [
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p'.random_int(1, 999999),
            'video_url' => 'https://cdn.example.com/v.mp4', 'play_count' => 300000, 'like_count' => 15000, 'comment_count' => 200,
            'scraped_at' => now(), 'comments' => [['text' => '@priya see this!', 'likes' => 3], ['text' => 'Which shade?', 'likes' => 1]],
        ]);
    }

    private static function readJson(): string
    {
        return json_encode([
            'language' => 'Tamil',
            'transcript' => [['at' => '0:00', 'said' => 'Dusky girls', 'english' => 'Dusky girls', 'on_screen' => 'Best lip shades']],
            'hook' => 'Audience callout + film meme', 'format' => '3-item listicle', 'topic' => 'lip shades',
            'like_moments' => [['at' => '0:04', 'what' => 'swatch', 'why' => 'satisfying']],
            'share_moments' => [['at' => '0:10', 'what' => 'price', 'why' => 'tag a friend']],
            'comment_read' => ['themes' => ['shade names'], 'quoted_lines' => [], 'questions' => []],
            'summary' => 'A listicle.', 'breakdown' => '## Hook',
        ]);
    }

    /** Gemini: a video question gets the reel JSON, a text question gets $brain. */
    private function fakeGemini(array $brain): void
    {
        Http::fake(function (Request $request) use ($brain) {
            $url = $request->url();
            if (str_contains($url, 'cdn.example.com')) {
                return Http::response('bytes', 200, ['Content-Type' => 'video/mp4']);
            }
            if (str_contains($url, '/upload/')) {
                return Http::response(['file' => ['name' => 'files/a', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/a', 'mimeType' => 'video/mp4']]);
            }
            if (str_contains($url, '/files/')) {
                return Http::response(['state' => 'ACTIVE']);
            }
            $isVideo = str_contains(json_encode($request->data()), 'fileData');
            $text = $isVideo ? self::readJson() : (is_string($brain['text'] ?? null) ? $brain['text'] : json_encode($brain));

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
        });
    }

    public function test_the_scrape_keeps_every_recent_video_with_its_comments_and_music(): void
    {
        $account = $this->account();
        $item = fn (string $id, int $views) => [
            'id' => $id, 'shortCode' => 'S'.$id, 'videoUrl' => "https://cdn.example.com/{$id}.mp4", 'videoPlayCount' => $views,
            'likesCount' => 10, 'commentsCount' => 2, 'videoDuration' => 31.44, 'hashtags' => ['tamil'], 'timestamp' => '2026-11-10T10:00:00Z',
            'musicInfo' => ['song_name' => 'Original audio', 'artist_name' => 'sandra', 'uses_original_audio' => true],
            'latestComments' => [['text' => '@ravi look', 'likesCount' => 4]],
        ];
        $apify = $this->mock(ApifyClient::class);
        $apify->shouldReceive('scrapeProfile')->andReturn(['profilePicUrl' => null, 'followersCount' => 5000]);
        $apify->shouldReceive('scrapeRecentPosts')->andReturn([$item('1', 100), $item('2', 200), ['id' => '3', 'type' => 'Image']]);
        $apify->shouldReceive('scrapeReels')->andReturn([$item('2', 200), $item('4', 400)]);

        $result = (new CompetitorScraper($apify))->scrape($account);

        $this->assertSame(3, $result['reels']); // 1, 2, 4 -- the image and the duplicate are left out
        $reel = CompetitorReel::where('platform_post_id', '4')->first();
        $this->assertSame(31.4, $reel->duration_seconds);
        $this->assertSame(['@ravi look'], array_column($reel->comments, 'text'));
        $this->assertTrue($reel->music['original']);
        $this->assertSame(1, $reel->friendTags());
    }

    public function test_one_gemini_pass_takes_the_script_and_the_like_and_share_moments(): void
    {
        AiSetting::current()->forceFill(['gemini_api_key' => 'review-key'])->save();
        $reel = $this->reel($this->account());
        $this->fakeGemini([]);

        $analysis = \App\Services\Competitors\ReelAnalysis::run($reel);

        $this->assertSame('Dusky girls', $analysis->transcript[0]['said']);
        $this->assertSame('tag a friend', $analysis->share_moments[0]['why']);
        $this->assertSame(1, $analysis->comment_read['tags_a_friend']);
        $this->assertSame(config('ai.review.models')[0], $analysis->gemini_model); // the reel review's Gemini
        $this->assertSame(1, CompetitorBrainLog::where('event', 'scripted')->count());
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'review-key'));
    }

    public function test_the_brain_learns_confirms_and_retires_and_logs_each_step(): void
    {
        AiSetting::current()->forceFill(['gemini_api_key' => 'k'])->save();
        $account = $this->account();
        $weak = CompetitorBrainNote::create(['competitor_account_id' => $account->id, 'category' => 'hook', 'insight' => 'Plain intros work', 'strength' => 1]);
        $strong = CompetitorBrainNote::create(['competitor_account_id' => null, 'category' => 'format', 'insight' => 'Listicles of 3 win', 'strength' => 2]);
        $analysis = CompetitorReelAnalysis::create(['competitor_reel_id' => $this->reel($account)->id, 'breakdown' => '## x', 'gemini_model' => 'm',
            'analyzed_at' => now(), 'transcript' => [['at' => '0:00', 'said' => 'hi', 'english' => 'hi', 'on_screen' => '']], 'format' => 'listicle']);
        $this->fakeGemini([
            'confirm' => [['id' => $strong->id, 'why' => 'another 3-item list beat the average 3x']],
            'contradict' => [['id' => $weak->id, 'why' => 'this one opens on a meme and won']],
            'new' => [['category' => 'share', 'scope' => 'account', 'insight' => 'Price reveals get friends tagged', 'why' => 'comments tag friends']],
        ]);

        $done = CompetitorBrain::learnFrom($analysis);

        $this->assertSame(['confirmed' => 1, 'contradicted' => 1, 'learned' => 1, 'retired' => 1], $done);
        $this->assertSame(3, $strong->fresh()->strength);
        $this->assertSame('retired', $weak->fresh()->status);
        $this->assertSame($account->id, CompetitorBrainNote::where('category', 'share')->value('competitor_account_id'));
        $this->assertEqualsCanonicalizing(['confirmed', 'retired', 'learned'], CompetitorBrainLog::pluck('event')->all());
        $this->assertNotNull($analysis->fresh()->learned_at);
    }

    public function test_learn_from_all_reels_runs_through_the_queue_one_step_at_a_time(): void
    {
        AiSetting::current()->forceFill(['gemini_api_key' => 'k'])->save();
        $account = $this->account();
        $this->reel($account);
        $this->reel($account, ['play_count' => 50]);
        $this->reel($account, ['scraped_at' => now()->subDays(10)]); // dead link: never queued
        $this->fakeGemini(['new' => [['category' => 'hook', 'scope' => 'account', 'insight' => 'Meme openers win']]]);

        $this->assertSame(0, BrainQueue::pendingCount()); // nothing until someone asks
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('competitors.learn', $account))->assertSessionHas('status');
        // The press itself ran one step after the response; the rest via the command.
        $this->artisan('competitors:learn')->assertExitCode(0);

        $this->assertSame(0, BrainQueue::pendingCount());
        $this->assertSame(2, CompetitorReelAnalysis::whereNotNull('transcript')->whereNotNull('learned_at')->count());
        $this->assertSame(2, CompetitorBrainNote::count());
    }

    public function test_a_reel_whose_video_will_not_download_leaves_the_queue_with_a_log_line(): void
    {
        AiSetting::current()->forceFill(['gemini_api_key' => 'k'])->save();
        $account = $this->account();
        $account->forceFill(['learning_requested_at' => now()])->save();
        $reel = $this->reel($account);
        Http::fake(['cdn.example.com/*' => Http::response('', 403)]);

        $this->assertStringContainsString('script failed', (string) BrainQueue::step());

        $this->assertSame(0, BrainQueue::pendingCount());
        $this->assertSame('failed', CompetitorBrainLog::latest('id')->value('event'));
        $this->assertNull($reel->fresh()->analysis);
    }

    public function test_the_brain_page_shows_notes_and_log_and_answers_questions(): void
    {
        AiSetting::current()->forceFill(['gemini_api_key' => 'k'])->save();
        $account = $this->account();
        CompetitorBrainNote::create(['competitor_account_id' => $account->id, 'category' => 'share', 'insight' => 'Price reveals get friends tagged', 'strength' => 4, 'evidence' => [1, 2]]);
        CompetitorBrainLog::write('learned', 'Price reveals get friends tagged', ['competitor_account_id' => $account->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->withoutVite();

        $this->actingAs($admin)->get(route('competitors.brain'))->assertOk()
            ->assertSee('What gets shared')->assertSee('Price reveals get friends tagged')->assertSee('Learning log');

        $this->fakeGemini(['text' => "**Open on a price reveal.** It gets friends tagged."]);
        $this->actingAs($admin)->from(route('competitors.brain'))->post(route('competitors.brain.ask'), ['question' => 'What makes people share?'])
            ->assertRedirect(route('competitors.brain'))->assertSessionHas('brain_answer');
        $this->assertSame('asked', CompetitorBrainLog::latest('id')->value('event'));
        $this->assertStringContainsString('price reveal', CompetitorBrainLog::latest('id')->value('detail'));
    }

    public function test_an_employee_without_the_module_cannot_open_the_brain(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->get(route('competitors.brain'))->assertForbidden();
    }
}
