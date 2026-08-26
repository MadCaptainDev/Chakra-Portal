<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\CompetitorSetting;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auto-generating the description + creative-strategy fields from Anthropic
 * when a piece is first mapped to an Instagram post, the manual "Regenerate"
 * action, and the token-usage log every call writes to (see AiUsageLog).
 */
class PortfolioAiGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function client(string $name = 'Chakra Production'): Client
    {
        return Client::create(['name' => $name]);
    }

    private static int $nextPlatformUserId = 37841476964090891;

    private function connectedAccount(?Client $client = null): SocialAccount
    {
        $client ??= $this->client();
        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => (string) self::$nextPlatformUserId++,
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now(), 'last_synced_at' => now()->subHour()])->save();

        return $account->fresh();
    }

    private function media(SocialAccount $account, string $platformMediaId = '18000000000000199'): SocialMediaItem
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => $platformMediaId,
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => 'Unboxing our new summer collection in one take.',
            'permalink' => 'https://www.instagram.com/p/CxAbCdEfGh/',
            'posted_at' => now()->subDays(2),
            'cached_at' => now(),
        ]);

        SocialInsight::record([
            'social_account_id' => $account->id,
            'social_media_item_id' => $media->id,
            'metric' => 'views',
            'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => 12000,
            'period' => 'lifetime',
            'period_start' => now()->toDateString(),
        ]);

        return $media->fresh();
    }

    private function fakeAnthropicSuccess(array $fields, int $inputTokens = 210, int $outputTokens = 90): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($fields)]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ], 200)]);
    }

    private function creativeFields(): array
    {
        return [
            'description' => 'A punchy summer-collection unboxing reel.',
            'creative_hook' => 'Box opens on beat one.',
            'creative_concept' => 'Unboxing as reveal.',
            'creative_storytelling' => 'Single continuous take, no cuts.',
            'creative_cta' => 'Shop the link in bio.',
            'creative_offer' => 'None',
            'creative_audience' => 'Existing followers browsing new arrivals.',
        ];
    }

    public function test_creating_a_mapped_piece_auto_generates_the_creative_strategy(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $this->fakeAnthropicSuccess($this->creativeFields());

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Summer reel', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $item = PortfolioItem::firstOrFail();
        $this->assertSame('A punchy summer-collection unboxing reel.', $item->description);
        $this->assertSame('Box opens on beat one.', $item->creative_hook);
        $this->assertSame('Shop the link in bio.', $item->creative_cta);
    }

    public function test_auto_generation_never_overwrites_fields_staff_already_typed(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $this->fakeAnthropicSuccess($this->creativeFields());

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Summer reel', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
            'description' => 'Written by a human, keep this exactly.',
        ]);

        $item = PortfolioItem::firstOrFail();
        $this->assertSame('Written by a human, keep this exactly.', $item->description);
        Http::assertNothingSent();
    }

    public function test_auto_generation_is_skipped_without_an_anthropic_key(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);
        Http::fake();

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Summer reel', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);

        Http::assertNothingSent();
        // mapToInstagram() still falls back to the caption as a description
        // with no AI involved at all -- it is specifically the AI-only
        // creative fields that must stay empty here.
        $this->assertNull(PortfolioItem::firstOrFail()->creative_hook);
        $this->assertSame(0, AiUsageLog::count());
    }

    public function test_auto_generation_is_skipped_for_a_piece_not_mapped_to_instagram(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        Http::fake();

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Hand-typed piece', 'client_id' => $this->client()->id, 'is_visible' => '1',
        ]);

        Http::assertNothingSent();
    }

    public function test_a_failed_anthropic_call_does_not_block_saving_the_piece(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $account = $this->connectedAccount();
        $media = $this->media($account);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Summer reel', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $this->assertSame(1, PortfolioItem::count());
        // The save itself must succeed regardless -- only the AI-only
        // creative fields stay empty when the call fails.
        $this->assertNull(PortfolioItem::firstOrFail()->creative_hook);
    }

    public function test_every_call_is_logged_with_its_token_usage_and_cost(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $this->fakeAnthropicSuccess($this->creativeFields(), inputTokens: 1_000_000, outputTokens: 1_000_000);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Summer reel', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);

        $log = AiUsageLog::firstOrFail();
        $this->assertSame('portfolio_creative', $log->purpose);
        $this->assertSame('claude-sonnet-5', $log->model);
        $this->assertSame(1_000_000, $log->input_tokens);
        $this->assertSame(1_000_000, $log->output_tokens);
        // $3/MTok in + $15/MTok out at 1M tokens each = $18.00.
        $this->assertSame(18.0, $log->estimated_cost_usd);
        $this->assertSame(PortfolioItem::firstOrFail()->id, $log->portfolio_item_id);
    }

    public function test_regenerate_overwrites_existing_fields_on_demand(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $admin = $this->admin();

        // A sequence, not two separate fake() calls -- Http::fake() checks
        // registered stub patterns in registration order and the first
        // match wins, so a second fake() call for the same 'api.anthropic.
        // com/*' pattern would never actually be reached.
        $rewrittenFields = $this->creativeFields();
        $rewrittenFields['creative_hook'] = 'Completely rewritten hook.';

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode($this->creativeFields())]], 'usage' => ['input_tokens' => 200, 'output_tokens' => 80]])
            ->push(['content' => [['type' => 'text', 'text' => json_encode($rewrittenFields)]], 'usage' => ['input_tokens' => 200, 'output_tokens' => 80]])]);

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'Summer reel', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);
        $item = PortfolioItem::firstOrFail();
        $this->assertSame('Box opens on beat one.', $item->creative_hook);

        $this->actingAs($admin)->post(route('portfolio.regenerate-creative', $item))
            ->assertRedirect();

        $this->assertSame('Completely rewritten hook.', $item->fresh()->creative_hook);
        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_regenerate_refuses_a_piece_with_no_mapped_instagram_post(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $item = PortfolioItem::create(['title' => 'Hand-typed piece', 'is_visible' => true]);
        Http::fake();

        $this->actingAs($this->admin())->post(route('portfolio.regenerate-creative', $item))
            ->assertRedirect();

        Http::assertNothingSent();
    }
}
