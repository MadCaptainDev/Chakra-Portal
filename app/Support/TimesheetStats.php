<?php

namespace App\Support;

use App\Models\Client;
use App\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Chart-ready aggregates from a set of timesheet entries.
 *
 * Keeps Blade free of grouping logic and stays dependency-free (no Chart.js).
 */
class TimesheetStats
{
    /**
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return array{
     *     daily: list<array{date: string, label: string, weekday: string, minutes: int, entries: int}>,
     *     ventures: list<array{label: string, minutes: int, href: ?string}>,
     *     taskTypes: list<array{label: string, key: string, minutes: int}>,
     *     maxDaily: int,
     *     maxVenture: int,
     *     maxTaskType: int,
     *     totalMinutes: int,
     *     daysWorked: int
     * }
     */
    public static function forEntries(Collection $entries, Carbon $month): array
    {
        $counted = $entries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED);

        $byDay = $counted->groupBy(fn (TimesheetEntry $e) => $e->worked_on->toDateString());

        $daily = [];
        $cursor = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $dayEntries = $byDay->get($key, collect());
            $daily[] = [
                'date' => $key,
                'label' => $cursor->format('j'),
                'weekday' => $cursor->format('D'),
                'minutes' => (int) $dayEntries->sum('minutes'),
                'entries' => $dayEntries->count(),
            ];
            $cursor->addDay();
        }

        $labelMap = self::ventureLabelMap();
        $hrefMap = self::ventureHrefMap();

        // Only real client ventures — empty / junk must not appear as a "client".
        $ventures = $counted
            ->filter(fn (TimesheetEntry $e) => filled($e->venture))
            ->groupBy(fn (TimesheetEntry $e) => (string) $e->venture)
            ->map(function (Collection $group, string $venture) use ($labelMap, $hrefMap) {
                return [
                    'label' => $labelMap[$venture] ?? $venture,
                    'minutes' => (int) $group->sum('minutes'),
                    'href' => $hrefMap[$venture] ?? null,
                ];
            })
            ->sortByDesc('minutes')
            ->values()
            ->all();

        $taskTypes = collect(TimesheetEntry::TASK_TYPES)->map(function (string $label, string $key) use ($counted) {
            $group = $counted->where('task_type', $key);

            return [
                'key' => $key,
                'label' => $label,
                'minutes' => (int) $group->sum('minutes'),
            ];
        })->values()->all();

        return [
            'daily' => $daily,
            'ventures' => $ventures,
            'taskTypes' => $taskTypes,
            'maxDaily' => max(1, (int) (collect($daily)->max('minutes') ?: 0)),
            'maxVenture' => max(1, (int) (collect($ventures)->max('minutes') ?: 0)),
            'maxTaskType' => max(1, (int) (collect($taskTypes)->max('minutes') ?: 0)),
            'totalMinutes' => (int) $counted->sum('minutes'),
            'daysWorked' => collect($daily)->where('minutes', '>', 0)->count(),
        ];
    }

    /**
     * Hours logged against a single client (matched on canonical venture).
     *
     * @return array{minutes: int, entries: int, byType: list<array{label: string, key: string, minutes: int}>}
     */
    public static function forClient(Client $client, ?Carbon $month = null): array
    {
        $canonical = TimesheetVenture::canonicalForClient($client);
        if ($canonical === null) {
            return [
                'minutes' => 0,
                'entries' => 0,
                'byType' => collect(TimesheetEntry::TASK_TYPES)->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'minutes' => 0,
                ])->values()->all(),
            ];
        }

        $query = TimesheetEntry::query()
            ->counted()
            ->where('venture', $canonical);

        if ($month) {
            $query->forMonth($month);
        }

        $entries = $query->get();

        $byType = collect(TimesheetEntry::TASK_TYPES)->map(function (string $label, string $key) use ($entries) {
            return [
                'key' => $key,
                'label' => $label,
                'minutes' => (int) $entries->where('task_type', $key)->sum('minutes'),
            ];
        })->values()->all();

        return [
            'minutes' => (int) $entries->sum('minutes'),
            'entries' => $entries->count(),
            'byType' => $byType,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function ventureOptions(): array
    {
        return TimesheetVenture::options();
    }

    /**
     * @return list<string>
     */
    public static function knownVentures(): array
    {
        return TimesheetVenture::allowedValues();
    }

    /**
     * @return array<string, string>
     */
    private static function ventureLabelMap(): array
    {
        $map = [];
        foreach (TimesheetVenture::options() as $option) {
            $map[$option['value']] = $option['label'];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function ventureHrefMap(): array
    {
        $map = [];
        foreach (Client::query()->orderBy('name')->get(['id', 'name', 'notion_venture']) as $client) {
            $canonical = TimesheetVenture::canonicalForClient($client);
            if ($canonical === null) {
                continue;
            }
            $map[$canonical] = route('clients.show', $client);
        }

        return $map;
    }
}
