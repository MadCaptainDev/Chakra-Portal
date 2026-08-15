<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    /** Whoever raised the invoices in a given test. */
    private ?User $issuer = null;

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'name' => 'SVA Silks and Readymades',
            'notion_venture' => 'SVA Silks',
        ], $overrides));
    }

    private function loginFor(Client $client, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(Client $client, array $overrides = []): Invoice
    {
        static $n = 1000;
        $n++;

        // created_by is NOT NULL: an invoice is always somebody's doing.
        $this->issuer ??= User::factory()->create(['role' => User::ROLE_ADMIN]);

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-'.$n,
            'client_id' => $client->id,
            'created_by' => $this->issuer->id,
            'invoice_date' => today()->subDays(10)->toDateString(),
            'due_date' => today()->addDays(20)->toDateString(),
            'subtotal' => 10000,
            'total' => 10000,
            'status' => Invoice::STATUS_UNPAID,
        ], $overrides));
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
            'published_date' => today()->subDay()->toDateString(),
        ], $overrides));
    }

    // ——— Who may open the client area ———

    public function test_a_client_reaches_their_own_area(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('SVA Silks and Readymades');
    }

    public function test_an_employee_cannot_reach_the_client_area(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach (['client.dashboard', 'client.invoices', 'client.work', 'client.shoots'] as $route) {
            $this->actingAs($employee)->get(route($route))->assertForbidden();
        }
    }

    public function test_an_admin_cannot_reach_the_client_area_either(): void
    {
        // Deliberate. Every screen answers "what does *my* client see" from the
        // signed-in user's client_id, and an admin has none.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('client.dashboard'))
            ->assertForbidden();
    }

    public function test_a_client_login_with_no_client_is_refused_cleanly(): void
    {
        // Possible: deleting a client nulls the column rather than deleting the
        // account. It must refuse, not 500 four screens deep.
        $orphan = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => null]);

        $this->actingAs($orphan)->get(route('client.dashboard'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('client.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_client_cannot_reach_any_staff_screen(): void
    {
        $login = $this->loginFor($this->client());

        foreach (['dashboard', 'editors.index', 'clients.index', 'timesheets.index', 'users.index'] as $route) {
            $this->actingAs($login)->get(route($route))->assertForbidden();
        }
    }

    public function test_signing_in_sends_a_client_to_their_own_area(): void
    {
        $login = $this->loginFor($this->client());

        $this->assertSame('client.dashboard', $login->homeRoute());
    }

    // ——— Invoices ———

    public function test_a_client_sees_only_their_own_invoices(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Some Other Brand', 'notion_venture' => 'Other']);

        $this->invoice($mine, ['invoice_number' => 'INV-MINE']);
        $this->invoice($theirs, ['invoice_number' => 'INV-THEIRS']);

        $this->actingAs($this->loginFor($mine))->get(route('client.invoices'))
            ->assertOk()
            ->assertSee('INV-MINE')
            ->assertDontSee('INV-THEIRS');
    }

    public function test_another_clients_invoice_pdf_is_a_404(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Some Other Brand', 'notion_venture' => 'Other']);
        $notMine = $this->invoice($theirs);

        // 404 not 403: that the invoice exists is itself not theirs to learn.
        $this->actingAs($this->loginFor($mine))
            ->get(route('client.invoices.pdf', $notMine->id))
            ->assertNotFound();
    }

    public function test_an_invoice_still_awaiting_approval_is_invisible(): void
    {
        $client = $this->client();
        $this->invoice($client, [
            'invoice_number' => 'INV-DRAFT',
            'status' => Invoice::STATUS_PENDING_APPROVAL,
        ]);

        $login = $this->loginFor($client);

        // It has no number yet and the figures may still change. Showing one
        // would be quoting a price nobody has agreed to send.
        $this->actingAs($login)->get(route('client.invoices'))
            ->assertOk()
            ->assertDontSee('INV-DRAFT');

        $draft = Invoice::where('invoice_number', 'INV-DRAFT')->firstOrFail();
        $this->actingAs($login)->get(route('client.invoices.pdf', $draft->id))->assertNotFound();
    }

    public function test_the_pdf_downloads_without_generating_the_studios_recurring_invoices(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $before = Invoice::count();

        $response = $this->actingAs($this->loginFor($client))
            ->get(route('client.invoices.pdf', $invoice->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        // The whole reason the client group sits outside recurring.catchup.
        $this->assertSame($before, Invoice::count());
    }

    public function test_the_balance_reflects_payments(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, ['total' => 10000]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 4000,
            'paid_on' => today()->toDateString(),
            'recorded_by' => $this->issuer->id,
        ]);

        $this->actingAs($this->loginFor($client))->get(route('client.dashboard'))
            ->assertOk()
            ->assertViewHas('outstanding', 6000.0);
    }

    // ——— Work delivered ———

    public function test_work_is_matched_across_the_spellings_notion_actually_uses(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['title' => 'Exact spelling']);
        $this->item('Sva womenswear', ['title' => 'Different spelling']);
        $this->item('Totally Unrelated Brand', ['title' => 'Somebody elses']);

        // The old column join found only the exact one and missed 98 real items.
        $this->actingAs($this->loginFor($client))->get(route('client.work'))
            ->assertOk()
            ->assertSee('Exact spelling')
            ->assertSee('Different spelling')
            ->assertDontSee('Somebody elses');
    }

    public function test_a_client_with_no_notion_venture_still_matches_by_name(): void
    {
        $client = $this->client(['name' => 'Thor Gym', 'notion_venture' => null]);
        $this->item('THOR', ['title' => 'Gym reel']);

        // Six clients have a null notion_venture and used to see nothing at all.
        $this->actingAs($this->loginFor($client))->get(route('client.work'))
            ->assertOk()
            ->assertSee('Gym reel');
    }

    public function test_unpublished_and_future_dated_work_is_left_out(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['title' => 'Still editing', 'status' => 'To Be Edited']);
        $this->item('SVA Silks', [
            'title' => 'Scheduled for later',
            'published_date' => today()->addWeek()->toDateString(),
        ]);

        // A planner row dated next week is a plan; showing it as delivered
        // would be a promise.
        $this->actingAs($this->loginFor($client))->get(route('client.work'))
            ->assertOk()
            ->assertDontSee('Still editing')
            ->assertDontSee('Scheduled for later');
    }

    public function test_the_work_screen_never_names_the_editor_or_the_tier(): void
    {
        $client = $this->client();
        $this->item('SVA Silks', ['editor' => 'Sanjai', 'tier' => 'Low Effort & Work']);

        $body = $this->actingAs($this->loginFor($client))->get(route('client.work'))->getContent();

        // Internal judgements about staff. A client reading "Low effort"
        // against something they paid for is a conversation nobody meant.
        $this->assertStringNotContainsString('Sanjai', $body);
        $this->assertStringNotContainsString('Low Effort', $body);
    }

    // ——— Shoots ———

    public function test_shoots_show_the_client_only_what_is_theirs_and_nothing_internal(): void
    {
        $client = $this->client();
        $crew = User::factory()->create(['name' => 'Aron Camera']);

        $shoot = Shoot::create([
            'title' => 'Diwali campaign',
            'client_id' => $client->id,
            'starts_at' => now()->addWeek(),
            'location' => 'Studio floor',
            'status' => Shoot::STATUS_CONFIRMED,
            'notes' => 'Client always runs late, budget an extra hour',
        ]);
        $shoot->crew()->create(['user_id' => $crew->id, 'role' => 'Camera']);

        $body = $this->actingAs($this->loginFor($client))->get(route('client.shoots'))
            ->assertOk()
            ->assertSee('Diwali campaign')
            ->assertSee('Studio floor')
            ->getContent();

        $this->assertStringNotContainsString('runs late', $body);
        $this->assertStringNotContainsString('Aron Camera', $body);
    }

    public function test_another_clients_shoot_is_not_listed(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Other Brand', 'notion_venture' => 'Other']);

        Shoot::create([
            'title' => 'Not your shoot',
            'client_id' => $theirs->id,
            'starts_at' => now()->addWeek(),
            'status' => Shoot::STATUS_PLANNED,
        ]);

        $this->actingAs($this->loginFor($mine))->get(route('client.shoots'))
            ->assertOk()
            ->assertDontSee('Not your shoot');
    }

    // ——— The third role must not leak into staff screens ———

    public function test_a_client_is_not_an_employee(): void
    {
        $login = $this->loginFor($this->client());

        // isEmployee() used to be "not an admin", which made this true and let
        // a client through every employee check in the app.
        $this->assertFalse($login->isEmployee());
        $this->assertTrue($login->isClient());
    }

    public function test_a_client_timesheet_cannot_be_opened_by_an_admin(): void
    {
        $login = $this->loginFor($this->client());

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('timesheets.show', $login))
            ->assertNotFound();
    }

    public function test_a_client_does_not_appear_in_staff_pickers(): void
    {
        $login = $this->loginFor($this->client(), ['name' => 'Client Person']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('users.create'))
            ->assertOk()
            ->assertDontSee('Client Person');

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('Client Person');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('my.todos'))
            ->assertOk()
            ->assertDontSee('Client Person');
    }

    // ——— Issuing the login ———

    public function test_an_admin_issues_a_login_from_the_client_screen(): void
    {
        $client = $this->client();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('clients.login.store', $client), [
                'name' => 'SVA Silks',
                'email' => 'sva@example.com',
                'password' => 'a-good-password',
            ])->assertRedirect();

        $login = User::where('email', 'sva@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_CLIENT, $login->role);
        $this->assertSame($client->id, $login->client_id);
    }

    public function test_a_second_login_for_the_same_client_is_refused(): void
    {
        $client = $this->client();
        $this->loginFor($client);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('clients.login.store', $client), [
                'name' => 'Another',
                'email' => 'another@example.com',
                'password' => 'a-good-password',
            ])->assertStatus(409);
    }

    public function test_revoking_a_login_stops_them_signing_in(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->delete(route('clients.login.destroy', $client))
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $login->id]);
    }

    public function test_an_employee_cannot_issue_a_client_login(): void
    {
        $client = $this->client();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->post(route('clients.login.store', $client), [
                'name' => 'Sneaky',
                'email' => 'sneaky@example.com',
                'password' => 'a-good-password',
            ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_deleting_a_client_leaves_the_login_visible_rather_than_vanishing(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $client->delete();

        // nullOnDelete: an account that disappears is one nobody knows to
        // look for. This one is left broken, visible, and fixable.
        $this->assertDatabaseHas('users', ['id' => $login->id, 'client_id' => null]);
        $this->actingAs($login->refresh())->get(route('client.dashboard'))->assertForbidden();
    }

    // ——— The venture mapping this all rests on ———

    public function test_raw_ventures_are_gathered_for_a_client(): void
    {
        $client = $this->client();
        $this->item('SVA Silks');
        $this->item('Sva womenswear');
        $this->item('Totally Unrelated Brand');

        $ventures = TimesheetVenture::rawVenturesFor($client);

        $this->assertContains('SVA Silks', $ventures);
        $this->assertContains('Sva womenswear', $ventures);
        $this->assertNotContains('Totally Unrelated Brand', $ventures);
    }

    public function test_a_client_matching_no_venture_gets_nothing_rather_than_everything(): void
    {
        $client = $this->client(['name' => 'Brand New Client', 'notion_venture' => null]);
        $this->item('SVA Silks');

        // An empty whereIn is a no-op in some drivers, which would have shown
        // this client every other client's work.
        $this->assertSame(0, $client->contentItems()->count());
    }
}
