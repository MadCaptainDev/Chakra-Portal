<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Services\ViewMilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "This reel crossed 50K views": one announcement per step, per reel, for a
 * week, posted by the portal after the Instagram sync.
 */
class ViewMilestonesTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = SocialAccount::create([
            'client_id' => Client::factory()->create(['name' => 'Riya Makeover'])->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '501',
            'username' => 'riya',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_a_reel_crossing_a_step_is_announced_once_and_pushed(): void
    {
        Notification::fake();
        $staff = User::factory()->employee()->create();
        $reel = $this->reel('bridal-look', 12_400);

        $this->assertSame(1, app(ViewMilestones::class)->run());
        $this->assertSame(0, app(ViewMilestones::class)->run(), 'the same step is never announced twice');

        $a = Announcement::sole();
        $this->assertSame('🎉 Riya Makeover reel crossed 10K views', $a->title);
        $this->assertStringContainsString('Now at 12,400 views', $a->body);
        $this->assertSame('https://instagram.com/reel/bridal-look', $a->link_url);
        $this->assertTrue($a->isMilestone());
        $this->assertFalse($a->visible_to_clients);
        $this->assertSame(10_000, $reel->fresh()->views_milestone);
        Notification::assertSentTo($staff, AnnouncementPosted::class);
    }

    public function test_a_jump_announces_only_the_highest_step_and_never_a_lower_one_later(): void
    {
        Notification::fake();
        $reel = $this->reel('viral', 61_000);

        app(ViewMilestones::class)->run();
        $this->assertSame(['🎉 Riya Makeover reel crossed 50K views'], Announcement::pluck('title')->all());

        $this->views($reel, 104_000, now()->toDateString());
        app(ViewMilestones::class)->run();
        $this->assertSame(2, Announcement::count());
        $this->assertSame('🎉 Riya Makeover reel crossed 100K views', Announcement::latest('id')->first()->title);
    }

    public function test_old_posts_photos_and_small_numbers_are_left_alone(): void
    {
        $this->reel('old', 80_000, postedDaysAgo: 45);
        $this->reel('small', 9_999);
        $photo = $this->reel('photo', 20_000);
        $photo->update(['media_type' => SocialMediaItem::TYPE_IMAGE, 'media_product_type' => SocialMediaItem::PRODUCT_FEED]);

        $this->assertSame(0, app(ViewMilestones::class)->run(push: false));
        $this->assertSame(0, Announcement::count());
    }

    public function test_milestones_show_on_dashboards_for_a_week_then_drop_off(): void
    {
        $this->reel('week', 26_000);
        app(ViewMilestones::class)->run(push: false);

        $this->assertSame(1, Announcement::active()->count());
        $this->travel(8)->days();
        $this->assertSame(0, Announcement::active()->count());
        $this->assertTrue(Announcement::sole()->hasEnded());
    }

    public function test_the_command_and_the_announcements_page(): void
    {
        $this->reel('cmd', 30_000);

        $this->artisan('instagram:view-milestones', ['--no-push' => true])
            ->expectsOutput('Posted 1 views milestone announcement(s).')
            ->assertSuccessful();

        $this->actingAs(User::factory()->create())->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Views milestone')
            ->assertSee('Watch on Instagram')
            ->assertSee('Posted by the portal');
    }

    public function test_short_numbers(): void
    {
        $this->assertSame('10K', ViewMilestones::short(10_000));
        $this->assertSame('100K', ViewMilestones::short(100_000));
        $this->assertSame('1.5M', ViewMilestones::short(1_500_000));
        $this->assertNull(ViewMilestones::stepFor(9_999));
        $this->assertSame(25_000, ViewMilestones::stepFor(49_999));
    }

    private function reel(string $code, int $views, int $postedDaysAgo = 2): SocialMediaItem
    {
        $item = SocialMediaItem::create([
            'social_account_id' => $this->account->id,
            'platform_media_id' => $code,
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => 'Bridal look for '.$code,
            'permalink' => 'https://instagram.com/reel/'.$code,
            'posted_at' => now()->subDays($postedDaysAgo),
            'cached_at' => now(),
        ]);
        $this->views($item, $views, now()->subDay()->toDateString());

        return $item;
    }

    private function views(SocialMediaItem $item, int $views, string $day): void
    {
        SocialInsight::record([
            'social_account_id' => $this->account->id, 'social_media_item_id' => $item->id,
            'metric' => 'views', 'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => $views, 'period' => 'lifetime', 'period_start' => $day,
        ]);
    }
}
