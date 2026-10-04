<?php

namespace App\Tools\Finance;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\SaasProduct;
use App\Models\User;
use App\Tools\Tool;
use Illuminate\Support\Facades\Gate;

class MoneyDue extends Tool
{
    public function name(): string
    {
        return 'money_due';
    }

    public function title(): string
    {
        return 'Money due: unpaid invoices and upcoming renewals';
    }

    public function group(): string
    {
        return 'Finance';
    }

    public function description(): string
    {
        return 'What money is owed or about to fall due: every unpaid invoice with how many days overdue '
            .'(worst first), software maintenance (AMC) renewals that are overdue or due within the next '
            .'days_ahead days, and recurring invoices the portal will generate in that window. '
            .'USE WHEN asked "who owes us", "what is overdue", "what renewals are coming". '
            .'For costs the studio paid on a client\'s behalf use client_advances_owed instead. '
            .'Read-only: it never sends reminders or records payments.';
    }

    public function permission(): ?string
    {
        return 'invoices.view';
    }

    public function schema(): array
    {
        return $this->object([
            'days_ahead' => ['type' => 'integer', 'description' => 'Optional. How far ahead to look for renewals and recurring invoices; default 30, max 90.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $daysAhead = max(1, min(90, (int) ($arguments['days_ahead'] ?? 30)));
        $horizon = today()->addDays($daysAhead);

        $unpaid = Invoice::unpaid()->with(['client', 'payments'])->get()
            ->map(fn (Invoice $i) => [
                'invoice' => $i->invoice_number,
                'client' => $i->client?->name,
                'balance_due' => round($i->balanceDue(), 2),
                'due_date' => $i->due_date?->toDateString(),
                'days_overdue' => $i->isOverdue() ? (int) $i->due_date->diffInDays(today()) : 0,
            ])
            ->sortByDesc('days_overdue')
            ->values();

        $result = [
            'unpaid_invoices' => [
                'count' => $unpaid->count(),
                'total' => round($unpaid->sum('balance_due'), 2),
                'overdue_total' => round($unpaid->where('days_overdue', '>', 0)->sum('balance_due'), 2),
                'items' => $unpaid->all(),
            ],
            'recurring_invoices_due' => RecurringInvoice::query()
                ->with('client')
                ->where('is_active', true)
                ->whereDate('next_run_on', '<=', $horizon)
                ->orderBy('next_run_on')
                ->get()
                ->map(fn (RecurringInvoice $r) => [
                    'label' => $r->label,
                    'client' => $r->client?->name,
                    'frequency' => $r->frequency,
                    'generates_on' => $r->next_run_on?->toDateString(),
                ])->all(),
        ];

        // Renewals only for someone who can see SaaS products at all.
        if (Gate::forUser($user)->allows('saas-products.view')) {
            $result['amc_renewals'] = SaasProduct::query()
                ->with('client')
                ->whereNotNull('amc_paid_until')
                ->whereDate('amc_paid_until', '<=', $horizon)
                ->orderBy('amc_paid_until')
                ->get()
                ->map(fn (SaasProduct $p) => [
                    'product' => $p->name,
                    'client' => $p->client?->name,
                    'paid_until' => $p->amc_paid_until->toDateString(),
                    'status' => $p->status(),
                ])->all();
        }

        return $result;
    }
}
