<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * A month at a glance -- every shoot, by date. Same shape as
 * RoutineCalendarController, which this deliberately mirrors: a month-grid
 * of days, each carrying its own items, is a pattern this app already has
 * rather than one to invent twice.
 */
class ShootCalendarController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));

        $shoots = Shoot::query()
            ->with(['client', 'crew.user'])
            ->whereDate('starts_at', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('starts_at', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Shoot $shoot) => $shoot->starts_at->toDateString());

        return view('shoots.calendar', [
            'month' => $month,
            'weeks' => $this->weeks($month, $shoots),
            'shootsThisMonth' => $shoots->flatten()->count(),
        ]);
    }

    /**
     * @param  Collection<string, Collection<int, Shoot>>  $shoots
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function weeks(Carbon $month, Collection $shoots): array
    {
        $start = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $weeks = [];
        $days = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $dayShoots = $shoots->get($key, collect());

            $days[] = [
                'date' => $cursor->copy(),
                'inMonth' => $cursor->month === $month->month,
                'isToday' => $cursor->isToday(),
                'shoots' => $dayShoots,
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
