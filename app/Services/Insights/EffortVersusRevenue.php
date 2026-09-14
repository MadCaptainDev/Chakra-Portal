<?php

namespace App\Services\Insights;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TimesheetEntry;
use App\Support\TimesheetVenture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the studio's hours go, against what those hours were billed for.
 *
 * The portal has always known both halves and never put them together: hours
 * live on timesheet entries under a venture name, money lives on invoices
 * under a client id, and nothing joined the two. Set side by side they answer
 * the question a production studio actually runs on -- not "how much did we
 * bill" but "what did it cost us to bill it".
 *
 * The join is TimesheetVenture::clientIdFor(), not a name match. That matters
 * more than it sounds: "Surya's Restaurant" on a timesheet and "Suryas Groups
 * of Companies" on an invoice are one customer and share no matching word, so
 * a name match silently drops their entire revenue and reports the hours as
 * free work.
 *
 * Hours that belong to no client -- ALL_CLIENTS, internal projects -- are
 * counted and shown as their own line rather than dropped. A report whose
 * hours do not add up to the hours logged is a report nobody trusts twice.
 */
final class EffortVersusRevenue
{
    /**
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     unassigned: array<string, mixed>,
     *     totals: array<string, mixed>
     * }
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        $minutesByClient = [];
        $unassignedMinutes = 0.0;
        $unassignedVentures = [];

        /*
         * Grouped by venture in SQL and mapped to clients in PHP. The mapping
         * is a text normalisation, not something a join can express -- and
         * there are a few dozen ventures against tens of thousands of entries,
         * so the grouping is what keeps this one cheap query.
         */
        $byVenture = TimesheetEntry::query()
            ->counted()
            ->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('venture, SUM(minutes) as minutes')
            ->groupBy('venture')
            ->get();

        foreach ($byVenture as $row) {
            $minutes = (float) $row->minutes;
            $clientId = TimesheetVenture::clientIdFor($row->venture);

            if ($clientId === null) {
                $unassignedMinutes += $minutes;
                $unassignedVentures[] = blank($row->venture) ? 'No venture set' : $row->venture;

                continue;
            }

            $minutesByClient[$clientId] = ($minutesByClient[$clientId] ?? 0) + $minutes;
        }

        $invoiced = Invoice::query()
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('client_id, SUM(total) as total, COUNT(*) as invoices')
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');

        $collected = Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereBetween('payments.paid_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('invoices.client_id as client_id, SUM(payments.amount) as total')
            ->groupBy('invoices.client_id')
            ->get()
            ->keyBy('client_id');

        $clientIds = collect(array_keys($minutesByClient))
            ->merge($invoiced->keys())
            ->merge($collected->keys())
            ->filter()
            ->unique();

        $clients = Client::query()->whereIn('id', $clientIds)->get()->keyBy('id');
        $totalMinutes = array_sum($minutesByClient) + $unassignedMinutes;

        $rows = $clientIds
            ->map(function ($clientId) use ($clients, $minutesByClient, $invoiced, $collected, $totalMinutes) {
                $minutes = (float) ($minutesByClient[$clientId] ?? 0);
                $billed = (float) ($invoiced[$clientId]->total ?? 0);

                return [
                    'client' => $clients[$clientId] ?? null,
                    'client_id' => $clientId,
                    'name' => $clients[$clientId]->name ?? 'Client #'.$clientId,
                    'hours' => round($minutes / 60, 1),
                    'share' => $totalMinutes > 0 ? round($minutes / $totalMinutes * 100) : 0,
                    'invoiced' => $billed,
                    'invoices' => (int) ($invoiced[$clientId]->invoices ?? 0),
                    'collected' => (float) ($collected[$clientId]->total ?? 0),
                    /*
                     * Null rather than zero when no hours were logged: a
                     * client invoiced for work done last month has no rate
                     * this month, and printing "₹0/hr" would read as a
                     * disaster rather than as an empty cell.
                     */
                    'per_hour' => $minutes > 0 ? (int) round($billed / ($minutes / 60)) : null,
                ];
            })
            ->sortByDesc('hours')
            ->values();

        return [
            'rows' => $rows,
            'unassigned' => [
                'hours' => round($unassignedMinutes / 60, 1),
                'share' => $totalMinutes > 0 ? round($unassignedMinutes / $totalMinutes * 100) : 0,
                // Named, because "48 hours on nothing in particular" is a
                // question, and the answer is usually one venture nobody
                // linked to a client yet.
                'ventures' => collect($unassignedVentures)->unique()->sort()->values()->all(),
            ],
            'totals' => [
                'hours' => round($totalMinutes / 60, 1),
                'invoiced' => $rows->sum('invoiced'),
                'collected' => $rows->sum('collected'),
                'per_hour' => $totalMinutes > 0
                    ? (int) round($rows->sum('invoiced') / ($totalMinutes / 60))
                    : null,
            ],
        ];
    }
}
