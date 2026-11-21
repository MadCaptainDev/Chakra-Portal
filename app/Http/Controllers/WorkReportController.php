<?php

namespace App\Http\Controllers;

use App\Support\WorkReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Work Report: a day, a week or a month of the studio's work in one
 * page -- hours, videos, cancellations, shoots. See App\Support\WorkReport.
 */
class WorkReportController extends Controller
{
    public function index(Request $request): View
    {
        $report = WorkReport::for((string) $request->query('period', 'week'), $request->query('date'));

        return view('work-report.index', ['r' => $report]);
    }
}
