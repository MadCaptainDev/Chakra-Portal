<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\InstagramGrid;
use App\Support\StudioNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The homepage's What we do explorer and "Where the hours go" pipeline:
 * real data, shown as its shape only.
 */
class HomePageStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ?string $savedManifest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        // Never clobber a real grid manifest in this checkout.
        if (is_file(InstagramGrid::manifestPath())) {
            $this->savedManifest = file_get_contents(InstagramGrid::manifestPath());
        }
    }

    protected function tearDown(): void
    {
        File::delete(InstagramGrid::manifestPath());
        if ($this->savedManifest !== null) {
            file_put_contents(InstagramGrid::manifestPath(), $this->savedManifest);
        }
        File::deleteDirectory(public_path('uploads/home-grid-test'));

        parent::tearDown();
    }

    private function log(string $type, string $task, int $minutes, ?string $day = null): void
    {
        TimesheetEntry::create([
            'user_id' => $this->user->id,
            'worked_on' => $day ?? now()->subWeek()->toDateString(),
            'task' => $task,
            'task_type' => $type,
            'minutes' => $minutes,
        ]);
    }

    private function account(string $username): SocialAccount
    {
        $account = SocialAccount::create([
            'client_id' => Client::create(['name' => 'Client '.$username])->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => (string) random_int(1000, 999999),
            'username' => $username,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now(), 'last_synced_at' => now()])->save();

        return $account;
    }

    private function reel(SocialAccount $account, string $postedAt, int $views): void
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => (string) random_int(1, PHP_INT_MAX),
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => "A reel\nsecond line",
            'posted_at' => $postedAt,
        ]);

        foreach (['views' => $views, 'reach' => intdiv($views, 2), 'likes' => 10] as $metric => $value) {
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
    }

    public function test_hours_reach_the_page_only_as_shares(): void
    {
        $this->log('shooting', 'Shoot', 600);
        $this->log('editing', 'Reel cut', 900);
        $this->log('posting', 'Posting', 30);
        // "Other" tasks are sorted by what they say.
        $this->log('other', 'DM checking', 30);
        $this->log('other', 'Excel sheet update', 120);
        $this->log('other', 'Learning', 300);

        $numbers = StudioNumbers::get();

        // 10 h shoot, 15 h edit, 1 h post, 2 h reports, of 28.
        $this->assertSame([36, 54, 4, 7], array_column($numbers['split'], 'pct'));
        $this->assertArrayNotHasKey('hours', $numbers);
        // The 10-hour shoot is a long day: the top level, no hours attached.
        $this->assertSame(4, collect($numbers['shoot'])->flatten(1)->max('level'));
    }

    public function test_the_report_is_shapes_and_multiples_and_skips_work_before_we_started(): void
    {
        $month = fn (int $ago) => now()->startOfMonth()->subMonths($ago)->addDays(3)->toDateTimeString();

        $riya = $this->account('riyamakeover_artistry');
        $this->reel($riya, $month(6), 1_000);
        $this->reel($riya, $month(1), 20_000);

        // Thillai before 15 Aug 2026 was someone else's work.
        $thillai = $this->account('thillaipetsclinic_');
        $this->reel($thillai, '2026-02-07 10:00:00', 9_000_000);

        $report = StudioNumbers::get()['report'];

        $this->assertSame(20, $report['viewsMultiple']);
        $this->assertSame(100, last($report['months'])['views']);
        $this->assertSame(5, $report['months'][0]['views']);
        $this->assertSame(100, $report['top'][0]['pct']);
        $this->assertSame('riyamakeover_artistry', $report['top'][0]['username']);
        $this->assertCount(2, $report['top']);
    }

    public function test_the_page_shows_real_data_but_no_raw_figures(): void
    {
        $this->log('shooting', 'Shoot', 1_742 * 60);
        $this->log('editing', 'Edit', 2_707 * 60, now()->subMonth()->toDateString());

        $this->get('/')
            ->assertOk()
            ->assertSee('Tap a service and watch it work.')
            ->assertSee('Reports &amp; analysis', false)
            ->assertSee('Where the hours go.')
            ->assertSee('Real data')
            ->assertSee('61% of our hours')
            ->assertDontSee('1,742')
            ->assertDontSee('2,707')
            ->assertDontSee('Not a slide of promises');
    }

    public function test_the_grid_only_lists_posts_whose_files_exist(): void
    {
        File::ensureDirectoryExists(public_path('uploads/home-grid-test'));
        foreach (['a', 'b', 'c'] as $name) {
            file_put_contents(public_path("uploads/home-grid-test/{$name}.jpg"), 'x');
        }

        $post = fn (string $name) => ['id' => 1, 'image' => "uploads/home-grid-test/{$name}.jpg", 'type' => 'photo', 'url' => 'https://instagram.com/p/x'];
        file_put_contents(InstagramGrid::manifestPath(), json_encode([
            ['username' => 'zirabridalstudio', 'name' => 'Zira', 'avatar' => null, 'posts' => [$post('a'), $post('b'), $post('c'), $post('gone')]],
            ['username' => 'mahavir.groups', 'name' => 'Mahavir', 'avatar' => null, 'posts' => [$post('a')]],
        ]));

        $grid = InstagramGrid::read();

        $this->assertCount(1, $grid, 'an account with under three posts is left out');
        $this->assertCount(3, $grid[0]['posts']);
    }

    public function test_it_works_with_no_records_at_all(): void
    {
        $this->get('/')->assertOk()->assertSee('Where the hours go.');
    }
}
