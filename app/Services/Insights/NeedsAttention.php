<?php

namespace App\Services\Insights;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Shoot;
use Illuminate\Support\Collection;

/**
 * The things that are off right now, and nothing else.
 *
 * A report gets read twice and then filed. An exception gets acted on, and
 * only exists while something is wrong -- so an empty list here is the good
 * outcome, not a broken page.
 *
 * Everything below is a fact already in the database that nothing currently
 * surfaces until somebody goes looking: a shoot the day after tomorrow with
 * nobody on it, a client who has stopped paying while still owing, an invoice
 * that quietly crossed a month.
 *
 * Each item carries where to go and do something about it, because an alert
 * that does not link anywhere is a worry rather than a task.
 */
final class NeedsAttention
{
    /** A shoot this close with no crew is a problem today, not on the day. */
    private const CREW_WARNING_HOURS = 72;

    /** Past this, an unpaid invoice has stopped being a timing difference. */
    private const STALE_INVOICE_DAYS = 30;

    /** A paying client who has gone this quiet while still owing. */
    private const GONE_QUIET_DAYS = 60;

    /** @return Collection<int, array{severity: string, title: string, detail: string, url: ?string}> */
    public static function all(): Collection
    {
        return collect([
            ...self::uncrewedShoots(),
            ...self::staleInvoices(),
            ...self::clientsGoneQuiet(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function uncrewedShoots(): array
    {
        return Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereBetween('starts_at', [now(), now()->addHours(self::CREW_WARNING_HOURS)])
            ->with('crew', 'client')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Shoot $shoot) => $shoot->crew->isEmpty())
            ->map(fn (Shoot $shoot) => [
                'severity' => 'urgent',
                'title' => 'Nobody crewed: '.$shoot->title,
                'detail' => trim(($shoot->client?->name ? $shoot->client->name.' — ' : '')
                    .$shoot->starts_at->diffForHumans().', '.$shoot->starts_at->format('D j M g:i A')),
                'url' => route('shoots.show', $shoot),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function staleInvoices(): array
    {
        return Invoice::unpaid()
            ->with('client', 'payments')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->isOverdue()
                && $invoice->due_date->diffInDays(now()) >= self::STALE_INVOICE_DAYS)
            ->sortByDesc(fn (Invoice $invoice) => $invoice->due_date->diffInDays(now()))
            ->map(fn (Invoice $invoice) => [
                'severity' => 'urgent',
                'title' => '₹'.number_format($invoice->balanceDue(), 0).' unpaid for '
                    .(int) $invoice->due_date->diffInDays(now()).' days',
                'detail' => ($invoice->client?->name ?? 'No client').' — '.$invoice->invoice_number
                    .', due '.$invoice->due_date->format('j M Y'),
                'url' => route('invoices.show', $invoice),
            ])
            ->values()
            ->all();
    }

    /**
     * Clients who owe money and have gone quiet.
     *
     * Distinct from an overdue invoice: this is about the relationship rather
     * than the document. Somebody who paid every month until three months ago
     * and still owes is a conversation, and the invoice list will not start it.
     *
     * @return list<array<string, mixed>>
     */
    private static function clientsGoneQuiet(): array
    {
        $unpaid = Invoice::unpaid()->with('client', 'payments')->get()->groupBy('client_id');

        return Client::query()
            ->whereIn('id', $unpaid->keys()->filter())
            ->where('is_active', true)
            ->with(['invoices.payments'])
            ->get()
            ->map(function (Client $client) use ($unpaid) {
                $lastPaid = $client->invoices
                    ->flatMap(fn (Invoice $invoice) => $invoice->payments)
                    ->max('paid_on');

                $owed = $unpaid[$client->id]->sum(fn (Invoice $invoice) => $invoice->balanceDue());

                // Never paid at all is a different conversation, and the
                // overdue list above already has it covered.
                if ($lastPaid === null || $owed <= 0) {
                    return null;
                }

                $quietFor = (int) $lastPaid->diffInDays(now());

                return $quietFor < self::GONE_QUIET_DAYS ? null : [
                    'severity' => 'watch',
                    'title' => $client->name.' has gone quiet',
                    'detail' => 'Owes ₹'.number_format($owed, 0).', last paid '
                        .$lastPaid->format('j M Y').' ('.$quietFor.' days ago)',
                    'url' => route('clients.show', $client),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
