<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Support\ContentDashboard;
use App\Support\DashboardWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_dashboard_shows_a_content_card_per_account(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Main IG', 'target_reel' => 10]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);

        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'status' => 'Published',
            'published_date' => '2026-08-10',
            'title' => 'Launch reel',
        ]);

        Carbon::setTestNow('2026-08-15');

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Content pipeline')
            ->assertSee('Main IG')
            ->assertSee('SVA Silks')
            // The type split, which is the whole point of the card: a
            // published count is reported per type, against its target.
            ->assertSee('Insta Reel')
            ->assertSee('Insta Post');
    }

    public function test_staff_dashboard_shows_their_upcoming_shoots(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['shoots' => ['view']]);

        $client = Client::create(['name' => 'SVA Silks']);
        $shoot = Shoot::create([
            'title' => 'Warehouse day',
            'client_id' => $client->id,
            'starts_at' => now()->addDays(3),
            'status' => Shoot::STATUS_CONFIRMED,
        ]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $user->id, 'role' => 'Camera']);

        $this->actingAs($user->fresh())
            ->get(route('my.dashboard'))
            ->assertOk()
            ->assertSee('My upcoming shoots')
            ->assertSee('Warehouse day');
    }

    /**
     * The split the old flat "published" count hid: three reels and one
     * post is a different month from four posts, and the card has to say
     * which it was.
     */
    public function test_published_counts_are_split_by_content_type(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);
        $account = ContentAccount::create([
            'client_id' => $client->id,
            'name' => 'Main IG',
            'target_reel' => 10,
            'target_post' => 4,
        ]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);

        foreach (range(1, 3) as $i) {
            ContentItem::factory()->create([
                'venture' => 'SVA Silks',
                'source' => ContentItem::SOURCE_REEL,
                'status' => 'Published',
                'published_date' => '2026-08-0'.$i,
            ]);
        }
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_POST,
            'status' => 'Published',
            'published_date' => '2026-08-05',
        ]);
        // Not published, so it counts towards planned but never actual.
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Edit in Progress',
            'published_date' => '2026-08-06',
        ]);

        $card = ContentDashboard::forAccounts(collect([$account]), Carbon::parse('2026-08-01'))->first();

        $this->assertSame(3, $card['types'][ContentItem::SOURCE_REEL]['actual']);
        $this->assertSame(1, $card['types'][ContentItem::SOURCE_POST]['actual']);
        $this->assertSame(10, $card['types'][ContentItem::SOURCE_REEL]['target']);
        $this->assertSame(4, $card['types'][ContentItem::SOURCE_REEL]['planned']);
        $this->assertSame(4, $card['total']);
    }

    public function test_pace_is_behind_when_the_month_is_further_along_than_the_work(): void
    {
        $account = $this->accountWithReelTarget(10);

        // Two thirds through August with 1 of 10 reels published.
        Carbon::setTestNow('2026-08-20');
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Published',
            'published_date' => '2026-08-02',
        ]);

        $card = ContentDashboard::forAccounts(collect([$account]), Carbon::parse('2026-08-01'))->first();

        $this->assertSame('behind', $card['types'][ContentItem::SOURCE_REEL]['pace']);
    }

    public function test_pace_is_on_track_when_the_work_is_ahead_of_the_calendar(): void
    {
        $account = $this->accountWithReelTarget(10);

        Carbon::setTestNow('2026-08-05');
        foreach (range(1, 6) as $i) {
            ContentItem::factory()->create([
                'venture' => 'SVA Silks',
                'source' => ContentItem::SOURCE_REEL,
                'status' => 'Published',
                'published_date' => '2026-08-0'.$i,
            ]);
        }

        $card = ContentDashboard::forAccounts(collect([$account]), Carbon::parse('2026-08-01'))->first();

        $this->assertSame('on_track', $card['types'][ContentItem::SOURCE_REEL]['pace']);
    }

    /**
     * A finished month is not "behind" -- it is simply what it was, and
     * colouring it amber invites chasing work nobody can still do.
     */
    public function test_a_past_month_carries_no_pace_verdict(): void
    {
        $account = $this->accountWithReelTarget(10);

        Carbon::setTestNow('2026-09-15');

        $card = ContentDashboard::forAccounts(collect([$account]), Carbon::parse('2026-08-01'))->first();

        $this->assertNull($card['types'][ContentItem::SOURCE_REEL]['pace']);
    }

    public function test_the_delta_compares_against_the_same_type_last_month(): void
    {
        $account = $this->accountWithReelTarget(10);

        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Published',
            'published_date' => '2026-07-10',
        ]);
        foreach (range(1, 4) as $i) {
            ContentItem::factory()->create([
                'venture' => 'SVA Silks',
                'source' => ContentItem::SOURCE_REEL,
                'status' => 'Published',
                'published_date' => '2026-08-0'.$i,
            ]);
        }

        $card = ContentDashboard::forAccounts(collect([$account]), Carbon::parse('2026-08-01'))->first();

        $this->assertSame(1, $card['types'][ContentItem::SOURCE_REEL]['previous']);
        $this->assertSame(3, $card['types'][ContentItem::SOURCE_REEL]['delta']);
    }

    public function test_pinning_accounts_replaces_the_previous_selection(): void
    {
        $admin = $this->admin();
        $first = $this->accountWithReelTarget(10);
        $second = ContentAccount::create(['client_id' => $first->client_id, 'name' => 'Second IG']);

        $this->actingAs($admin)->put(route('dashboard.widgets.update'), [
            'accounts' => [$second->id],
        ])->assertRedirect(route('dashboard'));

        $this->assertSame([$second->id], DashboardWidgets::pinnedAccountsFor($admin->fresh())->pluck('id')->all());

        $this->actingAs($admin)->put(route('dashboard.widgets.update'), [
            'accounts' => [$first->id],
        ]);

        $this->assertSame([$first->id], DashboardWidgets::pinnedAccountsFor($admin->fresh())->pluck('id')->all());
    }

    /**
     * Unticking everything must not leave a blank dashboard -- it falls
     * back to the same first-few default a brand new account sees.
     */
    public function test_pinning_nothing_falls_back_to_the_default_cards(): void
    {
        $admin = $this->admin();
        $account = $this->accountWithReelTarget(10);

        $this->actingAs($admin)->put(route('dashboard.widgets.update'), ['accounts' => []]);

        $this->assertFalse(DashboardWidgets::hasPinned($admin->fresh()));
        $this->assertSame([$account->id], DashboardWidgets::pinnedAccountsFor($admin->fresh())->pluck('id')->all());
    }

    public function test_one_persons_pins_do_not_change_anothers(): void
    {
        $mine = $this->admin();
        $theirs = $this->admin();
        $first = $this->accountWithReelTarget(10);
        $second = ContentAccount::create(['client_id' => $first->client_id, 'name' => 'Second IG']);

        $this->actingAs($mine)->put(route('dashboard.widgets.update'), ['accounts' => [$second->id]]);

        $this->assertSame([$second->id], DashboardWidgets::pinnedAccountsFor($mine->fresh())->pluck('id')->all());
        $this->assertFalse(DashboardWidgets::hasPinned($theirs->fresh()));
    }

    private function accountWithReelTarget(int $target): ContentAccount
    {
        $client = Client::firstOrCreate(['name' => 'SVA Silks']);
        $account = ContentAccount::create([
            'client_id' => $client->id,
            'name' => 'Main IG',
            'target_reel' => $target,
        ]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);

        return $account;
    }
}
