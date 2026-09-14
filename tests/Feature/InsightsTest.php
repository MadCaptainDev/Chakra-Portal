<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\Insights\EffortVersusRevenue;
use App\Services\Insights\NeedsAttention;
use App\Services\Insights\PaymentBehaviour;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three questions the portal held the answers to and never asked.
 *
 * The one worth being careful about is the join. Hours are filed under a
 * venture *name* and money under a client *id*, and the whole report is worth
 * nothing if those are matched by hoping the spellings agree — which in this
 * studio's data they frequently do not.
 */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function hours(User $who, string $venture, int $minutes, ?string $on = null): void
    {
        TimesheetEntry::create([
            'user_id' => $who->id,
            'worked_on' => $on ?? today()->toDateString(),
            'task' => 'Editing',
            'task_type' => 'editing',
            'venture' => $venture,
            'minutes' => $minutes,
            'status' => 'completed',
        ]);
    }

    // ——— The join everything rests on ———

    public function test_a_venture_resolves_to_its_client_even_when_the_names_do_not_match(): void
    {
        $client = Client::factory()->create(['name' => 'Suryas Groups of Companies', 'notion_venture' => "Surya's Restaurant"]);

        // These two strings share no word. A name match reports the hours as
        // free work and the revenue as belonging to nobody.
        $this->assertSame($client->id, TimesheetVenture::clientIdFor("Surya's Restaurant"));
    }

    public function test_work_spanning_several_clients_belongs_to_none_of_them(): void
    {
        $this->assertNull(TimesheetVenture::clientIdFor(TimesheetVenture::ALL_CLIENTS));
    }

    // ——— Effort vs revenue ———

    public function test_hours_and_invoices_meet_on_the_client(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $client = Client::factory()->create(['name' => 'SVA Silks and Readymades', 'notion_venture' => 'SVA Silks']);

        $this->hours($staff, 'SVA Silks', 600);
        Invoice::factory()->create(['client_id' => $client->id, 'total' => 30000, 'invoice_date' => today()]);

        $report = EffortVersusRevenue::between(today()->startOfMonth(), today()->endOfMonth());
        $row = $report['rows']->firstWhere('client_id', $client->id);

        $this->assertSame(10.0, $row['hours']);
        $this->assertSame(30000.0, $row['invoiced']);
        $this->assertSame(3000, $row['per_hour']);
    }

    public function test_hours_belonging_to_no_client_are_shown_rather_than_dropped(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $client = Client::factory()->create(['name' => 'SVA Silks', 'notion_venture' => 'SVA Silks']);

        $this->hours($staff, 'SVA Silks', 60);
        $this->hours($staff, TimesheetVenture::ALL_CLIENTS, 120);

        $report = EffortVersusRevenue::between(today()->startOfMonth(), today()->endOfMonth());

        // A report whose hours do not add up to the hours logged is one
        // nobody trusts twice.
        $this->assertSame(2.0, $report['unassigned']['hours']);
        $this->assertSame(3.0, $report['totals']['hours']);
    }

    public function test_cancelled_work_does_not_inflate_the_hours(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        Client::factory()->create(['name' => 'SVA Silks', 'notion_venture' => 'SVA Silks']);

        $this->hours($staff, 'SVA Silks', 60);
        // forceFill, because `status` is deliberately not fillable -- passing
        // it to create() drops it silently and writes a perfectly ordinary
        // entry, which is how this test passed while proving nothing.
        TimesheetEntry::create([
            'user_id' => $staff->id, 'worked_on' => today()->toDateString(), 'task' => 'Scrapped',
            'task_type' => 'editing', 'venture' => 'SVA Silks', 'minutes' => 600,
        ])->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])->save();

        $this->assertSame(1.0, EffortVersusRevenue::between(today()->startOfMonth(), today()->endOfMonth())['totals']['hours']);
    }

    public function test_a_client_invoiced_without_hours_shows_no_rate_rather_than_zero(): void
    {
        $client = Client::factory()->create(['name' => 'Nova', 'notion_venture' => 'Nova']);
        Invoice::factory()->create(['client_id' => $client->id, 'total' => 5000, 'invoice_date' => today()]);

        $row = EffortVersusRevenue::between(today()->startOfMonth(), today()->endOfMonth())['rows']
            ->firstWhere('client_id', $client->id);

        // Work billed this month for hours logged last month. "₹0/hr" would
        // read as a disaster rather than as an empty cell.
        $this->assertNull($row['per_hour']);
    }

    // ——— Who pays ———

    public function test_early_payers_are_told_apart_from_late_ones(): void
    {
        $admin = $this->admin();
        $early = Client::factory()->create(['name' => 'Pays early']);
        $late = Client::factory()->create(['name' => 'Pays late']);

        $a = Invoice::factory()->create(['client_id' => $early->id, 'total' => 1000, 'due_date' => today()]);
        $a->payments()->create(['amount' => 1000, 'paid_on' => today()->subDays(5), 'recorded_by' => $admin->id]);

        $b = Invoice::factory()->create(['client_id' => $late->id, 'total' => 1000, 'due_date' => today()->subDays(10)]);
        $b->payments()->create(['amount' => 1000, 'paid_on' => today(), 'recorded_by' => $admin->id]);

        $rows = PaymentBehaviour::all()->keyBy('name');

        // Signed, because an average that treated "five days early" as five
        // days of lateness would describe the best payer as the worst.
        $this->assertSame(-5, $rows['Pays early']['average_days']);
        $this->assertSame(10, $rows['Pays late']['average_days']);
        $this->assertSame('Pays late', $rows->first()['name']);
    }

    public function test_a_single_payment_is_flagged_as_too_thin_to_judge(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create(['name' => 'New client']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 1000, 'due_date' => today()]);
        $invoice->payments()->create(['amount' => 1000, 'paid_on' => today(), 'recorded_by' => $admin->id]);

        $this->assertFalse(PaymentBehaviour::all()->first()['confident']);
    }

    // ——— What is off right now ———

    public function test_a_shoot_within_three_days_with_no_crew_is_raised(): void
    {
        Shoot::create(['title' => 'Zira Shoot', 'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CONFIRMED]);

        $this->assertStringContainsString('Nobody crewed', NeedsAttention::all()->first()['title']);
    }

    public function test_a_shoot_next_month_is_not_todays_problem(): void
    {
        Shoot::create(['title' => 'Zira Shoot', 'starts_at' => now()->addMonth(), 'status' => Shoot::STATUS_CONFIRMED]);

        $this->assertTrue(NeedsAttention::all()->isEmpty());
    }

    public function test_an_invoice_a_month_past_due_is_raised(): void
    {
        $client = Client::factory()->create(['name' => 'Janet']);
        Invoice::factory()->create([
            'client_id' => $client->id, 'total' => 7500,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => today()->subDays(40),
        ]);

        $item = NeedsAttention::all()->first();
        $this->assertStringContainsString('7,500', $item['title']);
        $this->assertStringContainsString('40 days', $item['title']);
    }

    public function test_nothing_wrong_is_an_empty_list_rather_than_a_page_of_nothing(): void
    {
        $this->assertTrue(NeedsAttention::all()->isEmpty());
    }

    // ——— The screen ———

    public function test_an_admin_can_open_the_insights_page(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $client = Client::factory()->create(['name' => 'SVA Silks', 'notion_venture' => 'SVA Silks']);
        $this->hours($staff, 'SVA Silks', 600);
        Invoice::factory()->create(['client_id' => $client->id, 'total' => 30000, 'invoice_date' => today()]);

        $this->actingAs($this->admin())
            ->get(route('insights.index'))
            ->assertOk()
            ->assertSee('Effort vs revenue')
            ->assertSee('SVA Silks');
    }

    public function test_an_employee_cannot(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        // An effective hourly rate per client is not a Timesheets-permission
        // kind of number.
        $this->actingAs($employee)->get(route('insights.index'))->assertForbidden();
    }

    public function test_a_hand_edited_month_falls_back_rather_than_breaking(): void
    {
        $this->actingAs($this->admin())
            ->get(route('insights.index', ['month' => 'not-a-month']))
            ->assertOk();
    }
}
