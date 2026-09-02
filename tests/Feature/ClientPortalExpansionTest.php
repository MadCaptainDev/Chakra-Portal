<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\SaasProduct;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The six additions to the client portal: a content calendar, a portfolio
 * gallery, requesting a shoot, "Your team", AMC/subscription status, and
 * studio announcements opted in for clients. Each is its own screen or its
 * own card, so grouped here by feature rather than merged into
 * ClientPortalTest, which is already about the portal's original five.
 */
class ClientPortalExpansionTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'name' => 'SVA Silks and Readymades',
            'notion_venture' => 'SVA Silks',
        ], $overrides));
    }

    private function loginFor(Client $client): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
    }

    private function staff(array $abilities = ['view', 'manage']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'clients', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    private function item(string $venture, array $overrides = []): ContentItem
    {
        static $n = 0;
        $n++;

        return ContentItem::create(array_merge([
            'source' => 'reel',
            'notion_page_id' => 'page-'.$n,
            'title' => 'Reel '.$n,
            'venture' => $venture,
            'status' => 'Published',
            'published_date' => today()->toDateString(),
        ], $overrides));
    }

    // -- Content calendar ---------------------------------------------------

    public function test_a_client_sees_their_own_scheduled_item_this_month(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['title' => 'Diwali Reel', 'status' => 'Scheduled', 'published_date' => today()->toDateString()]);

        $this->actingAs($this->loginFor($client))->get(route('client.content-calendar'))
            ->assertOk()
            ->assertSee('Diwali Reel');
    }

    public function test_an_in_progress_item_shows_regardless_of_date(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['title' => 'Being Edited', 'status' => 'Edit in Progress', 'published_date' => null]);

        $this->actingAs($this->loginFor($client))->get(route('client.content-calendar'))
            ->assertOk()
            ->assertSee('Being Edited');
    }

    public function test_a_published_item_this_month_shows_in_its_own_section(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['title' => 'Already Out', 'status' => 'Published']);

        $this->actingAs($this->loginFor($client))->get(route('client.content-calendar'))
            ->assertOk()
            ->assertSee('Already Out');
    }

    public function test_a_canceled_item_is_never_shown(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['title' => 'Dropped Idea', 'status' => 'Canceled']);

        $this->actingAs($this->loginFor($client))->get(route('client.content-calendar'))
            ->assertOk()
            ->assertDontSee('Dropped Idea');
    }

    public function test_another_clients_content_never_shows(): void
    {
        $mine = $this->client();
        $this->item('Somebody Else', ['title' => 'Not Yours', 'status' => 'Scheduled']);

        $this->actingAs($this->loginFor($mine))->get(route('client.content-calendar'))
            ->assertOk()
            ->assertDontSee('Not Yours');
    }

    public function test_an_employee_cannot_reach_the_content_calendar(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('client.content-calendar'))
            ->assertForbidden();
    }

    // -- Portfolio gallery ----------------------------------------------------

    public function test_a_client_sees_their_own_published_portfolio_item(): void
    {
        $client = $this->client();
        PortfolioItem::create(['client_id' => $client->id, 'title' => 'Launch Campaign', 'is_visible' => true, 'sort_order' => 1]);

        $this->actingAs($this->loginFor($client))->get(route('client.portfolio'))
            ->assertOk()
            ->assertSee('Launch Campaign');
    }

    public function test_a_hidden_portfolio_item_is_not_shown(): void
    {
        $client = $this->client();
        PortfolioItem::create(['client_id' => $client->id, 'title' => 'Not Ready Yet', 'is_visible' => false, 'sort_order' => 1]);

        $this->actingAs($this->loginFor($client))->get(route('client.portfolio'))
            ->assertOk()
            ->assertDontSee('Not Ready Yet');
    }

    public function test_an_item_under_a_hidden_category_is_not_shown(): void
    {
        $client = $this->client();
        $category = PortfolioCategory::create(['name' => 'Archived', 'slug' => 'archived', 'is_visible' => false, 'sort_order' => 1]);
        PortfolioItem::create([
            'client_id' => $client->id, 'title' => 'Old Work', 'is_visible' => true,
            'portfolio_category_id' => $category->id, 'sort_order' => 1,
        ]);

        $this->actingAs($this->loginFor($client))->get(route('client.portfolio'))
            ->assertOk()
            ->assertDontSee('Old Work');
    }

    public function test_another_clients_portfolio_item_never_shows(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Other Brand', 'notion_venture' => 'Other']);
        PortfolioItem::create(['client_id' => $theirs->id, 'title' => 'Their Reel', 'is_visible' => true, 'sort_order' => 1]);

        $this->actingAs($this->loginFor($mine))->get(route('client.portfolio'))
            ->assertOk()
            ->assertDontSee('Their Reel');
    }

    // -- Request a shoot ----------------------------------------------------

    public function test_a_client_can_request_a_shoot(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $this->actingAs($login)->post(route('client.shoots.request'), [
            'title' => 'Product shoot for new collection',
            'starts_at' => today()->addWeek()->toDateString(),
            'location' => 'Studio floor',
            'notes' => 'Need the new fabric samples ready.',
        ])->assertRedirect(route('client.shoots'));

        $shoot = Shoot::sole();
        $this->assertSame('Product shoot for new collection', $shoot->title);
        $this->assertSame($client->id, $shoot->client_id);
        $this->assertSame(Shoot::STATUS_PLANNED, $shoot->status);
        $this->assertSame($login->id, $shoot->created_by_id);
        $this->assertNotNull($shoot->requested_at);
        $this->assertTrue($shoot->isRequestedByClient());
    }

    public function test_a_shoot_request_needs_a_title_and_a_date(): void
    {
        $login = $this->loginFor($this->client());

        $this->actingAs($login)->post(route('client.shoots.request'), [])
            ->assertSessionHasErrors(['title', 'starts_at']);

        $this->assertSame(0, Shoot::count());
    }

    public function test_a_shoot_request_cannot_ask_for_a_date_in_the_past(): void
    {
        $login = $this->loginFor($this->client());

        $this->actingAs($login)->post(route('client.shoots.request'), [
            'title' => 'Backdated request',
            'starts_at' => today()->subDay()->toDateString(),
        ])->assertSessionHasErrors('starts_at');
    }

    public function test_an_employee_cannot_request_a_shoot_through_the_client_route(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->post(route('client.shoots.request'), ['title' => 'x', 'starts_at' => today()->toDateString()])
            ->assertForbidden();
    }

    public function test_a_staff_created_shoot_is_never_flagged_as_requested(): void
    {
        $client = $this->client();
        $shoot = Shoot::create([
            'title' => 'Studio-booked shoot', 'client_id' => $client->id,
            'starts_at' => now()->addWeek(), 'status' => Shoot::STATUS_CONFIRMED,
        ]);

        $this->assertFalse($shoot->isRequestedByClient());
    }

    // -- Your team ------------------------------------------------------------

    public function test_staff_can_add_and_remove_a_team_member(): void
    {
        $client = $this->client();
        $staffMember = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Priya Editor']);

        $this->actingAs($this->staff())->post(route('clients.team.store', $client), [
            'user_id' => $staffMember->id,
            'role' => 'Editor',
        ])->assertRedirect(route('clients.show', $client));

        $member = $client->teamMembers()->sole();
        $this->assertSame('Priya Editor', $member->name);
        $this->assertSame('Editor', $member->pivot->role);

        $this->actingAs($this->staff())
            ->delete(route('clients.team.destroy', [$client, $staffMember]))
            ->assertRedirect(route('clients.show', $client));

        $this->assertSame(0, $client->teamMembers()->count());
    }

    public function test_adding_a_team_member_needs_the_manage_ability(): void
    {
        $client = $this->client();
        $staffMember = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($this->staff(['view']))
            ->post(route('clients.team.store', $client), ['user_id' => $staffMember->id])
            ->assertForbidden();
    }

    public function test_the_client_dashboard_shows_their_team_with_role(): void
    {
        $client = $this->client();
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Priya Editor']);
        $client->teamMembers()->attach($editor->id, ['role' => 'Editor']);

        $this->actingAs($this->loginFor($client))->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Priya Editor')
            ->assertSee('Editor');
    }

    public function test_the_team_card_is_absent_when_nobody_is_assigned(): void
    {
        $this->actingAs($this->loginFor($this->client()))->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('Your team');
    }

    // -- AMC / subscription status --------------------------------------------

    public function test_the_client_dashboard_shows_an_active_amc_products_renewal_date(): void
    {
        $client = $this->client();
        SaasProduct::create([
            'client_id' => $client->id, 'name' => 'DJ Thanga ERP',
            'amc_paid_until' => today()->addMonths(6)->toDateString(),
        ]);

        $this->actingAs($this->loginFor($client))->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('DJ Thanga ERP')
            ->assertSee(today()->addMonths(6)->format('j M Y'));
    }

    public function test_an_overdue_amc_product_shows_as_overdue(): void
    {
        $client = $this->client();
        SaasProduct::create([
            'client_id' => $client->id, 'name' => 'Lapsed Product',
            'amc_paid_until' => today()->subMonth()->toDateString(),
        ]);

        $this->actingAs($this->loginFor($client))->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Overdue');
    }

    public function test_the_product_card_is_absent_for_a_client_with_no_saas_product(): void
    {
        $this->actingAs($this->loginFor($this->client()))->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('Your product');
    }

    // -- Announcements --------------------------------------------------------

    public function test_an_announcement_opted_in_for_clients_shows_on_their_dashboard(): void
    {
        Announcement::create([
            'title' => 'Studio closed for Diwali',
            'body' => 'We will reopen on the 25th.',
            'is_active' => true,
            'visible_to_clients' => true,
        ]);

        $this->actingAs($this->loginFor($this->client()))->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Studio closed for Diwali');
    }

    public function test_an_announcement_not_opted_in_never_shows_to_a_client(): void
    {
        Announcement::create([
            'title' => 'Internal server maintenance',
            'body' => 'Staff systems down 2-4am.',
            'is_active' => true,
            'visible_to_clients' => false,
        ]);

        $this->actingAs($this->loginFor($this->client()))->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('Internal server maintenance');
    }

    public function test_an_inactive_announcement_never_shows_even_if_opted_in(): void
    {
        Announcement::create([
            'title' => 'Old notice',
            'body' => 'No longer relevant.',
            'is_active' => false,
            'visible_to_clients' => true,
        ]);

        $this->actingAs($this->loginFor($this->client()))->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('Old notice');
    }

    public function test_posting_an_announcement_can_opt_it_in_for_clients(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('announcements.store'), [
            'title' => 'New pricing',
            'body' => 'Rates change from next month.',
            'is_active' => '1',
            'visible_to_clients' => '1',
        ])->assertRedirect(route('announcements.index'));

        $announcement = Announcement::sole();
        $this->assertTrue($announcement->visible_to_clients);
    }

    public function test_visible_to_clients_defaults_off_when_the_checkbox_is_not_ticked(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('announcements.store'), [
            'title' => 'Staff only note',
            'body' => 'Internal.',
        ])->assertRedirect();

        $this->assertFalse(Announcement::sole()->visible_to_clients);
    }
}
