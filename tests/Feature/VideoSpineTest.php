<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\Video;
use App\Services\Instagram\InstagramContentMatcher;
use App\Services\Videos\VideoLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Video record: one row per Notion item, linked and never copied, and
 * the lifetime-metric rule that stops a post being counted once per sync day.
 */
class VideoSpineTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create(['name' => 'Janet']);
        $this->account = SocialAccount::create([
            'client_id' => $this->client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '1784',
            'username' => 'janet',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $this->account->forceFill(['access_token' => 'tok', 'connected_at' => now()])->save();

        $contentAccount = ContentAccount::create(['client_id' => $this->client->id, 'name' => 'Janet', 'target_reel' => 5]);
        ContentAccountVenture::create(['content_account_id' => $contentAccount->id, 'venture' => 'Janet']);
    }

    private function media(string $id, string $postedAt, bool $reel): SocialMediaItem
    {
        return SocialMediaItem::create([
            'social_account_id' => $this->account->id,
            'platform_media_id' => $id,
            'media_type' => $reel ? 'VIDEO' : 'IMAGE',
            'media_product_type' => $reel ? SocialMediaItem::PRODUCT_REELS : SocialMediaItem::PRODUCT_FEED,
            'posted_at' => $postedAt,
            'cached_at' => now(),
        ]);
    }

    private function reading(SocialMediaItem $media, string $day, int $value): void
    {
        SocialInsight::record([
            'social_account_id' => $this->account->id,
            'social_media_item_id' => $media->id,
            'metric' => 'views',
            'period_start' => $day,
            'period_end' => $day,
            'value' => $value,
        ]);
    }

    public function test_latest_reading_is_used_not_the_sum_of_every_sync_day(): void
    {
        $media = $this->media('ig-1', '2026-08-01 10:00:00', true);
        $this->reading($media, '2026-08-02', 100);
        $this->reading($media, '2026-08-03', 150);
        $this->reading($media, '2026-08-04', 180);

        $this->assertSame(180, SocialInsight::latestPerItem([$media->id], ['views'])[$media->id]['views']);
    }

    public function test_a_youtube_item_never_claims_an_instagram_post(): void
    {
        $media = $this->media('ig-1', '2026-08-15 10:00:00', true);
        $short = ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_YOUTUBE, 'venture' => 'Janet', 'status' => 'Published', 'published_date' => '2026-08-15',
        ]);

        (new InstagramContentMatcher)->matchAll();

        $this->assertNull($short->fresh()->social_media_item_id);
        $this->assertNotNull($media->id);
    }

    public function test_every_item_gets_one_video_and_a_second_run_changes_nothing(): void
    {
        ContentItem::factory()->count(3)->create(['venture' => 'Janet']);

        $first = (new VideoLinker)->run();
        $second = (new VideoLinker)->run();

        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(3, Video::count());
    }

    public function test_script_client_and_post_are_linked_and_a_planned_reel_posted_as_a_post_is_flagged(): void
    {
        $media = $this->media('ig-2', '2026-08-20 09:00:00', false);
        $item = ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'status' => 'Published',
            'published_date' => '2026-08-20', 'social_media_item_id' => $media->id,
        ]);
        $script = Script::create(['client_id' => $this->client->id, 'content_item_id' => $item->id, 'title' => 'Why wash hands', 'status' => 'draft']);

        (new VideoLinker)->run();
        $video = Video::where('content_item_id', $item->id)->first();

        $this->assertSame($script->id, $video->script_id);
        $this->assertSame($this->client->id, $video->client_id);
        $this->assertSame('post', $video->posted_as);
        $this->assertTrue($video->hasFormatMismatch());
    }

    public function test_a_link_already_made_is_not_removed_when_its_source_disappears(): void
    {
        $item = ContentItem::factory()->create(['venture' => 'Janet']);
        $script = Script::create(['client_id' => $this->client->id, 'content_item_id' => $item->id, 'title' => 'T', 'status' => 'draft']);
        (new VideoLinker)->run();

        $script->forceFill(['content_item_id' => null])->save();
        (new VideoLinker)->run();

        $this->assertSame($script->id, Video::where('content_item_id', $item->id)->value('script_id'));
    }

    public function test_a_script_is_marked_shot_once_its_reel_is_past_the_shoot_in_the_planner(): void
    {
        $shot = ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'status' => 'Video Ready', 'shoot_date' => '2026-09-01']);
        $planned = ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'status' => 'To Be Shooted']);
        $a = Script::create(['client_id' => $this->client->id, 'content_item_id' => $shot->id, 'title' => 'A', 'status' => 'draft']);
        $b = Script::create(['client_id' => $this->client->id, 'content_item_id' => $planned->id, 'title' => 'B', 'status' => 'draft']);

        (new VideoLinker)->run();

        $this->assertSame('2026-09-01', $a->fresh()->shot_at->toDateString());
        $this->assertNull($b->fresh()->shot_at);
    }

    public function test_a_shot_date_already_on_a_script_is_not_overwritten(): void
    {
        $item = ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'status' => 'Published', 'published_date' => '2026-09-10']);
        $script = Script::create(['client_id' => $this->client->id, 'content_item_id' => $item->id, 'title' => 'A', 'status' => 'draft']);
        $script->forceFill(['shot_at' => '2026-08-01 00:00:00'])->save();

        (new VideoLinker)->run();

        $this->assertSame('2026-08-01', $script->fresh()->shot_at->toDateString());
    }

    public function test_an_unlinked_script_finds_its_reel_by_title_only_when_unambiguous(): void
    {
        $reel = ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'title' => 'Why wash your hands!']);
        $script = Script::create(['client_id' => $this->client->id, 'title' => 'why wash your hands', 'status' => 'draft']);

        (new VideoLinker)->run();

        $this->assertSame($reel->id, $script->fresh()->content_item_id);
    }

    public function test_a_shorts_planner_row_is_paired_with_the_reel_of_the_same_title(): void
    {
        $reel = ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'title' => 'Janet - Fertility Red Flags', 'published_date' => '2026-09-01']);
        $short = ContentItem::factory()->create(['source' => ContentItem::SOURCE_YOUTUBE, 'venture' => 'Janet', 'title' => 'Janet - Fertility Red Flags', 'published_date' => '2026-09-03']);
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'title' => 'Janet - Something else entirely']);

        (new VideoLinker)->run();

        $this->assertSame($short->id, Video::where('content_item_id', $reel->id)->value('short_content_item_id'));
    }

    public function test_a_short_is_not_paired_with_another_clients_reel(): void
    {
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Other', 'title' => 'Same title here']);
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_YOUTUBE, 'venture' => 'Janet', 'title' => 'Same title here']);

        (new VideoLinker)->run();

        $this->assertSame(0, Video::whereNotNull('short_content_item_id')->count());
    }
    public function test_a_script_finds_its_notion_reel_despite_the_prefix_and_tamil_spellings_and_is_marked_done(): void
    {
        $reel = fn (string $title, string $venture = 'Janet') => ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => $venture, 'title' => $title, 'status' => 'To Be Edited', 'shoot_date' => '2026-10-20',
        ]);
        $fracture = $reel('Janet - Fracture Ku Naattu Katu Podalama');
        $slipper = $reel('Janet - Veetla Slipper');
        $reel('Janet - Kaal Sulukku');
        $other = $reel('Someone Else - Office-la Ukkaarnthu Back Pain', 'Someone Else');

        $s1 = Script::create(['client_id' => $this->client->id, 'title' => 'Fracture-ku Nattu Kattu Podalaamaa?', 'status' => 'draft']);
        $s2 = Script::create(['client_id' => $this->client->id, 'title' => 'Veetla Slipper – Aadambaram Illa, Avasiyam', 'status' => 'draft']);
        $s3 = Script::create(['client_id' => $this->client->id, 'title' => 'Office-la Ukkaarndhe Back Pain', 'status' => 'draft']);

        (new VideoLinker)->run();

        $this->assertSame($fracture->id, $s1->fresh()->content_item_id);
        $this->assertSame($slipper->id, $s2->fresh()->content_item_id);
        // Another client's reel is never a match, however alike the title.
        $this->assertNull($s3->fresh()->content_item_id);
        $this->assertNotSame($other->id, $s3->fresh()->content_item_id);
        // Shot in Notion, so the script is done.
        $this->assertSame(Script::STATUS_COMPLETED, $s1->fresh()->status);
        $this->assertSame('2026-10-20', $s1->fresh()->shot_at->toDateString());
    }
    public function test_a_script_is_linked_by_hand_and_the_planner_shows_it(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $reel = ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'venture' => 'Janet', 'title' => 'Janet - Premium Door Actual Price', 'status' => 'To Be Edited', 'shoot_date' => '2026-10-15', 'script' => null]);
        $script = Script::create(['client_id' => $this->client->id, 'title' => 'Premium Door Price — Guess the Budget', 'status' => 'draft']);
        $section = $script->sections()->make(['heading' => 'Hook']);
        $section->position = 0;
        $section->body = '<p>Guess the price of this door</p>';
        $section->save();

        $this->withoutVite();
        $this->actingAs($admin)->get(route('scripts.show', $script))->assertOk()
            ->assertSee('Notion reel')->assertSee('Janet - Premium Door Actual Price');
        $this->post(route('scripts.notion-reel', $script), ['content_item_id' => $reel->id])->assertRedirect();

        $this->assertSame($reel->id, $script->fresh()->content_item_id);
        $this->assertSame(Script::STATUS_COMPLETED, $script->fresh()->status);

        $row = \App\Services\Planner\PlannerItems::detail($reel->fresh()->load(\App\Services\Planner\PlannerItems::RELATIONS));
        $this->assertTrue($row['has_script']);
        $this->assertSame('Premium Door Price — Guess the Budget', $row['portal_script']['title']);
        $this->assertSame('Guess the price of this door', $row['portal_script_text'][0]['text']);
    }
}
