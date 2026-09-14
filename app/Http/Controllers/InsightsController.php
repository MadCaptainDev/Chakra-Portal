<?php

namespace App\Http\Controllers;

use App\Services\Insights\EffortVersusRevenue;
use App\Services\Insights\NeedsAttention;
use App\Services\Insights\PaymentBehaviour;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The three questions the portal held the answers to and never asked.
 *
 * Everything on this screen is computed from rows that were already there --
 * timesheet entries, invoices, payments, shoots. Nothing new is recorded to
 * make it work. What was missing was the joining up: hours under a venture
 * name and money under a client id, sitting in the same database, never once
 * set beside each other.
 *
 * Admin-only, like the money screens it draws from. An effective hourly rate
 * per client is a number that decides whether somebody keeps their job, and
 * it is not something to hand out with a Timesheets permission.
 */
class InsightsController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->month($request);
        $wholeYear = $request->query('range') === 'year';

        [$from, $to] = $wholeYear
            ? [$month->copy()->startOfYear(), $month->copy()->endOfYear()]
            : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

        return view('insights.index', [
            'month' => $month,
            'wholeYear' => $wholeYear,
            'from' => $from,
            'to' => $to,
            'effort' => EffortVersusRevenue::between($from, $to),
            'payers' => PaymentBehaviour::all(),
            'attention' => NeedsAttention::all(),
        ]);
    }

    /**
     * Anything unparseable falls back to this month rather than erroring --
     * a hand-edited query string should not be able to break a report.
     */
    private function month(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        try {
            return $raw === '' ? now()->startOfMonth() : Carbon::parse($raw.'-01')->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }
}
