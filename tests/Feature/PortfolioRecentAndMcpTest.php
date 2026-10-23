<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Services\Portfolio\TopReelsImporter;
use App\Tools\Portfolio\AddPortfolioVideo;
use App\Tools\Portfolio\AddRecentPortfolioReels;
use App\Tools\Portfolio\UpdatePortfolioItem;
use App\Tools\ToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortfolioRecentAndMcpTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // covers: nothing to download in tests
        $this->client = Client::create(['name' => 'Janet', 'phone' => '9000000001']);
        $this->account = SocialAccount::create(['client_id' => $this->client->id, 'platform' => 'instagram', 'platform_user_id' => '1']);
    }

    private function reel(string $code, int $views, string $postedAt, ?string $plannerDate = null, string $status = 'Published'): SocialMediaItem
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $this->account->id,
            'platform_media_id' => $code,
            'permalink' => "https://www.instagram.com/reel/{$code}/",
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'posted_at' => $postedAt,
            'caption' => "Reel {$code}",
        ]);
        SocialInsight::create([
            'social_account_id' => $this->account->id, 'social_media_item_id' => $media->id, 'metric' => 'views',
            'dedupe_key' => 'views-'.$media->id, 'fetched_at' => now(), 'value' => $views, 'period' => 'lifetime', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ]);
        if ($plannerDate) {
            ContentItem::create([
                'notion_page_id' => 'p-'.$code, 'source' => ContentItem::SOURCE_REEL, 'title' => "Reel {$code}", 'venture' => 'Janet',
                'status' => $status, 'published_date' => $plannerDate, 'social_media_item_id' => $media->id,
            ]);
        }

        return $media;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_posted_check_needs_a_published_planner_row_on_the_same_date(): void
    {
        $ok = $this->reel('AAA', 50_000, now()->subDays(3)->setTime(12, 0)->toDateTimeString(), now()->subDays(3)->toDateString());
        $noRow = $this->reel('BBB', 50_000, now()->subDays(3)->toDateTimeString());
        $wrongDay = $this->reel('CCC', 50_000, now()->subDays(3)->setTime(12, 0)->toDateTimeString(), now()->subDays(9)->toDateString());
        $notPublished = $this->reel('DDD', 50_000, now()->subDays(3)->setTime(12, 0)->toDateTimeString(), now()->subDays(3)->toDateString(), 'Scheduled');

        $this->assertTrue(TopReelsImporter::postedCheck($ok)['ok']);
        $this->assertFalse(TopReelsImporter::postedCheck($noRow)['ok']);
        $this->assertFalse(TopReelsImporter::postedCheck($wrongDay)['ok']);
        $this->assertFalse(TopReelsImporter::postedCheck($notPublished)['ok']);
    }

    public function test_recent_reels_are_good_confirmed_and_capped_per_client(): void
    {
        $day = fn (int $ago) => now()->subDays($ago)->setTime(12, 0);
        $this->reel('R1', 90_000, $day(2), $day(2)->toDateString());
        $this->reel('R2', 60_000, $day(5), $day(5)->toDateString());
        $this->reel('R3', 40_000, $day(6), $day(6)->toDateString()); // third for the client: over the cap
        $this->reel('LOW', 5_000, $day(1), $day(1)->toDateString()); // not good enough
        $this->reel('OLD', 99_000, $day(60), $day(60)->toDateString()); // not recent
        $this->reel('UNPLANNED', 80_000, $day(3)); // not confirmed

        $result = (new AddRecentPortfolioReels)->handle([], $this->admin());

        $this->assertSame([90_000, 60_000], array_column($result['added'], 'views'));
        $this->assertSame(2, PortfolioItem::count());
        // Running it again adds nothing: the client already has its two.
        $this->assertSame([], (new AddRecentPortfolioReels)->handle([], $this->admin())['added']);
    }

    public function test_add_one_video_by_instagram_link_checks_it_was_posted(): void
    {
        $this->reel('GOOD1', 30_000, now()->subDays(2)->setTime(12, 0), now()->subDays(2)->toDateString());
        $this->reel('NOPLAN', 30_000, now()->subDays(2)->setTime(12, 0));

        $out = (new AddPortfolioVideo)->handle(['instagram_url' => 'https://www.instagram.com/reel/GOOD1/?igsh=x', 'title' => 'Knee pain explained'], $this->admin());
        $this->assertSame('Knee pain explained', $out['title']);
        $this->assertSame(30_000, $out['views']);

        $this->expectException(ToolException::class);
        (new AddPortfolioVideo)->handle(['instagram_url' => 'https://www.instagram.com/reel/NOPLAN/'], $this->admin());
    }

    public function test_add_a_non_instagram_video_and_edit_it(): void
    {
        $category = PortfolioCategory::create(['name' => 'Healthcare', 'slug' => 'healthcare', 'is_visible' => true]);
        $out = (new AddPortfolioVideo)->handle(['video_url' => 'https://youtu.be/abc', 'client' => 'Janet', 'title' => 'Hospital film'], $this->admin());

        (new UpdatePortfolioItem)->handle(['id' => $out['id'], 'category' => 'Healthcare', 'published_on' => '2026-10-01', 'video_url' => 'https://youtu.be/xyz'], $this->admin());

        $item = PortfolioItem::find($out['id']);
        $this->assertSame($category->id, $item->portfolio_category_id);
        $this->assertSame('2026-10-01', $item->published_on->toDateString());
        $this->assertSame('https://youtu.be/xyz', $item->video_url);
    }

    public function test_the_portfolio_page_leads_with_latest_work(): void
    {
        $media = $this->reel('NEW1', 70_000, now()->subDays(2)->setTime(12, 0), now()->subDays(2)->toDateString());
        $item = PortfolioItem::create(['client_id' => $this->client->id, 'title' => 'Fresh one', 'is_visible' => true, 'thumbnail_path' => 'uploads/test-cover.jpg']);
        $item->mapToInstagram($media);
        $item->save();
        @mkdir(public_path('uploads'), 0755, true);
        file_put_contents(public_path('uploads/test-cover.jpg'), base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));

        try {
            $this->get(route('portfolio'))->assertOk()->assertSee('Latest work')->assertSee('Fresh one');
        } finally {
            @unlink(public_path('uploads/test-cover.jpg'));
        }
    }
}
