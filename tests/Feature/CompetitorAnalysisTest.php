<?php

namespace Tests\Feature;

use App\Models\CompetitorAccount;
use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use App\Services\Competitors\ApifyClient;
use App\Services\Competitors\CompetitorAnalysisException;
use App\Services\Competitors\ConceptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The competitor-reel-analysis pipeline: settings, the ranking bar, and the
 * three external-API plumbing classes (Apify, Gemini, Anthropic), faked at
 * the HTTP boundary the same way PushSenderTest fakes Firebase's.
 */
class CompetitorAnalysisTest extends TestCase
{
    use RefreshDatabase;

    // -- CompetitorSetting -----------------------------------------------

    public function test_settings_is_a_singleton_row(): void
    {
        $first = CompetitorSetting::current();
        $second = CompetitorSetting::current();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CompetitorSetting::count());
    }

    public function test_the_service_account_style_keys_are_encrypted_at_rest(): void
    {
        $settings = CompetitorSetting::current();
        $settings->update([
            'apify_token' => 'apify-secret',
            'gemini_api_key' => 'gemini-secret',
            'anthropic_api_key' => 'anthropic-secret',
        ]);

        $raw = DB::table('competitor_settings')->where('id', $settings->id)->first();

        $this->assertStringNotContainsString('apify-secret', $raw->apify_token);
        $this->assertStringNotContainsString('gemini-secret', $raw->gemini_api_key);
        $this->assertStringNotContainsString('anthropic-secret', $raw->anthropic_api_key);

        $this->assertSame('apify-secret', $settings->fresh()->apify_token);
    }

    public function test_is_fully_configured_needs_all_three_keys(): void
    {
        $settings = CompetitorSetting::current();

        $this->assertFalse($settings->isFullyConfigured());

        $settings->update(['apify_token' => 'a', 'gemini_api_key' => 'g']);
        $this->assertFalse($settings->fresh()->isFullyConfigured());

        $settings->update(['anthropic_api_key' => 'c']);
        $this->assertTrue($settings->fresh()->isFullyConfigured());
    }

    // -- CompetitorReel::isViral() -----------------------------------------

    private function account(array $overrides = []): CompetitorAccount
    {
        return CompetitorAccount::create($overrides + [
            'username' => 'a_competitor',
            'platform' => CompetitorAccount::PLATFORM_INSTAGRAM,
            'avg_views_30d' => 10000,
        ]);
    }

    public function test_a_reel_beating_its_accounts_own_average_is_viral(): void
    {
        $account = $this->account(['avg_views_30d' => 10000]);
        $reel = CompetitorReel::create([
            'competitor_account_id' => $account->id,
            'platform_post_id' => 'https://www.instagram.com/p/1/',
            'play_count' => 50000,
            'scraped_at' => now(),
        ]);

        $this->assertTrue($reel->fresh(['account'])->isViral());
    }

    public function test_a_reel_below_its_accounts_own_average_is_not_viral(): void
    {
        $account = $this->account(['avg_views_30d' => 10000]);
        $reel = CompetitorReel::create([
            'competitor_account_id' => $account->id,
            'platform_post_id' => 'https://www.instagram.com/p/1/',
            'play_count' => 4000,
            'scraped_at' => now(),
        ]);

        $this->assertFalse($reel->fresh(['account'])->isViral());
    }

    public function test_a_reel_is_never_viral_without_a_known_account_average(): void
    {
        $account = $this->account(['avg_views_30d' => null]);
        $reel = CompetitorReel::create([
            'competitor_account_id' => $account->id,
            'platform_post_id' => 'https://www.instagram.com/p/1/',
            'play_count' => 999999,
            'scraped_at' => now(),
        ]);

        $this->assertFalse($reel->fresh(['account'])->isViral());
    }

    public function test_not_analyzed_scope_excludes_reels_with_an_analysis(): void
    {
        $account = $this->account();
        $analyzed = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p1',
            'play_count' => 100, 'scraped_at' => now(),
        ]);
        $unanalyzed = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p2',
            'play_count' => 200, 'scraped_at' => now(),
        ]);
        CompetitorReelAnalysis::create([
            'competitor_reel_id' => $analyzed->id, 'breakdown' => 'x',
            'gemini_model' => 'gemini-2.5-flash', 'analyzed_at' => now(),
        ]);

        $result = CompetitorReel::notAnalyzed()->pluck('id');

        $this->assertTrue($result->contains($unanalyzed->id));
        $this->assertFalse($result->contains($analyzed->id));
    }

    public function test_highest_views_first_orders_by_play_count_descending(): void
    {
        $account = $this->account();
        $low = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p1',
            'play_count' => 100, 'scraped_at' => now(),
        ]);
        $high = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p2',
            'play_count' => 900, 'scraped_at' => now(),
        ]);

        $ordered = CompetitorReel::highestViewsFirst()->pluck('id');

        $this->assertSame([$high->id, $low->id], $ordered->all());
    }

    // -- ApifyClient ---------------------------------------------------------

    public function test_apify_client_returns_scraped_reels(): void
    {
        Http::fake([
            'api.apify.com/*' => Http::response([
                ['url' => 'https://www.instagram.com/p/1/', 'videoUrl' => 'https://cdn/vid1.mp4', 'videoPlayCount' => 5000],
            ], 200),
        ]);

        $reels = (new ApifyClient('token-123'))->scrapeReels('a_competitor', 10, 30);

        $this->assertCount(1, $reels);
        $this->assertSame(5000, $reels[0]['videoPlayCount']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'apify~instagram-scraper')
            && str_contains($request->url(), 'token=token-123')
            && $request['resultsType'] === 'stories');
    }

    public function test_apify_clients_error_is_reported_verbatim(): void
    {
        Http::fake(['api.apify.com/*' => Http::response('Actor run failed: invalid input', 400)]);

        $this->expectException(CompetitorAnalysisException::class);
        $this->expectExceptionMessage('Actor run failed: invalid input');

        (new ApifyClient('token-123'))->scrapeReels('a_competitor', 10, 30);
    }

    // -- ConceptGenerator ------------------------------------------------

    public function test_concept_generator_sends_the_breakdown_and_brand_prompt_to_claude(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Here are three new Reel concepts...']],
            ], 200),
        ]);

        $result = (new ConceptGenerator('sk-ant-test'))->generateConcepts(
            'A shot-by-shot breakdown of a viral reel.',
            'Adapt this for a jewellery brand, warm and celebratory tone.',
        );

        $this->assertSame('Here are three new Reel concepts...', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-ant-test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && str_contains($request['messages'][0]['content'], 'A shot-by-shot breakdown of a viral reel.')
                && str_contains($request['messages'][0]['content'], 'jewellery brand');
        });
    }

    public function test_anthropics_error_is_reported_verbatim(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $this->expectException(CompetitorAnalysisException::class);
        $this->expectExceptionMessage('invalid x-api-key');

        (new ConceptGenerator('bad-key'))->generateConcepts('x', 'y');
    }

    // -- competitors:analyze (CLI) ----------------------------------------

    private function geminiFakes(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/upload/*' => Http::response([
                'file' => ['name' => 'files/abc123', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123', 'mimeType' => 'video/mp4'],
            ], 200),
            'generativelanguage.googleapis.com/v1beta/files/*' => Http::response(['state' => 'ACTIVE'], 200),
            'generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "some preamble\n# Breakdown\nThe hook lands in the first second."]]]]],
            ], 200),
            'cdn.example.com/*' => Http::response('fake video bytes', 200, ['Content-Type' => 'video/mp4']),
        ]);
    }

    public function test_analyze_command_refuses_without_a_gemini_key(): void
    {
        $this->artisan('competitors:analyze')
            ->expectsOutputToContain('No Gemini API key set')
            ->assertExitCode(1);
    }

    public function test_analyze_command_stores_a_breakdown_for_the_top_reel(): void
    {
        CompetitorSetting::current()->update(['gemini_api_key' => 'gemini-key']);
        $account = $this->account();
        $reel = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p1',
            'video_url' => 'https://cdn.example.com/vid1.mp4', 'play_count' => 500, 'scraped_at' => now(),
        ]);
        $this->geminiFakes();

        $this->artisan('competitors:analyze')->assertExitCode(0);

        $analysis = CompetitorReelAnalysis::where('competitor_reel_id', $reel->id)->first();
        $this->assertNotNull($analysis);
        $this->assertStringStartsWith('# Breakdown', $analysis->breakdown);
    }

    public function test_a_reel_missing_its_video_url_is_skipped_not_fatal(): void
    {
        CompetitorSetting::current()->update(['gemini_api_key' => 'gemini-key']);
        $account = $this->account();
        CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'p1',
            'video_url' => null, 'play_count' => 500, 'scraped_at' => now(),
        ]);

        $this->artisan('competitors:analyze')->assertExitCode(0);

        $this->assertSame(0, CompetitorReelAnalysis::count());
    }

    public function test_one_failed_reel_does_not_stop_the_rest_of_the_batch(): void
    {
        CompetitorSetting::current()->update(['gemini_api_key' => 'gemini-key']);
        $account = $this->account();
        $bad = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'bad',
            'video_url' => 'https://cdn.example.com/dead.mp4', 'play_count' => 999, 'scraped_at' => now(),
        ]);
        $good = CompetitorReel::create([
            'competitor_account_id' => $account->id, 'platform_post_id' => 'good',
            'video_url' => 'https://cdn.example.com/vid1.mp4', 'play_count' => 500, 'scraped_at' => now(),
        ]);

        Http::fake([
            'cdn.example.com/dead.mp4' => Http::response('', 404),
            'cdn.example.com/vid1.mp4' => Http::response('fake video bytes', 200, ['Content-Type' => 'video/mp4']),
            'generativelanguage.googleapis.com/upload/*' => Http::response([
                'file' => ['name' => 'files/abc123', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123', 'mimeType' => 'video/mp4'],
            ], 200),
            'generativelanguage.googleapis.com/v1beta/files/*' => Http::response(['state' => 'ACTIVE'], 200),
            'generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '# Breakdown']]]]],
            ], 200),
        ]);

        $this->artisan('competitors:analyze')->assertExitCode(1);

        $this->assertNull(CompetitorReelAnalysis::where('competitor_reel_id', $bad->id)->first());
        $this->assertNotNull(CompetitorReelAnalysis::where('competitor_reel_id', $good->id)->first());
    }
}
