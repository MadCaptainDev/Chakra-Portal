<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\EmployeePoint;
use App\Models\TimesheetEntry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $month = now()->startOfMonth();

        $entries = TimesheetEntry::where('user_id', $user->id)->forMonth($month)->get();
        $counted = $entries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED);

        /*
         * No per-type or per-day breakdown here on purpose. The timesheet screen
         * owns those charts; the dashboard links to it rather than repeating
         * them, which TimesheetTest pins.
         */
        return view('my.dashboard', [
            'month' => $month,
            'employee' => $user->employeeRecord,
            'totalMinutes' => $counted->sum('minutes'),
            'pendingCount' => $entries->where('status', TimesheetEntry::STATUS_PENDING)->count(),
            // Questions from a manager outrank pending: someone is waiting on
            // a reply, and answering is a specific action rather than a chore.
            'queriedCount' => TimesheetEntry::where('user_id', $user->id)
                ->whereNotNull('review_note')
                ->count(),
            'daysLogged' => $counted->pluck('worked_on')->map->toDateString()->unique()->count(),
            'point' => EmployeePoint::where('user_id', $user->id)
                ->whereDate('period', $month->toDateString())
                ->first(),
            'recentPoints' => EmployeePoint::where('user_id', $user->id)
                ->orderByDesc('period')
                ->take(6)
                ->get(),
            'announcements' => Announcement::active()->latest()->take(5)->get(),
            'recentEntries' => TimesheetEntry::where('user_id', $user->id)
                ->orderByDesc('worked_on')
                ->orderByRaw('started_at IS NULL')
                ->orderBy('started_at')
                ->take(5)
                ->get(),
        ]);
    }
}
