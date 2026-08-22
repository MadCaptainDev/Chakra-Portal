<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Notifications\RecurringInvoicesGenerated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RecurringInvoiceGenerator
{
    /**
     * Generate any invoices that are due, catching up on every missed
     * period per schedule. Returns the number of invoices created.
     * Idempotent: a schedule with next_run_on in the future is left
     * untouched, so running this twice in a row creates nothing extra.
     */
    public function run(): int
    {
        $totalCreated = 0;

        RecurringInvoice::query()->due()->with(['items', 'client'])->each(function (RecurringInvoice $schedule) use (&$totalCreated) {
            $totalCreated += $this->generateForSchedule($schedule);
        });

        if ($totalCreated > 0) {
            $this->notifyStaff($totalCreated);
        }

        return $totalCreated;
    }

    private function generateForSchedule(RecurringInvoice $schedule): int
    {
        $created = 0;

        // Guard against a runaway loop if a schedule's next_run_on is
        // badly stale (e.g. left inactive for years) - catch up at
        // most 24 periods in one pass rather than generating hundreds
        // of invoices at once.
        while ($schedule->next_run_on->lte(today()) && $created < 24) {
            $this->createPendingInvoice($schedule);
            $created++;
            $schedule->next_run_on = $schedule->nextRunAfter($schedule->next_run_on);
        }

        if ($created > 0) {
            $schedule->last_generated_on = today()->format('Y-m-d');
            $schedule->save();
        }

        return $created;
    }

    private function createPendingInvoice(RecurringInvoice $schedule): Invoice
    {
        return DB::transaction(function () use ($schedule) {
            $invoiceDate = $schedule->next_run_on;

            $invoice = Invoice::create([
                'invoice_number' => null,
                'client_id' => $schedule->client_id,
                'invoice_date' => $invoiceDate->format('Y-m-d'),
                'due_date' => $schedule->due_days
                    ? $invoiceDate->copy()->addDays($schedule->due_days)->format('Y-m-d')
                    : null,
                'intro_text' => $schedule->intro_text,
                'discount_label' => $schedule->discount_label,
                'discount_amount' => $schedule->discount_amount,
                'status' => Invoice::STATUS_PENDING_APPROVAL,
                'created_by' => $schedule->created_by,
                'recurring_invoice_id' => $schedule->id,
                'saas_product_id' => $schedule->saas_product_id,
                // Every recurring schedule with a saas_product_id was
                // created by SaasProductController::setupAmc() -- there is
                // no other way to link one -- so it is AMC by construction.
                'saas_invoice_type' => $schedule->saas_product_id ? Invoice::STUDIO_TYPE_AMC : null,
            ]);

            foreach ($schedule->items as $item) {
                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->quantity * $item->unit_price,
                    'sort_order' => $item->sort_order,
                ]);
            }

            $invoice->load('items');
            $invoice->recalculateTotals();
            $invoice->save();

            return $invoice;
        });
    }

    private function notifyStaff(int $count): void
    {
        $notificationEmail = CompanySetting::current()->notification_email;

        if ($notificationEmail) {
            Notification::route('mail', $notificationEmail)->notify(new RecurringInvoicesGenerated($count));

            return;
        }

        Notification::send(User::staff()->get(), new RecurringInvoicesGenerated($count));
    }
}
