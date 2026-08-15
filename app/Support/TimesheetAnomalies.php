<?php

namespace App\Support;

use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Timesheet rows that cannot be true.
 *
 * Not an accusation of dishonesty, and the wording throughout is careful about
 * that -- the commonest cause by far is somebody logging how long a *job* ran
 * rather than how long they worked on it, which is a misunderstanding of the
 * form and not a lie. What it is, unambiguously, is a warning that any figure
 * derived from these rows is wrong.
 *
 * This exists because the editor throughput screen divides output by hours. One
 * entry of "24 hours" silently halves somebody's apparent productivity, and a
 * screen that ranks people on a number it cannot vouch for is worse than no
 * screen. So the flags are computed alongside the figures and shown next to
 * them, rather than living in a report nobody opens.
 */
class TimesheetAnomalies
{
    /**
     * Longer than anyone works in one sitting. Not impossible -- a shoot day
     * genuinely runs twelve hours -- so this is the softest flag and says so.
     */
    public const LONG_ENTRY_MINUTES = 720;

    /**
     * A whole calendar day, to the minute. There is no work pattern that
     * produces exactly 1440 minutes; it is the signature of a date range being
     * entered where a duration was asked for.
     */
    public const FULL_DAY_MINUTES = 1440;

    /** More than this in one day is not a long day, it is two days in one row. */
    public const IMPOSSIBLE_DAY_MINUTES = 960;

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    /**
     * Everything wrong with a window of timesheet, worst first.
     *
     * $only scopes it to one person, which is what the employee's own screen
     * passes. It is a query filter rather than something the caller filters
     * afterwards, so a mistake upstream cannot show somebody else's rows to
     * somebody who should never see them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function between(Carbon $from, Carbon $to, ?User $only = null): Collection
    {
        $entries = TimesheetEntry::with('user')
            ->when($only, fn ($query) => $query->where('user_id', $only->id))
            ->where('worked_on', '>=', $from->toDateString())
            ->where('worked_on', '<', $to->copy()->addDay()->toDateString())
            ->orderByDesc('worked_on')
            ->get();

        return collect()
            ->merge(self::impossibleDays($entries))
            ->merge(self::fullDayEntries($entries))
            ->merge(self::longEntries($entries))
            ->merge(self::overlapping($entries))
            ->merge(self::unattributed($entries))
            ->sortBy(fn (array $flag) => [
                array_search($flag['severity'], [self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW], true),
                -$flag['minutes'],
            ])
            ->values();
    }

    /**
     * Whose figures cannot be trusted, and by how much.
     *
     * The share is of *editing* time specifically, because that is the
     * denominator the throughput screen divides by. Someone whose flags are all
     * on shoot days has perfectly usable editing hours.
     *
     * @param  Collection<int, array<string, mixed>>  $flags
     * @return array<int, array{minutes: int, share: float}>  keyed by user id
     */
    public static function editingImpactByUser(Collection $flags, Carbon $from, Carbon $to): array
    {
        $editing = TimesheetEntry::where('task_type', TimesheetEntry::TASK_EDITING)
            ->counted()
            ->where('worked_on', '>=', $from->toDateString())
            ->where('worked_on', '<', $to->copy()->addDay()->toDateString())
            ->get(['user_id', 'minutes'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('minutes'));

        $suspect = $flags
            /*
             * A day-total flag counts the entries beneath it, so counting both
             * would double what it claims is affected. Then the survivors are
             * reduced to one per entry: a single row can be flagged twice --
             * a 24-hour entry that also overlaps its neighbour is two problems
             * and one lot of minutes -- and summing both put shares above 100%.
             */
            ->where('kind', '!=', 'impossible_day')
            ->where('task_type', TimesheetEntry::TASK_EDITING)
            ->unique('entry_id')
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('minutes'));

        $impact = [];

        foreach ($suspect as $userId => $minutes) {
            $total = $editing->get($userId, 0);

            $impact[$userId] = [
                'minutes' => $minutes,
                'share' => $total > 0 ? $minutes / $total : 0.0,
            ];
        }

        return $impact;
    }

    /**
     * How many things this person has to go and fix, over a window.
     *
     * For the nudge on their own dashboard. Counts flags rather than entries,
     * because that is what the list they are being sent to will show them --
     * a number that does not match the list it links to is worse than none.
     */
    public static function fixableCountFor(User $user, Carbon $from, Carbon $to): int
    {
        return self::between($from, $to, $user)->count();
    }

    /**
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private static function impossibleDays(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (TimesheetEntry $e) => $e->user_id.'|'.$e->worked_on->toDateString())
            ->filter(fn (Collection $rows) => $rows->sum('minutes') > self::IMPOSSIBLE_DAY_MINUTES)
            /*
             * A day made of one entry is already reported as that entry --
             * anything over 16 hours in a single row is caught by the full-day
             * or long-entry check. Saying it twice makes the list look worse
             * than the timesheet is, and gives somebody two things to fix where
             * there is one.
             */
            ->filter(fn (Collection $rows) => $rows->count() > 1)
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $minutes = (int) $rows->sum('minutes');

                return [
                    'kind' => 'impossible_day',
                    'entry_id' => null,
                    'severity' => self::SEVERITY_HIGH,
                    'user_id' => $first->user_id,
                    'person' => $first->user?->name ?? 'Unknown',
                    'date' => $first->worked_on->toDateString(),
                    'minutes' => $minutes,
                    'task_type' => $rows->sortByDesc('minutes')->first()->task_type,
                    'title' => TimesheetEntry::formatMinutes($minutes).' logged in one day',
                    'detail' => $rows->count().' '.str('entry')->plural($rows->count())
                        .' totalling more than a day can hold. Almost always a date range typed into a duration box.',
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private static function fullDayEntries(Collection $entries): Collection
    {
        return $entries
            ->where('minutes', self::FULL_DAY_MINUTES)
            ->map(fn (TimesheetEntry $e) => self::flag($e, self::SEVERITY_HIGH, 'full_day',
                'Exactly 24 hours',
                'No work pattern produces exactly a full day. This is a job\'s calendar span, not time worked.'))
            ->values();
    }

    /**
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private static function longEntries(Collection $entries): Collection
    {
        return $entries
            ->where('minutes', '>=', self::LONG_ENTRY_MINUTES)
            ->where('minutes', '!=', self::FULL_DAY_MINUTES)
            ->map(fn (TimesheetEntry $e) => self::flag($e, self::SEVERITY_MEDIUM, 'long_entry',
                TimesheetEntry::formatMinutes($e->minutes).' in one entry',
                'Long for a single sitting. A shoot day can genuinely run this long, so worth a look rather than a correction.'))
            ->values();
    }

    /**
     * Two entries claiming the same hour.
     *
     * Only where both carry times -- an entry with a duration and no clock
     * times makes no claim about *when*, so it cannot contradict anything.
     *
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private static function overlapping(Collection $entries): Collection
    {
        $flags = collect();

        $timed = $entries->filter(fn (TimesheetEntry $e) => $e->started_at && $e->ended_at);

        foreach ($timed->groupBy(fn (TimesheetEntry $e) => $e->user_id.'|'.$e->worked_on->toDateString()) as $rows) {
            $sorted = $rows->sortBy('started_at')->values();

            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];

                // Compared as HH:MM strings, which sort correctly. An entry
                // running past midnight ends "before" it starts and is skipped
                // rather than reported as a false overlap.
                if ($previous->ended_at <= $previous->started_at) {
                    continue;
                }

                if ($current->started_at < $previous->ended_at) {
                    $flags->push(self::flag($current, self::SEVERITY_MEDIUM, 'overlap',
                        'Overlaps another entry',
                        'Starts at '.substr($current->started_at, 0, 5).', while "'.$previous->task
                        .'" runs until '.substr($previous->ended_at, 0, 5).'. The same hour is counted twice.'));
                }
            }
        }

        return $flags->values();
    }

    /**
     * Work with no client on it.
     *
     * The softest flag, and deliberately included: it is not wrong, but it is
     * hours that cannot be billed, attributed or explained later, and it is
     * usually the answer to "where did the month go".
     *
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private static function unattributed(Collection $entries): Collection
    {
        return $entries
            ->filter(fn (TimesheetEntry $e) => blank($e->venture) && $e->minutes > 0)
            ->groupBy('user_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $minutes = (int) $rows->sum('minutes');

                return [
                    'kind' => 'unattributed',
                    'entry_id' => null,
                    'severity' => self::SEVERITY_LOW,
                    'user_id' => $first->user_id,
                    'person' => $first->user?->name ?? 'Unknown',
                    'date' => null,
                    'minutes' => $minutes,
                    'task_type' => null,
                    'title' => TimesheetEntry::formatMinutes($minutes).' with no client',
                    'detail' => $rows->count().' '.str('entry')->plural($rows->count())
                        .' carry no client, so those hours cannot be billed or attributed to any job.',
                ];
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private static function flag(TimesheetEntry $entry, string $severity, string $kind, string $title, string $detail): array
    {
        return [
            'kind' => $kind,
            'severity' => $severity,
            // The row this is about, so two flags on one entry can be counted
            // once when it matters and listed separately when it does not.
            'entry_id' => $entry->id,
            'user_id' => $entry->user_id,
            'person' => $entry->user?->name ?? 'Unknown',
            'date' => $entry->worked_on->toDateString(),
            'minutes' => (int) $entry->minutes,
            'task_type' => $entry->task_type,
            'task' => $entry->task,
            'title' => $title,
            'detail' => $detail,
        ];
    }
}
