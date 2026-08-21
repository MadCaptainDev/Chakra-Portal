<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Support\PublicUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PortfolioTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function trackUploads(): void
    {
        foreach (PortfolioItem::all() as $item) {
            $this->written[] = $item->video_path;
            $this->written[] = $item->thumbnail_path;
        }

        $this->written = array_values(array_filter($this->written));
    }

    public function test_an_admin_uploads_a_video_with_a_thumbnail(): void
    {
        $category = PortfolioCategory::create([
            'name' => 'Weddings', 'slug' => 'weddings', 'sort_order' => 0, 'is_visible' => true,
        ]);

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'portfolio_category_id' => $category->id,
            'title' => 'Anita & Ravi',
            'client_name' => 'Private',
            'description' => 'Two-day wedding film.',
            'is_visible' => '1',
            'video' => UploadedFile::fake()->create('film.mp4', 64, 'video/mp4'),
            'thumbnail' => UploadedFile::fake()->image('still.jpg', 640, 360),
        ])->assertRedirect(route('portfolio.index'));

        $this->trackUploads();

        $item = PortfolioItem::firstOrFail();

        $this->assertSame('Anita & Ravi', $item->title);
        $this->assertSame($category->id, $item->portfolio_category_id);
        $this->assertTrue($item->is_visible);

        // Served straight out of public/, because the host cannot make the
        // storage symlink that Storage::disk('public') relies on.
        $this->assertStringStartsWith('uploads/portfolio/videos/', $item->video_path);
        $this->assertStringStartsWith('uploads/portfolio/thumbnails/', $item->thumbnail_path);
        $this->assertFileExists(public_path($item->video_path));
        $this->assertFileExists(public_path($item->thumbnail_path));
    }

    public function test_a_video_that_is_too_large_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('portfolio.store'), [
                'title' => 'Oversized',
                'video' => UploadedFile::fake()->create('huge.mp4', PortfolioItem::VIDEO_MAX_KB + 1024, 'video/mp4'),
            ])
            ->assertSessionHasErrors('video');

        $this->assertSame(0, PortfolioItem::count());
    }

    public function test_replacing_a_video_deletes_the_file_it_replaced(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'First cut',
            'is_visible' => '1',
            'video' => UploadedFile::fake()->create('first.mp4', 16, 'video/mp4'),
        ]);

        $item = PortfolioItem::firstOrFail();
        $original = $item->video_path;

        $this->actingAs($admin)->put(route('portfolio.update', $item), [
            'title' => 'Second cut',
            'is_visible' => '1',
            'video' => UploadedFile::fake()->create('second.mp4', 16, 'video/mp4'),
        ])->assertRedirect(route('portfolio.index'));

        $this->trackUploads();

        $item->refresh();

        $this->assertNotSame($original, $item->video_path);
        $this->assertFileDoesNotExist(public_path($original));
        $this->assertFileExists(public_path($item->video_path));
    }

    public function test_deleting_a_piece_removes_its_files(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'Throwaway',
            'is_visible' => '1',
            'video' => UploadedFile::fake()->create('clip.mp4', 16, 'video/mp4'),
            'thumbnail' => UploadedFile::fake()->image('still.jpg'),
        ]);

        $item = PortfolioItem::firstOrFail();
        $video = $item->video_path;
        $thumb = $item->thumbnail_path;

        $this->actingAs($admin)->delete(route('portfolio.destroy', $item))
            ->assertRedirect(route('portfolio.index'));

        $this->assertSame(0, PortfolioItem::count());
        $this->assertFileDoesNotExist(public_path($video));
        $this->assertFileDoesNotExist(public_path($thumb));
    }

    public function test_deleting_a_category_keeps_its_videos(): void
    {
        $category = PortfolioCategory::create([
            'name' => 'Corporate', 'slug' => 'corporate', 'sort_order' => 0, 'is_visible' => true,
        ]);

        $item = PortfolioItem::create([
            'portfolio_category_id' => $category->id,
            'title' => 'Annual report film',
            'is_visible' => true,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('portfolio-categories.destroy', $category))
            ->assertRedirect(route('portfolio-categories.index'));

        $this->assertSame(0, PortfolioCategory::count());
        $this->assertNull($item->fresh()->portfolio_category_id);
        $this->assertSame(1, PortfolioItem::count());
    }

    public function test_the_slug_stays_unique_across_categories(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio-categories.store'), ['name' => 'Music Videos', 'is_visible' => '1']);
        $this->actingAs($admin)->post(route('portfolio-categories.store'), ['name' => 'Music Videos', 'is_visible' => '1']);

        $this->assertSame(
            ['music-videos', 'music-videos-2'],
            PortfolioCategory::orderBy('id')->pluck('slug')->all()
        );
    }

    public function test_the_public_page_shows_visible_work_with_its_category_tabs(): void
    {
        $category = PortfolioCategory::create([
            'name' => 'Weddings', 'slug' => 'weddings', 'sort_order' => 0, 'is_visible' => true,
        ]);

        PortfolioItem::create([
            'portfolio_category_id' => $category->id,
            'title' => 'Anita & Ravi',
            'is_visible' => true,
        ]);

        PortfolioItem::create(['title' => 'Draft piece', 'is_visible' => false]);

        $this->get(route('portfolio'))
            ->assertOk()
            ->assertSee('Anita &amp; Ravi', false)
            ->assertSee('Weddings')
            ->assertSee('All work')
            ->assertDontSee('Draft piece');
    }

    public function test_work_filed_under_a_hidden_category_stays_off_the_public_page(): void
    {
        $hidden = PortfolioCategory::create([
            'name' => 'Internal', 'slug' => 'internal', 'sort_order' => 0, 'is_visible' => false,
        ]);

        PortfolioItem::create([
            'portfolio_category_id' => $hidden->id,
            'title' => 'Staff party reel',
            'is_visible' => true,
        ]);

        // Nothing is left to show, so the screen steps aside entirely.
        $this->get(route('portfolio'))->assertRedirect(route('home'));
        $this->get('/')->assertOk()->assertDontSee('Staff party reel');
    }

    public function test_the_landing_page_shows_work_and_links_to_the_full_portfolio(): void
    {
        PortfolioItem::create(['title' => 'Brand film', 'is_visible' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Selected work')
            ->assertSee('Brand film')
            ->assertSee(route('portfolio'), false);
    }

    public function test_the_landing_page_offers_see_more_only_when_there_is_more(): void
    {
        // Six is exactly what the landing page shows, so there is nothing more
        // to see and the button would be a lie.
        foreach (range(1, 6) as $index) {
            PortfolioItem::create(['title' => 'Piece '.$index, 'is_visible' => true]);
        }

        $this->get('/')->assertOk()->assertDontSee('See more');

        PortfolioItem::create(['title' => 'Piece 7', 'is_visible' => true]);

        $this->get('/')->assertOk()->assertSee('See more');
    }

    public function test_employees_cannot_reach_the_portfolio_admin(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('portfolio.index'))->assertForbidden();
        $this->actingAs($employee)->post(route('portfolio.store'), ['title' => 'Nope'])->assertForbidden();
    }

    public function test_guests_cannot_reach_the_portfolio_admin(): void
    {
        $this->get(route('portfolio.index'))->assertRedirect(route('login'));
    }

    public function test_uploads_never_land_outside_the_uploads_folder(): void
    {
        // A hand-edited path must not be able to remove a bundled asset.
        PublicUpload::delete('images/chakra-logo.png');
        PublicUpload::delete('uploads/../images/chakra-logo.png');

        $this->assertTrue(File::exists(public_path('images/chakra-logo.png')));
    }

    private function term(string $type, string $name): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'type' => $type,
            'name' => $name,
            'slug' => TaxonomyTerm::uniqueSlug($type, $name),
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * isVertical() drives whether a thumbnail renders in a 9:16 or 16:9 box
     * everywhere it appears (admin list, public grid, case study cover,
     * related items) -- see PortfolioItem::isVertical()'s own docblock for
     * why this reads the format term rather than stored dimensions.
     */
    public function test_a_reel_shaped_format_term_is_read_as_vertical(): void
    {
        $reel = PortfolioItem::create([
            'title' => 'Reel piece',
            'format_id' => $this->term(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical')->id,
        ]);

        $landscape = PortfolioItem::create([
            'title' => 'Landscape piece',
            'format_id' => $this->term(TaxonomyTerm::TYPE_FORMAT, '16:9 landscape')->id,
        ]);

        $noFormat = PortfolioItem::create(['title' => 'No format set']);

        $this->assertTrue($reel->isVertical());
        $this->assertFalse($landscape->isVertical());
        $this->assertFalse($noFormat->isVertical());
    }

    public function test_a_new_piece_defaults_to_9_16_vertical_and_instagram_reels(): void
    {
        $format = $this->term(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical');
        $platform = $this->term(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels');

        $response = $this->actingAs($this->admin())->get(route('portfolio.create'));

        $response->assertOk();
        $item = $response->viewData('item');

        $this->assertSame($format->id, $item->format_id);
        $this->assertSame($platform->id, $item->platform_id);
    }

    public function test_the_default_format_command_sets_every_existing_piece(): void
    {
        $format = $this->term(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical');
        $platform = $this->term(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels');
        $otherFormat = $this->term(TaxonomyTerm::TYPE_FORMAT, '16:9 landscape');

        $item = PortfolioItem::create(['title' => 'Old landscape piece', 'format_id' => $otherFormat->id]);

        $this->artisan('portfolio:set-default-format')->assertExitCode(0);

        $this->assertSame($format->id, $item->fresh()->format_id);
        $this->assertSame($platform->id, $item->fresh()->platform_id);
    }

    private function connectedInstagramAccount(): SocialAccount
    {
        $client = Client::create(['name' => 'Chakra Production']);

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '17841470000000099',
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();

        return $account->fresh();
    }

    /** A cached post with a views insight already synced, not yet mapped to any portfolio piece. */
    private function unlinkedMedia(SocialAccount $account, int $views): SocialMediaItem
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => (string) $account->id.'-1',
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => 'A caption worth reading',
            'permalink' => 'https://www.instagram.com/p/1/',
            'posted_at' => now()->subDays(3),
            'cached_at' => now(),
        ]);

        SocialInsight::record([
            'social_account_id' => $account->id,
            'social_media_item_id' => $media->id,
            'metric' => 'views',
            'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => $views,
            'period' => 'lifetime',
            'period_start' => now()->toDateString(),
        ]);

        return $media->fresh();
    }

    public function test_the_admin_screen_shows_a_worth_adding_strip_for_a_high_performer(): void
    {
        $account = $this->connectedInstagramAccount();
        $media = $this->unlinkedMedia($account, 50000);

        $response = $this->actingAs($this->admin())->get(route('portfolio.index'));

        $response->assertOk();
        $response->assertSee('Worth adding');

        // Blade escapes the & in the query string to &amp; in the rendered
        // href, same as route()'s own output never will be.
        $expectedHref = str_replace('&', '&amp;', route('portfolio.create', [
            'client_id' => $account->client_id,
            'media_id' => $media->id,
        ]));
        $response->assertSee($expectedHref, false);
    }

    public function test_the_admin_screen_shows_no_strip_when_nothing_clears_the_bar(): void
    {
        $account = $this->connectedInstagramAccount();
        $this->unlinkedMedia($account, 500);

        $response = $this->actingAs($this->admin())->get(route('portfolio.index'));

        $response->assertOk();
        $response->assertDontSee('Worth adding');
    }
}
