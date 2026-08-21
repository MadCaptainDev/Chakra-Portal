<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Services\Instagram\InstagramContentMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tying a planned Notion item to the real Instagram post it became.
 *
 * The join is account + calendar day, which is all the two sides share --
 * so it is a strong inference rather than a fact, and these pin the rules
 * that keep a wrong inference from becoming a wrong number on a client's
 * dashboard.
 */
class InstagramContentMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private SocialAccount $socialAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create(['name' => 'Digital Harvest (Janet Hospitals)']);

        $this->socialAccount = SocialAccount::create([
            'client_id' => $this->client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '17841400000000000',
            'username' => 'janethospitaltrichy',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        // access_token is not fillable and scopeConnected() requires it --
        // an account without one is not "connected" as far as any query in
        // the app is concerned.
        $this->socialAccount->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();
    }

    private function mapVenture(string $venture, ?Client $client = null): ContentAccount
    {
        $account = ContentAccount::create([
            'client_id' => ($client ?? $this->client)->id,
            'name' => 'Janet',
            'target_reel' => 5,
        ]);

        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => $venture]);

        return $account;
    }

    private function media(string $id, string $postedAt, bool $reel = true): SocialMediaItem
    {
        return SocialMediaItem::create([
            'social_account_id' => $this->socialAccount->id,
            'platform_media_id' => $id,
            'media_type' => $reel ? 'VIDEO' : 'IMAGE',
            'media_product_type' => $reel ? SocialMediaItem::PRODUCT_REELS : SocialMediaItem::PRODUCT_FEED,
            'posted_at' => $postedAt,
            'cached_at' => now(),
        ]);
    }

    private function item(string $venture, string $date, string $source = ContentItem::SOURCE_REEL): ContentItem
    {
        return ContentItem::factory()->create([
            'source' => $source,
            'venture' => $venture,
            'status' => 'Published',
            'published_date' => $date,
        ]);
    }

    public function test_it_matches_a_planned_item_to_the_post_published_that_day(): void
    {
        $this->mapVenture('Janet');
        $item = $this->item('Janet', '2026-08-15');
        $media = $this->media('ig-1', '2026-08-15 11:00:00');

        $result = (new InstagramContentMatcher)->matchAll();

        $this->assertSame(1, $result['matched']);
        $this->assertSame($media->id, $item->fresh()->social_media_item_id);
    }

    public function test_a_different_day_is_not_a_match(): void
    {
        $this->mapVenture('Janet');
        $item = $this->item('Janet', '2026-08-15');
        $this->media('ig-1', '2026-08-16 11:00:00');

        $this->assertSame(0, (new InstagramContentMatcher)->matchAll()['matched']);
        $this->assertNull($item->fresh()->social_media_item_id);
    }

    public function test_an_unmapped_venture_is_never_attributed_to_anybodys_instagram(): void
    {
        // "PR" belongs to no account, so there is nothing saying whose
        // Instagram it would even be.
        $item = $this->item('PR', '2026-08-15');
        $this->media('ig-1', '2026-08-15 11:00:00');

        $this->assertSame(0, (new InstagramContentMatcher)->matchAll()['matched']);
        $this->assertNull($item->fresh()->social_media_item_id);
    }

    public function test_a_client_with_no_connected_instagram_matches_nothing(): void
    {
        $other = Client::create(['name' => 'Thor Gym']);
        $this->mapVenture('THOR', $other);
        $item = $this->item('THOR', '2026-08-15');
        // The post belongs to Janet's account, not Thor's.
        $this->media('ig-1', '2026-08-15 11:00:00');

        $this->assertSame(0, (new InstagramContentMatcher)->matchAll()['matched']);
        $this->assertNull($item->fresh()->social_media_item_id);
    }

    public function test_one_instagram_post_is_claimed_by_only_one_planned_item(): void
    {
        $this->mapVenture('Janet');
        $a = $this->item('Janet', '2026-08-15');
        $b = $this->item('Janet', '2026-08-15');
        $this->media('ig-1', '2026-08-15 11:00:00');

        $result = (new InstagramContentMatcher)->matchAll();

        // Two planned reels, one real post: exactly one match, so its reach
        // cannot be counted twice.
        $this->assertSame(1, $result['matched']);
        $this->assertCount(1, array_filter([$a->fresh()->social_media_item_id, $b->fresh()->social_media_item_id]));
    }

    public function test_a_same_day_tie_prefers_the_post_whose_format_agrees(): void
    {
        $this->mapVenture('Janet');
        $reelItem = $this->item('Janet', '2026-08-15', ContentItem::SOURCE_REEL);

        // The carousel is created first, so picking it would be the easy
        // wrong answer.
        $carousel = $this->media('ig-carousel', '2026-08-15 09:00:00', reel: false);
        $reel = $this->media('ig-reel', '2026-08-15 18:00:00', reel: true);

        (new InstagramContentMatcher)->matchAll();

        $this->assertSame($reel->id, $reelItem->fresh()->social_media_item_id);
        $this->assertNotSame($carousel->id, $reelItem->fresh()->social_media_item_id);
    }

    public function test_an_existing_link_is_never_silently_repointed(): void
    {
        $this->mapVenture('Janet');
        $item = $this->item('Janet', '2026-08-15');
        $wrong = $this->media('ig-corrected-by-hand', '2026-01-01 10:00:00');
        $item->forceFill(['social_media_item_id' => $wrong->id])->save();

        $this->media('ig-same-day', '2026-08-15 11:00:00');

        (new InstagramContentMatcher)->matchAll();

        // A correction made by hand survives the next run.
        $this->assertSame($wrong->id, $item->fresh()->social_media_item_id);
    }

    public function test_unpublished_items_are_never_matched(): void
    {
        $this->mapVenture('Janet');
        $item = ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet',
            'status' => 'Idea', 'published_date' => '2026-08-15',
        ]);
        $this->media('ig-1', '2026-08-15 11:00:00');

        $this->assertSame(0, (new InstagramContentMatcher)->matchAll()['matched']);
        $this->assertNull($item->fresh()->social_media_item_id);
    }
}
