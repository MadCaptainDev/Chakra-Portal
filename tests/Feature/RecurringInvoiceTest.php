<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\RecurringInvoiceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): RecurringInvoice
    {
        $schedule = RecurringInvoice::factory()->create($overrides);
        $schedule->items()->create([
            'description' => 'Retainer Fee',
            'quantity' => 1,
            'unit_price' => 5000,
            'sort_order' => 0,
        ]);

        return $schedule;
    }

    public function test_generates_pending_invoice_with_no_number(): void
    {
        $schedule = $this->makeSchedule(['next_run_on' => today()->format('Y-m-d')]);

        $created = app(RecurringInvoiceGenerator::class)->run();

        $this->assertSame(1, $created);

        $invoice = Invoice::where('recurring_invoice_id', $schedule->id)->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame(Invoice::STATUS_PENDING_APPROVAL, $invoice->status);
        $this->assertSame('5000.00', (string) $invoice->total);
    }

    public function test_advances_next_run_on_after_generating(): void
    {
        $schedule = $this->makeSchedule([
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'day_of_month' => today()->day,
            'next_run_on' => today()->format('Y-m-d'),
        ]);

        app(RecurringInvoiceGenerator::class)->run();

        $schedule->refresh();
        $this->assertTrue($schedule->next_run_on->greaterThan(today()));
        $this->assertEquals(today()->format('Y-m-d'), $schedule->last_generated_on->format('Y-m-d'));
    }

    public function test_does_not_double_generate_on_a_second_run(): void
    {
        $this->makeSchedule(['next_run_on' => today()->format('Y-m-d')]);

        $generator = app(RecurringInvoiceGenerator::class);
        $first = $generator->run();
        $second = $generator->run();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, Invoice::count());
    }

    public function test_catches_up_multiple_missed_periods(): void
    {
        $this->makeSchedule([
            'frequency' => RecurringInvoice::FREQUENCY_WEEKLY,
            'next_run_on' => today()->subWeeks(3)->format('Y-m-d'),
        ]);

        $created = app(RecurringInvoiceGenerator::class)->run();

        // 3 weeks ago, 2 weeks ago, 1 week ago, and today all fall due.
        $this->assertGreaterThanOrEqual(3, $created);
        $this->assertSame($created, Invoice::count());
    }

    public function test_skips_inactive_schedules(): void
    {
        $this->makeSchedule([
            'next_run_on' => today()->format('Y-m-d'),
            'is_active' => false,
        ]);

        $created = app(RecurringInvoiceGenerator::class)->run();

        $this->assertSame(0, $created);
        $this->assertSame(0, Invoice::count());
    }

    public function test_monthly_schedule_clamps_at_month_end_and_returns_to_anchor_day(): void
    {
        $schedule = new RecurringInvoice(['frequency' => RecurringInvoice::FREQUENCY_MONTHLY, 'day_of_month' => 31]);

        $date = Carbon::parse('2026-01-31');
        $date = $schedule->nextRunAfter($date);
        $this->assertSame('2026-02-28', $date->format('Y-m-d'));

        $date = $schedule->nextRunAfter($date);
        $this->assertSame('2026-03-31', $date->format('Y-m-d'));

        $date = $schedule->nextRunAfter($date);
        $this->assertSame('2026-04-30', $date->format('Y-m-d'));

        $date = $schedule->nextRunAfter($date);
        $this->assertSame('2026-05-31', $date->format('Y-m-d'));
    }

    public function test_generating_sends_a_notification_to_staff(): void
    {
        Notification::fake();

        $this->makeSchedule(['next_run_on' => today()->format('Y-m-d')]);
        User::factory()->create();

        app(RecurringInvoiceGenerator::class)->run();

        Notification::assertCount(User::count());
    }

    public function test_due_days_sets_due_date_relative_to_invoice_date(): void
    {
        $schedule = $this->makeSchedule([
            'next_run_on' => today()->format('Y-m-d'),
            'due_days' => 10,
        ]);

        app(RecurringInvoiceGenerator::class)->run();

        $invoice = Invoice::where('recurring_invoice_id', $schedule->id)->first();
        $this->assertEquals(today()->addDays(10)->format('Y-m-d'), $invoice->due_date->format('Y-m-d'));
    }
}
