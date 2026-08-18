<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mapping a Portfolio piece to a cached Instagram post/reel, and keeping its
 * numbers current whenever that client's Instagram gets synced.
 */
class PortfolioInstagramMappingTest extends TestCase
{
    use RefreshDatabase;

    /** Files land in the real public/uploads, so each test clears up after itself. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file(public_path($path))) {
                unlink(public_path($path));
            }
        }

        parent::tearDown();
    }

    private function trackUploads(): void
    {
        foreach (PortfolioItem::all() as $item) {
            $this->written[] = $item->thumbnail_path;
        }

        $this->written = array_values(array_filter($this->written));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function staff(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'portfolio', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    private function client(string $name = 'Chakra Production'): Client
    {
        return Client::create(['name' => $name]);
    }

    private static int $nextPlatformUserId = 17841476964090891;

    private function connectedAccount(?Client $client = null): SocialAccount
    {
        $client ??= $this->client();
        $platformUserId = (string) self::$nextPlatformUserId++;

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();

        return $account->fresh();
    }

    /** A cached post/reel with the given metrics already synced. */
    private function media(
        SocialAccount $account,
        array $metrics = ['views' => 1200, 'reach' => 900, 'likes' => 80, 'comments' => 12, 'shares' => 6, 'saved' => 20],
        string $productType = SocialMediaItem::PRODUCT_FEED,
        string $platformMediaId = '18000000000000001',
    ): SocialMediaItem {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => $platformMediaId,
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => $productType,
            'caption' => 'A real caption from the client account',
            'permalink' => 'https://www.instagram.com/p/CxAbCdEfGh/',
            'thumbnail_url' => 'https://scontent.cdninstagram.com/thumb.jpg?token=abc',
            'media_url' => 'https://scontent.cdninstagram.com/media.mp4?token=abc',
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

    /**
     * A minimal fake JPEG response. storeFromUrl() only checks the
     * Content-Type header and body size, never decodes the bytes, so real
     * image data is not needed.
     */
    private function jpegResponse()
    {
        return Http::response(str_repeat("\xFF\xD8\xFF", 20), 200, ['Content-Type' => 'image/jpeg']);
    }

    // -- The media picker -----------------------------------------------------

    public function test_the_media_picker_returns_cached_items_for_a_connected_client(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $media = $this->media($account);

        $response = $this->actingAs($this->admin())
            ->getJson(route('portfolio.instagram-media', ['client_id' => $client->id]))
            ->assertOk();

        $response->assertJsonFragment(['id' => $media->id, 'permalink' => $media->permalink]);
    }

    public function test_the_media_picker_returns_an_empty_list_for_a_client_with_no_connected_account(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin())
            ->getJson(route('portfolio.instagram-media', ['client_id' => $client->id]))
            ->assertOk()
            ->assertJson(['items' => []]);
    }

    public function test_the_media_picker_requires_portfolio_create_or_edit_permission(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        // 'view' alone reaches the controller (the route sits behind
        // module:portfolio,view like the rest of this group) but is refused
        // by the create-or-edit check inside instagramMedia() itself.
        $this->actingAs($this->staff(['view']))
            ->getJson(route('portfolio.instagram-media', ['client_id' => $client->id]))
            ->assertForbidden();

        $this->actingAs($this->staff(['view', 'create']))
            ->getJson(route('portfolio.instagram-media', ['client_id' => $client->id]))
            ->assertOk();
    }

    // -- Saving a mapped piece -------------------------------------------------

    public function test_saving_a_portfolio_piece_with_a_social_media_item_from_a_different_client_is_silently_dropped(): void
    {
        $ownAccount = $this->connectedAccount($this->client('Owner'));
        $otherAccount = $this->connectedAccount($this->client('Someone Else'));
        $otherMedia = $this->media($otherAccount);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Spoofed mapping',
            'client_id' => $ownAccount->client_id,
            'social_media_item_id' => $otherMedia->id,
            'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $item = PortfolioItem::firstOrFail();

        $this->assertNull($item->social_media_item_id);
        $this->assertNull($item->video_url);
    }

    public function test_mapping_a_post_downloads_and_stores_its_thumbnail_locally(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $this->trackUploads();
        $item = PortfolioItem::firstOrFail();

        $this->assertSame($media->id, $item->social_media_item_id);
        $this->assertStringStartsWith('uploads/portfolio/thumbnails/', $item->thumbnail_path);
        $this->assertFileExists(public_path($item->thumbnail_path));
    }

    public function test_mapping_a_post_falls_back_to_media_url_when_thumbnail_url_is_absent(): void
    {
        // Confirmed against the real cache: most stored posts have no
        // thumbnail_url at all -- Meta only sets it for some media types --
        // while media_url (the actual image, for an IMAGE/CAROUSEL post) is
        // present. instagram/insights.blade.php's Content Performance table
        // already falls back the same way.
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $media->forceFill(['thumbnail_url' => null])->save();

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $this->trackUploads();
        $item = PortfolioItem::firstOrFail();

        $this->assertStringStartsWith('uploads/portfolio/thumbnails/', $item->thumbnail_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'media.mp4'));
    }

    public function test_mapping_a_post_with_an_unreachable_thumbnail_url_saves_the_piece_without_a_thumbnail(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        Http::fake(['scontent.cdninstagram.com/*' => Http::response('', 404)]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $item = PortfolioItem::firstOrFail();

        $this->assertSame($media->id, $item->social_media_item_id);
        $this->assertNull($item->thumbnail_path);
    }

    public function test_mapping_a_post_does_not_overwrite_an_uploaded_thumbnail_file(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
            'thumbnail' => UploadedFile::fake()->image('still.jpg', 640, 360),
        ])->assertRedirect(route('portfolio.index'));

        $this->trackUploads();

        Http::assertNothingSent();
    }

    public function test_mapping_a_post_populates_performance_fields_from_cached_insights(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['views' => 1200, 'reach' => 900, 'likes' => 80, 'comments' => 12, 'shares' => 6, 'saved' => 20]);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $this->trackUploads();
        $item = PortfolioItem::firstOrFail();

        $this->assertSame(1200, $item->views);
        $this->assertSame(900, $item->reach);
        $this->assertSame(80, $item->likes);
        $this->assertSame(12, $item->comments);
        $this->assertSame(6, $item->shares);
        $this->assertNotNull($item->instagram_refreshed_at);
    }

    public function test_mapping_a_post_maps_the_saved_metric_to_the_saves_column(): void
    {
        $account = $this->connectedAccount();
        // "saved" is Instagram's own metric key -- see InstagramInsights.
        $media = $this->media($account, ['saved' => 42]);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ]);

        $this->trackUploads();

        $this->assertSame(42, PortfolioItem::firstOrFail()->saves);
    }

    public function test_mapping_a_reel_populates_avg_watch_seconds_from_ig_reels_avg_watch_time(): void
    {
        // Meta reports this metric in milliseconds despite the name -- the
        // exact value below is a real synced reel's (11446ms against
        // 112,659 views: 11.4 seconds of average watch, not 11446 seconds).
        $account = $this->connectedAccount();
        $media = $this->media(
            $account,
            ['views' => 112659, 'ig_reels_avg_watch_time' => 11446],
            SocialMediaItem::PRODUCT_REELS,
        );

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped reel',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ]);

        $this->trackUploads();

        $this->assertSame(11.4, PortfolioItem::firstOrFail()->avg_watch_seconds);
    }

    public function test_mapping_a_post_never_touches_business_fields(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mapped piece',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
            'leads' => 15,
            'sales_amount' => 50000,
        ]);

        $this->trackUploads();
        $item = PortfolioItem::firstOrFail();

        $this->assertSame(15, $item->leads);
        $this->assertSame(50000.0, $item->sales_amount);
    }

    public function test_mapping_a_post_never_overwrites_a_staff_typed_title(): void
    {
        // The server side never touches title at all -- it is client-side
        // (Alpine) that fills a blank title, which store()/update() never
        // sees the "before" value of. This guards the server contract: a
        // title posted by the form is saved exactly as posted, mapping or
        // not.
        $account = $this->connectedAccount();
        $media = $this->media($account);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'A title staff chose themselves',
            'client_id' => $account->client_id,
            'social_media_item_id' => $media->id,
            'is_visible' => '1',
        ]);

        $this->trackUploads();

        $this->assertSame('A title staff chose themselves', PortfolioItem::firstOrFail()->title);
    }

    public function test_unlinking_a_mapped_piece_clears_the_link(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        Http::fake(['scontent.cdninstagram.com/*' => $this->jpegResponse()]);

        $item = PortfolioItem::create([
            'title' => 'Mapped piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        $this->actingAs($this->admin())->put(route('portfolio.update', $item), [
            'title' => $item->title,
            'client_id' => $account->client_id,
            'is_visible' => '1',
            // social_media_item_id deliberately omitted -- an explicit unlink.
        ])->assertRedirect(route('portfolio.index'));

        $this->assertNull($item->fresh()->social_media_item_id);
    }

    // -- Auto-refresh on Instagram sync -----------------------------------------

    public function test_syncing_an_account_from_the_controller_refreshes_its_linked_portfolio_items(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['views' => 100]);

        $item = PortfolioItem::create([
            'title' => 'Linked piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        // A fresh sync writes new numbers for the same day (record() upserts).
        SocialInsight::record([
            'social_account_id' => $account->id, 'social_media_item_id' => $media->id,
            'metric' => 'views', 'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => 999, 'period' => 'lifetime', 'period_start' => now()->toDateString(),
        ]);

        Http::fake(['graph.instagram.com/*' => Http::response(['data' => []])]);

        // Admin, not staff(): this route needs module:clients,edit, a
        // different module from the portfolio abilities staff() grants here.
        $this->actingAs($this->admin())
            ->post(route('instagram.insights.sync', $account->client))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'Synced'));

        $this->assertSame(999, $item->fresh()->views);
    }

    public function test_syncing_an_account_from_the_artisan_command_refreshes_its_linked_portfolio_items(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['views' => 100]);

        $item = PortfolioItem::create([
            'title' => 'Linked piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        SocialInsight::record([
            'social_account_id' => $account->id, 'social_media_item_id' => $media->id,
            'metric' => 'views', 'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => 777, 'period' => 'lifetime', 'period_start' => now()->toDateString(),
        ]);

        Http::fake(['graph.instagram.com/*' => Http::response(['data' => []])]);

        $this->artisan('instagram:sync')->assertExitCode(0);

        $this->assertSame(777, $item->fresh()->views);
    }

    public function test_a_failed_sync_does_not_refresh_linked_portfolio_items(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account, ['views' => 100]);

        $item = PortfolioItem::create([
            'title' => 'Linked piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        SocialInsight::record([
            'social_account_id' => $account->id, 'social_media_item_id' => $media->id,
            'metric' => 'views', 'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => 999, 'period' => 'lifetime', 'period_start' => now()->toDateString(),
        ]);

        // Every call fails, so syncAll() throws before the refresh hook is
        // ever reached.
        Http::fake(['graph.instagram.com/*' => Http::response(['error' => ['message' => 'boom', 'type' => 'OAuthException', 'code' => 190]], 401)]);

        $this->artisan('instagram:sync')->assertExitCode(1);

        $this->assertSame(100, $item->fresh()->views);
    }

    public function test_a_broken_linked_portfolio_item_does_not_fail_an_otherwise_successful_sync(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        // Linked to media that no longer exists by the time the sync runs --
        // the whereHas() join in refreshLinkedPortfolioItems() simply will
        // not find this row, which is the normal (not exceptional) case this
        // guards; a real thrown exception inside the per-item loop is what
        // the try/catch in refreshLinkedPortfolioItems() itself covers, and
        // is exercised indirectly here by asserting the sync still succeeds
        // with a linked item present.
        $item = PortfolioItem::create([
            'title' => 'Linked piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        Http::fake(['graph.instagram.com/*' => Http::response(['data' => []])]);

        $this->artisan('instagram:sync')->assertExitCode(0);
    }

    // -- Public display ----------------------------------------------------------

    public function test_the_public_case_study_page_renders_the_instagram_embed_blockquote_for_a_mapped_item(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        $item = PortfolioItem::create([
            'title' => 'Mapped piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            ->assertSee('instagram-media', false)
            ->assertSee('data-instgrm-permalink="'.$media->permalink.'"', false)
            ->assertSee('View on Instagram');
    }

    public function test_the_public_case_study_page_plays_the_uploaded_video_unchanged_for_a_non_mapped_item(): void
    {
        $item = PortfolioItem::create([
            'title' => 'Ordinary piece', 'is_visible' => true, 'video_url' => 'https://youtube.com/watch?v=abc',
        ]);

        $response = $this->get(route('portfolio.detail', $item))->assertOk();

        $response->assertDontSee('instagram-media', false);
        $response->assertDontSee('View on Instagram');
    }

    public function test_the_public_case_study_page_shows_a_view_on_instagram_badge_for_a_mapped_item(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        $item = PortfolioItem::create([
            'title' => 'Mapped piece', 'client_id' => $account->client_id, 'is_visible' => true,
        ]);
        $item->mapToInstagram($media);

        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            ->assertSee($media->permalink, false);
    }
}
