<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\McpToken;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice MCP tools: get, create, edit, duplicate, and recurring
 * schedules -- and the guards that stop a model double-billing a client.
 */
class McpInvoiceToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->token = McpToken::issue($this->admin, 'Test')['plain'];
    }

    private function tool(string $name, array $arguments = []): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])
            ->assertOk()->json('result');
    }

    private function ok(string $name, array $arguments = []): array
    {
        $result = $this->tool($name, $arguments);
        $this->assertArrayNotHasKey('isError', $result, $result['content'][0]['text'] ?? '');

        return json_decode($result['content'][0]['text'], true);
    }

    private function refused(string $name, array $arguments, string $contains): void
    {
        $result = $this->tool($name, $arguments);
        $this->assertTrue($result['isError'] ?? false, 'Expected a refusal');
        $this->assertStringContainsString($contains, $result['content'][0]['text']);
    }

    private function riya(): Client
    {
        return Client::create(['name' => 'Riya Makeover Artisty']);
    }

    private function novemberInvoice(Client $client): array
    {
        return $this->ok('create_invoice', [
            'client' => (string) $client->id,
            'invoice_date' => '2026-11-01',
            'items' => [
                ['description' => 'Reels Making', 'quantity' => 14, 'unit_price' => 1300],
                ['description' => 'Posts', 'quantity' => 5, 'unit_price' => 100],
                ['description' => 'SMM', 'quantity' => 1, 'unit_price' => 15500],
            ],
            'discount_label' => 'Complimentary: 5 Posts',
            'discount_amount' => 500,
        ]);
    }

    public function test_create_invoice_numbers_and_totals_it_and_sends_nothing(): void
    {
        $invoice = $this->novemberInvoice($this->riya());

        $this->assertSame(34200.0, (float) $invoice['subtotal']);
        $this->assertSame(33700.0, (float) $invoice['total']);
        $this->assertFalse($invoice['sent_to_client']);
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertNotSame('(not numbered until approved)', $invoice['number']);
        $this->assertSame(1300.0, (float) $invoice['lines'][0]['unit_price']);
    }

    public function test_a_second_invoice_in_the_same_month_needs_an_explicit_yes(): void
    {
        $riya = $this->riya();
        $first = $this->novemberInvoice($riya);

        $this->refused('create_invoice', [
            'client' => (string) $riya->id, 'invoice_date' => '2026-11-15',
            'items' => [['description' => 'Extra reel', 'quantity' => 1, 'unit_price' => 1300]],
        ], $first['number']);

        $this->ok('create_invoice', [
            'client' => (string) $riya->id, 'invoice_date' => '2026-11-15', 'allow_second_this_month' => true,
            'items' => [['description' => 'Extra reel', 'quantity' => 1, 'unit_price' => 1300]],
        ]);
        $this->assertSame(2, Invoice::count());
    }

    public function test_bad_lines_and_a_label_less_discount_are_refused(): void
    {
        $riya = $this->riya();

        $this->refused('create_invoice', ['client' => (string) $riya->id, 'items' => [['description' => 'X', 'quantity' => 0, 'unit_price' => 1]]], 'quantity');
        $this->refused('create_invoice', ['client' => (string) $riya->id, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]], 'discount_amount' => 50], 'discount_label');
        $this->assertSame(0, Invoice::count());
    }

    public function test_get_invoice_finds_it_by_number(): void
    {
        $made = $this->novemberInvoice($this->riya());

        $got = $this->ok('get_invoice', ['invoice' => strtolower($made['number'])]);

        $this->assertSame($made['invoice_id'], $got['invoice_id']);
        $this->assertCount(3, $got['lines']);
    }

    public function test_update_invoice_changes_only_what_is_passed_and_shows_before_and_after(): void
    {
        $made = $this->novemberInvoice($this->riya());

        $result = $this->ok('update_invoice', [
            'invoice' => $made['number'],
            'items' => [
                ['description' => 'Reels Making', 'quantity' => 15, 'unit_price' => 1300],
                ['description' => 'Posts', 'quantity' => 5, 'unit_price' => 100],
                ['description' => 'SMM', 'quantity' => 1, 'unit_price' => 15500],
            ],
        ]);

        $this->assertSame(33700.0, (float) $result['before']['total']);
        $this->assertSame(35000.0, (float) $result['after']['total']);
        $this->assertSame('Complimentary: 5 Posts', $result['after']['discount']['label']);

        $cleared = $this->ok('update_invoice', ['invoice' => $made['number'], 'discount_label' => '', 'discount_amount' => 0]);
        $this->assertNull($cleared['after']['discount']);
        $this->assertSame(35500.0, (float) $cleared['after']['total']);
    }

    public function test_update_invoice_will_not_go_below_what_was_paid(): void
    {
        $made = $this->novemberInvoice($this->riya());
        $invoice = Invoice::find($made['invoice_id']);
        $invoice->payments()->create(['amount' => 20000, 'paid_on' => '2026-11-02', 'recorded_by' => $this->admin->id]);

        $this->refused('update_invoice', [
            'invoice' => $made['number'],
            'items' => [['description' => 'SMM', 'quantity' => 1, 'unit_price' => 15500]],
        ], 'already paid');

        $this->assertSame(33700.0, (float) $invoice->fresh()->total);
        $this->assertCount(3, $invoice->fresh()->items);
    }

    public function test_paying_in_full_then_raising_the_total_puts_it_back_to_unpaid(): void
    {
        $made = $this->novemberInvoice($this->riya());
        $invoice = Invoice::find($made['invoice_id']);
        $invoice->payments()->create(['amount' => 33700, 'paid_on' => '2026-11-02', 'recorded_by' => $this->admin->id]);
        $invoice->refresh()->recalculateStatus();
        $this->assertSame('paid', $invoice->fresh()->status);

        $after = $this->ok('update_invoice', ['invoice' => $made['number'], 'discount_label' => '', 'discount_amount' => 0])['after'];

        $this->assertSame('unpaid', $after['status']);
        $this->assertSame(500.0, (float) $after['balance_due']);
    }

    public function test_duplicate_copies_lines_into_a_new_month(): void
    {
        $made = $this->novemberInvoice($this->riya());

        $this->refused('duplicate_invoice', ['invoice' => $made['number'], 'invoice_date' => '2026-11-20'], 'already has an invoice');

        $copy = $this->ok('duplicate_invoice', ['invoice' => $made['number'], 'invoice_date' => '2026-12-01']);

        $this->assertNotSame($made['number'], $copy['number']);
        $this->assertSame('2026-12-01', $copy['invoice_date']);
        $this->assertSame($made['total'], $copy['total']);
        $this->assertSame([], $copy['payments']);
    }

    public function test_recurring_schedule_from_an_invoice_then_paused(): void
    {
        $this->travelTo('2026-11-02 10:00');
        $riya = $this->riya();
        $made = $this->novemberInvoice($riya);

        $schedule = $this->ok('create_recurring_invoice', ['from_invoice' => $made['number'], 'day_of_month' => 1]);

        $this->assertSame('2026-12-01', $schedule['next_invoice_on']);
        $this->assertCount(3, $schedule['lines']);
        $this->assertSame('Complimentary: 5 Posts', $schedule['discount']['label']);

        $this->refused('create_recurring_invoice', ['from_invoice' => $made['number']], 'already has an active recurring invoice');

        $paused = $this->ok('update_recurring_invoice', [
            'schedule_id' => $schedule['schedule_id'], 'active' => false,
            'items' => [['description' => 'Reels Making', 'quantity' => '{{published_reels}}', 'unit_price' => 1300]],
        ]);
        $this->assertFalse($paused['after']['active']);
        $this->assertSame('{{published_reels}}', $paused['after']['lines'][0]['quantity']);

        $list = $this->ok('list_recurring_invoices', ['client' => 'Riya']);
        $this->assertCount(1, $list['schedules']);
    }

    public function test_recurring_refuses_a_past_start_date(): void
    {
        $riya = $this->riya();

        $this->refused('create_recurring_invoice', [
            'client' => (string) $riya->id, 'next_run_on' => '2020-01-01',
            'items' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 35000]],
        ], 'past');
        $this->assertSame(0, RecurringInvoice::count());
    }

    public function test_an_employee_without_invoice_permissions_gets_none_of_these(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $token = McpToken::issue($employee, 'Test')['plain'];

        $names = collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name');

        foreach (['get_invoice', 'create_invoice', 'update_invoice', 'duplicate_invoice', 'list_recurring_invoices', 'create_recurring_invoice', 'update_recurring_invoice'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
    }
}
