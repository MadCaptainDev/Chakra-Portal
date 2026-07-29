<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_invoice_past_due_date_is_overdue(): void
    {
        $invoice = Invoice::factory()->overdue()->create();

        $this->assertTrue($invoice->isOverdue());
    }

    public function test_paid_invoice_past_due_date_is_not_overdue(): void
    {
        $invoice = Invoice::factory()->overdue()->create(['status' => Invoice::STATUS_PAID]);

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_unpaid_invoice_without_due_date_is_not_overdue(): void
    {
        $invoice = Invoice::factory()->create(['due_date' => null]);

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_unpaid_invoice_with_future_due_date_is_not_overdue(): void
    {
        $invoice = Invoice::factory()->create(['due_date' => now()->addDays(5)->format('Y-m-d')]);

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_pending_approval_invoice_is_never_overdue(): void
    {
        $invoice = Invoice::factory()->pendingApproval()->create(['due_date' => now()->subDays(5)->format('Y-m-d')]);

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_overdue_scope_filters_correctly(): void
    {
        $overdue = Invoice::factory()->overdue()->create();
        Invoice::factory()->create(); // not overdue

        $results = Invoice::overdue()->get();

        $this->assertCount(1, $results);
        $this->assertSame($overdue->id, $results->first()->id);
    }
}
