<?php

namespace Tests\Feature;

use App\Http\Controllers\ShowreelController;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Enquiry;
use App\Models\PortfolioItem;
use App\Support\ImageVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ShowreelTest extends TestCase
{
    use RefreshDatabase;

    private const FOLDER = 'uploads/showreel-test';

    /** @var list<string> small copies made during a test, removed after it */
    private array $variants = [];

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path(self::FOLDER));
        foreach ($this->variants as $variant) {
            File::delete($variant);
        }

        parent::tearDown();
    }

    // A real (if tiny) image under public/, as an upload would leave one.
    private function image(string $name, int $width = 90, int $height = 160): string
    {
        File::ensureDirectoryExists(public_path(self::FOLDER));
        $path = self::FOLDER.'/'.$name;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 19, 42, 56));
        imagepng($image, public_path($path));
        imagedestroy($image);

        return $path;
    }

    private function variantFile(string $url): string
    {
        $file = public_path(ltrim(parse_url($url, PHP_URL_PATH), '/'));
        $this->variants[] = $file;

        return $file;
    }

    public function test_the_page_works_with_nothing_published_yet(): void
    {
        $this->get('/showreel')
            ->assertOk()
            ->assertSee('data-showreel', false)
            ->assertSee('Start a project')
            ->assertDontSee('Selected work');
    }

    public function test_it_shows_the_real_work_logos_and_rounded_down_numbers(): void
    {
        $logo = $this->image('riya.png', 600, 200);
        $riya = Client::create(['name' => 'Riya Makeover Artistry', 'logo_path' => $logo]);
        Client::create(['name' => 'Quiet Client With No Logo']);

        PortfolioItem::create([
            'title' => 'Celebrity Makeup Reel', 'is_visible' => true, 'client_id' => $riya->id,
            'thumbnail_path' => $this->image('one.png'), 'views' => 4_927_492,
        ]);
        PortfolioItem::create([
            'title' => 'Hospital Awareness Reel', 'is_visible' => true, 'client_name' => 'Janet  Hospitals',
            'thumbnail_path' => $this->image('two.png'), 'views' => 7_720_279,
        ]);
        PortfolioItem::create([
            'title' => 'Not Ready Yet', 'is_visible' => false,
            'thumbnail_path' => $this->image('three.png'), 'views' => 99_000_000,
        ]);

        ContentItem::factory()->count(1026)->create([
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Published',
        ]);

        $page = $this->get('/showreel')->assertOk();

        // The work, biggest hit first, and only what is published.
        $page->assertSeeInOrder(['Hospital Awareness Reel', 'Celebrity Makeup Reel'])
            ->assertDontSee('Not Ready Yet')
            ->assertSee('4.9M views');

        // Numbers floored, never rounded up: 12,647,771 → 12.6M+, 1,026 → 1,000+.
        $page->assertSee('12.6M+')->assertSee('7.7M')->assertSee('1,000+');

        // A brand with a logo shows it, as a small WebP copy; a brand the
        // portfolio names without one shows its name (double space squashed);
        // a client the public cannot see and with no logo is left out.
        $url = ImageVariant::url($logo, 320, 200);
        $file = $this->variantFile($url);
        $page->assertSee($url, false)
            ->assertSee('Riya Makeover Artistry')
            ->assertSee('Janet Hospitals')
            ->assertDontSee('Quiet Client With No Logo');

        $this->assertFileExists($file);
        $this->assertSame('image/webp', getimagesize($file)['mime']);
        $this->assertSame(320, getimagesize($file)[0]);
    }

    public function test_a_missing_or_unreadable_image_falls_back_to_the_original(): void
    {
        $this->assertSame(asset('uploads/nowhere.png'), ImageVariant::url('uploads/nowhere.png', 100, 100));

        File::ensureDirectoryExists(public_path(self::FOLDER));
        File::put(public_path(self::FOLDER.'/not-an-image.png'), 'plain text');
        $this->assertSame(asset(self::FOLDER.'/not-an-image.png'), ImageVariant::url(self::FOLDER.'/not-an-image.png', 100, 100));
    }

    public function test_compact_numbers_are_always_rounded_down(): void
    {
        $this->assertSame('4.9M', ShowreelController::compact(4_927_492));
        $this->assertSame('12.6M', ShowreelController::compact(12_647_771));
        $this->assertSame('1M', ShowreelController::compact(1_000_000));
        $this->assertSame('116K', ShowreelController::compact(116_501));
        $this->assertSame('999', ShowreelController::compact(999));
    }

    public function test_an_enquiry_from_the_showreel_is_filed_under_it(): void
    {
        Notification::fake();

        $this->get('/showreel')->assertSee(route('home', ['from' => 'showreel']).'#contact', false);

        $this->post(route('enquiry.store'), [
            'name' => 'Meera Raj',
            'email' => 'meera@example.test',
            'message' => 'Saw the showreel, we need reels for a launch.',
            'source' => 'showreel',
        ]);

        $this->assertSame('Showreel page', Enquiry::firstOrFail()->sourceLabel());
    }
}
