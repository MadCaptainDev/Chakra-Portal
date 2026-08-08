<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\TimesheetEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));

        $entries = TimesheetEntry::where('user_id', $request->user()->id)
            ->forMonth($month)
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (TimesheetEntry $entry) => $entry->worked_on->toDateString());

        return view('my.calendar', [
            'month' => $month,
            'weeks' => $this->weeks($month, $entries),
            'entriesByDay' => $entries,
            'totalMinutes' => $entries->flatten()
                ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
                ->sum('minutes'),
        ]);
    }

    /**
     * A month laid out as calendar weeks, padded with the leading and trailing
     * days needed to fill whole Monday-to-Sunday rows.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function weeks(Carbon $month, $entries): array
    {
        $start = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $weeks = [];
        $days = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $dayEntries = $entries->get($key, collect());

            $days[] = [
                'date' => $cursor->copy(),
                'inMonth' => $cursor->month === $month->month,
                'isToday' => $cursor->isToday(),
                'entries' => $dayEntries,
                'minutes' => $dayEntries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)->sum('minutes'),
            ];

            if (count($days) === 7) {
                $weeks[] = $days;
                $days = [];
            }

            $cursor->addDay();
        }

        return $weeks;
    }

    private function resolveMonth(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfMonth();
        }

        try {
            return Carbon::parse(strlen($value) === 7 ? $value.'-01' : $value)->startOfMonth();
        } catch (Throwable) {
            return now()->startOfMonth();
        }
    }
}
