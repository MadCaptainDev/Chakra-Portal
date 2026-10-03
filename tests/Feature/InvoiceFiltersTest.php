<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice list's client filter and "More filters": period, amount,
 * sort -- and the totals and CSV export following exactly the same set.
 */
class InvoiceFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $zira;

    private Client $riya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->zira = Client::factory()->create(['name' => 'Zira Bridal']);
        $this->riya = Client::factory()->create(['name' => 'Riya Makeover']);
    }

    public function test_picking_a_client_shows_all_their_invoices_not_just_this_month(): void
    {
        $now = $this->invoice($this->zira, 10000, now());
        $old = $this->invoice($this->zira, 20000, now()->subMonthsNoOverflow(3));
        $other = $this->invoice($this->riya, 5000, now());

        $response = $this->actingAs($this->admin)->get(route('invoices.index', ['client' => $this->zira->id]));

        $response->assertOk()->assertSee('All time')->assertSee('Client: Zira Bridal');
        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$now->id, $old->id], $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(30000.0, $response->viewData('summary')['invoiced']);
    }

    public function test_a_client_and_a_month_together_stay_on_that_month(): void
    {
        $this->invoice($this->zira, 10000, now());
        $this->invoice($this->zira, 20000, now()->subMonthsNoOverflow(3));

        $response = $this->actingAs($this->admin)
            ->get(route('invoices.index', ['client' => $this->zira->id, 'month' => now()->format('Y-m')]));

        $this->assertSame(1, $response->viewData('invoices')->total());
    }

    public function test_date_range_amount_and_sort(): void
    {
        $small = $this->invoice($this->zira, 3000, now()->subDays(10));
        $big = $this->invoice($this->riya, 50000, now()->subDays(5));
        $mid = $this->invoice($this->riya, 12000, now()->subDays(2));
        $this->invoice($this->riya, 12000, now()->subMonthsNoOverflow(6));

        $response = $this->actingAs($this->admin)->get(route('invoices.index', [
            'period' => 'range',
            'from' => now()->subDays(20)->toDateString(),
            'to' => now()->toDateString(),
            'min' => 5000,
            'sort' => 'amount_desc',
        ]));

        $this->assertSame([$big->id, $mid->id], $response->viewData('invoices')->pluck('id')->all());
        $this->assertNotContains($small->id, $response->viewData('invoices')->pluck('id')->all());
    }

    public function test_the_summary_splits_collected_outstanding_and_overdue(): void
    {
        $paid = $this->invoice($this->zira, 10000, now(), Invoice::STATUS_PAID);
        Payment::create(['invoice_id' => $paid->id, 'amount' => 10000, 'paid_on' => now(), 'recorded_by' => $this->admin->id]);

        $partOverdue = $this->invoice($this->zira, 8000, now(), Invoice::STATUS_UNPAID, now()->subDay());
        Payment::create(['invoice_id' => $partOverdue->id, 'amount' => 3000, 'paid_on' => now(), 'recorded_by' => $this->admin->id]);

        $this->invoice($this->zira, 2000, now(), Invoice::STATUS_UNPAID, now()->addWeek());

        $summary = $this->actingAs($this->admin)
            ->get(route('invoices.index', ['client' => $this->zira->id]))
            ->viewData('summary');

        $this->assertSame(3, $summary['count']);
        $this->assertSame(20000.0, $summary['invoiced']);
        $this->assertSame(13000.0, $summary['collected']);
        $this->assertSame(7000.0, $summary['outstanding']);
        $this->assertSame(5000.0, $summary['overdue']);
    }

    public function test_export_is_exactly_the_filtered_list_as_csv(): void
    {
        $this->invoice($this->zira, 10000, now(), Invoice::STATUS_UNPAID, null, 'CP-0101');
        $this->invoice($this->zira, 4000, now()->subMonthsNoOverflow(2), Invoice::STATUS_PAID, null, 'CP-0099');
        $this->invoice($this->riya, 7000, now(), Invoice::STATUS_UNPAID, null, 'CP-0102');

        $response = $this->actingAs($this->admin)
            ->get(route('invoices.export', ['client' => $this->zira->id]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('zira-bridal', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('CP-0101,"Zira Bridal",Production', $csv);
        $this->assertStringContainsString('CP-0099', $csv);
        $this->assertStringNotContainsString('CP-0102', $csv);
    }

    public function test_bad_filter_values_fall_back_rather_than_error(): void
    {
        $this->actingAs($this->admin)
            ->get(route('invoices.index', ['period' => 'range', 'from' => 'nope', 'min' => 'abc', 'sort' => 'drop table', 'status' => 'weird']))
            ->assertOk()
            ->assertSee(now()->format('F Y'));
    }

    private function invoice(
        Client $client,
        float $total,
        $date,
        string $status = Invoice::STATUS_UNPAID,
        $due = null,
        ?string $number = null,
    ): Invoice {
        return Invoice::factory()->create(array_filter([
            'client_id' => $client->id,
            'total' => $total,
            'subtotal' => $total,
            'invoice_date' => $date->toDateString(),
            'due_date' => $due?->toDateString(),
            'status' => $status,
            'invoice_number' => $number,
        ], fn ($v) => $v !== null));
    }
}
