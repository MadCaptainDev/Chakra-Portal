<?php

namespace App\Http\Controllers;

use App\Support\EditorThroughput;
use App\Support\TimesheetAnomalies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * What the editors produced, what it cost in hours, and what in the timesheet
 * cannot be believed.
 *
 * Admin only, and not module-gated. This is the screen that compares people to
 * one another; a permission checkbox that let a colleague see their own ranking
 * against the person at the next desk would change the room, and that is the
 * owner's decision to make deliberately rather than by ticking a box.
 */
class EditorOutputController extends Controller
{
    /** Windows offered, in months back from the end of this month. */
    private const PERIODS = [3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'];

    private const DEFAULT_PERIOD = 6;

    public function index(Request $request): View
    {
        $months = (int) $request->query('months', self::DEFAULT_PERIOD);

        if (! array_key_exists($months, self::PERIODS)) {
            $months = self::DEFAULT_PERIOD;
        }

        $to = today()->endOfMonth();
        $from = today()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $throughput = EditorThroughput::between($from, $to);
        $flags = TimesheetAnomalies::between($from, $to);

        return view('editors.index', [
            'periods' => self::PERIODS,
            'months' => $months,
            'from' => $from,
            'to' => $to,
            'throughput' => $throughput,
            'flags' => $flags,
            'flagsBySeverity' => $flags->groupBy('severity'),
            /*
             * Which people's hours the flags actually eat into. Passed
             * alongside the figures rather than left in the flags section, so a
             * rate computed from suspect hours is marked where it is read.
             */
            'impact' => TimesheetAnomalies::editingImpactByUser($flags, $from, $to),
        ]);
    }
}
