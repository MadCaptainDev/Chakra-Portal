<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each client's two most-viewed reels go into the portfolio, mapped to their
 * posts; the public page shuffles, shows the live numbers and pops up the
 * top performers.
 */
class PortfolioTopReelsTest extends TestCase
{
    use RefreshDatabase;

    private function reel(SocialAccount $account, string $id, int $views, string $caption): SocialMediaItem
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id, 'platform_media_id' => $id,
            'media_type' => SocialMediaItem::TYPE_VIDEO, 'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => $caption, 'permalink' => "https://www.instagram.com/reel/C{$id}x/",
            'thumbnail_url' => "https://scontent.cdninstagram.com/{$id}.jpg", 'posted_at' => now()->subDays(5), 'cached_at' => now(),
        ]);
        SocialInsight::record(['social_account_id' => $account->id, 'social_media_item_id' => $media->id, 'metric' => 'views',
            'metric_type' => SocialInsight::TYPE_TOTAL_VALUE, 'value' => $views, 'period' => 'lifetime', 'period_start' => now()->toDateString()]);

        return $media;
    }

    public function test_the_two_most_viewed_reels_are_added_once_and_shown_live(): void
    {
        Http::fake(['scontent.cdninstagram.com/*' => Http::response(str_repeat("\xFF\xD8\xFF", 20), 200, ['Content-Type' => 'image/jpeg'])]);
        $client = Client::create(['name' => 'Janet Hospitals']);
        $account = SocialAccount::create(['client_id' => $client->id, 'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '17841400000000009', 'username' => 'janet', 'status' => SocialAccount::STATUS_CONNECTED]);
        $account->forceFill(['access_token' => 'x', 'connected_at' => now(), 'last_synced_at' => now()])->save();
        $this->reel($account, '1', 6_500_000, "5 scans every husband should know 👀\n#janet #pregnancy");
        $this->reel($account, '2', 2_900_000, '#hashtag first line only');
        $this->reel($account, '3', 4_000, 'Small one');

        $this->artisan('portfolio:add-top-reels')->assertExitCode(0);
        $this->artisan('portfolio:add-top-reels')->assertExitCode(0); // again: nothing new

        $items = PortfolioItem::orderByDesc('views')->get();
        $this->assertCount(2, $items);
        $this->assertSame('5 scans every husband should know 👀', $items[0]->title);
        $this->assertSame(6_500_000, $items[0]->views);
        $this->assertSame('Janet Hospitals reel', $items[1]->title);
        $this->assertNotNull($items[0]->thumbnail_path);

        $this->withoutVite();
        $this->get(route('portfolio'))->assertOk()
            ->assertDontSee('Live numbers from Instagram')->assertSee('reels that broke out')->assertSee('data-pf-count', false)
            ->assertSee('Meta')
            ->assertSee('data-pf-views="'.$items[0]->id.'"', false)
            ->assertSee('Top performer');
        $this->getJson(route('portfolio.live'))->assertOk()->assertJsonPath('items.'.$items[0]->id.'.views', 6_500_000);
    }
}
