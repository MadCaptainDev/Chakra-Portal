<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Support\PortfolioSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "This is doing well and isn't in the portfolio yet." See
 * PortfolioSuggestions::best()'s own docblock for why there is only ever
 * one candidate, never a list.
 */
class PortfolioSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private static int $nextPlatformUserId = 17841470000000001;

    private function connectedAccount(?Client $client = null): SocialAccount
    {
        $client ??= Client::create(['name' => 'Chakra Production']);
        $platformUserId = (string) self::$nextPlatformUserId++;

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill([
            'access_token' => 'IGQV-token',
            'connected_at' => now(),
            'last_synced_at' => now()->subHour(),
        ])->save();

        return $account->fresh();
    }

    private function media(SocialAccount $account, array $metrics, string $platformMediaId): SocialMediaItem
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => $platformMediaId,
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => 'A caption',
            'permalink' => 'https://www.instagram.com/p/'.$platformMediaId.'/',
            'posted_at' => now()->subDays(3),
            'cached_at' => now(),
        ]);

        foreach ($metrics as $metric => $value) {
            SocialInsight::record([
                'social_account_id' => $account->id,
                'social_media_item_id' => $media->id,
                'metric' => $metric,
                'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
                'value' => $value,
                'period' => 'lifetime',
                'period_start' => now()->toDateString(),
            ]);
        }

        return $media->fresh();
    }

    public function test_a_post_clearing_the_fallback_floor_is_suggested_when_the_portfolio_has_no_scored_pieces(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['views' => 50000], '1');

        $best = PortfolioSuggestions::best();

        $this->assertNotNull($best);
        $this->assertTrue($media->is($best['media']));
        $this->assertSame('views', $best['metric']);
        $this->assertSame(50000, $best['value']);
        $this->assertSame($account->client_id, $best['clientId']);
    }

    public function test_a_post_below_the_fallback_floor_is_not_suggested(): void
    {
        $account = $this->connectedAccount();
        $this->media($account, ['views' => 500], '1');

        $this->assertNull(PortfolioSuggestions::best());
    }

    public function test_the_bar_is_the_portfolios_own_average_once_it_has_scored_pieces(): void
    {
        PortfolioItem::create(['title' => 'Existing piece one', 'views' => 100000]);
        PortfolioItem::create(['title' => 'Existing piece two', 'views' => 60000]);
        // Average of the two above is 80,000.

        $account = $this->connectedAccount();
        $belowAverage = $this->media($account, ['views' => 70000], '1');

        $this->assertNull(PortfolioSuggestions::best());

        $aboveAverage = $this->media($account, ['views' => 90000], '2');

        $best = PortfolioSuggestions::best();
        $this->assertNotNull($best);
        $this->assertTrue($aboveAverage->is($best['media']));
        $this->assertSame(80000, $best['bar']);
    }

    public function test_a_post_already_mapped_to_a_portfolio_piece_is_never_suggested(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['views' => 90000], '1');

        PortfolioItem::create(['title' => 'Already added', 'social_media_item_id' => $media->id]);

        $this->assertNull(PortfolioSuggestions::best());
    }

    public function test_falls_back_to_reach_when_views_was_never_synced(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['reach' => 40000], '1');

        $best = PortfolioSuggestions::best();

        $this->assertNotNull($best);
        $this->assertTrue($media->is($best['media']));
        $this->assertSame('reach', $best['metric']);
        $this->assertSame(40000, $best['value']);
    }

    public function test_the_highest_performer_wins_when_more_than_one_clears_the_bar(): void
    {
        $account = $this->connectedAccount();
        $this->media($account, ['views' => 20000], '1');
        $best = $this->media($account, ['views' => 90000], '2');

        $result = PortfolioSuggestions::best();

        $this->assertTrue($best->is($result['media']));
    }

    public function test_a_post_on_a_disconnected_account_is_never_suggested(): void
    {
        $account = $this->connectedAccount();
        $account->forceFill(['status' => SocialAccount::STATUS_REVOKED, 'access_token' => null])->save();
        $this->media($account, ['views' => 90000], '1');

        $this->assertNull(PortfolioSuggestions::best());
    }
}
