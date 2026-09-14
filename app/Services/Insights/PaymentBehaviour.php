<?php

namespace App\Services\Insights;

use App\Models\Payment;
use Illuminate\Support\Collection;

/**
 * How long each client actually takes to pay, measured against the date they
 * were asked to.
 *
 * Not the same question as "who owes money", which the dashboard already
 * answers. This is about habit: a client who is always eleven days late is a
 * client to invoice eleven days earlier, and one who pays before the due date
 * every time never needs chasing at all. Both facts are in the data already
 * and neither is visible anywhere.
 *
 * Days are counted in PHP rather than with DATEDIFF, because that is MySQL's
 * spelling and the tests run on SQLite -- a report that can only be exercised
 * against production is a report nobody exercises.
 */
final class PaymentBehaviour
{
    /**
     * Fewer than this and an average is an anecdote. Kept on the row rather
     * than filtered out, so a new client still appears -- flagged as thin
     * rather than hidden.
     */
    public const CONFIDENT_AFTER = 3;

    /** @return Collection<int, array<string, mixed>> */
    public static function all(): Collection
    {
        $payments = Payment::query()
            ->with(['invoice.client'])
            ->whereHas('invoice', fn ($query) => $query->whereNotNull('due_date'))
            ->get();

        return $payments
            ->groupBy(fn (Payment $payment) => $payment->invoice?->client_id)
            ->filter(fn (Collection $group, $clientId) => filled($clientId))
            ->map(function (Collection $group) {
                $client = $group->first()->invoice->client;

                $lateness = $group
                    // Negative is early. Signed on purpose: an average that
                    // treated "five days early" as five days of lateness
                    // would describe the studio's best payer as its worst.
                    ->map(fn (Payment $payment) => $payment->invoice->due_date->diffInDays($payment->paid_on, false))
                    ->values();

                return [
                    'client_id' => $client?->id,
                    'name' => $client?->name ?? 'No client',
                    'payments' => $lateness->count(),
                    'paid' => (float) $group->sum('amount'),
                    'average_days' => (int) round($lateness->avg()),
                    'worst_days' => (int) $lateness->max(),
                    'last_paid_on' => $group->max('paid_on'),
                    'confident' => $lateness->count() >= self::CONFIDENT_AFTER,
                ];
            })
            ->sortByDesc('average_days')
            ->values();
    }
}
