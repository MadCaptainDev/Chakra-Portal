<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\EmployeePoint;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\Todo;
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

        $decisions = TimesheetDay::decisionsFor([$user->id], $month)
            ->keyBy(fn (TimesheetDay $day) => $day->worked_on->toDateString());

        $workedDays = $counted->pluck('worked_on')->map->toDateString()->unique();

        /*
         * Today's to-dos, as counts only. The board owns the list; this is the
         * line that gets somebody to open it, which a feature nobody is
         * reminded of does not get.
         */
        $todosToday = Todo::where('user_id', $user->id)->onDay(today())->open()->get();

        /*
         * No per-type or per-day breakdown here on purpose. The timesheet screen
         * owns those charts; the dashboard links to it rather than repeating
         * them, which TimesheetTest pins.
         */
        return view('my.dashboard', [
            'month' => $month,
            'employee' => $user->employeeRecord,
            'totalMinutes' => $counted->sum('minutes'),
            // Days still waiting on a manager, and days sent back. A rejection
            // outranks a wait: it is a specific thing to go and fix, where
            // waiting is somebody else's turn.
            'pendingCount' => $workedDays->reject(fn (string $date) => $decisions->has($date))->count(),
            'rejectedCount' => $decisions->filter(fn (TimesheetDay $day) => $day->isRejected())->count(),
            'daysLogged' => $workedDays->count(),
            'todosToday' => $todosToday->count(),
            'todosStarted' => $todosToday->where('status', Todo::STATUS_STARTED)->count(),
            'todosOverdue' => $todosToday->filter(fn (Todo $todo) => $todo->isOverdueOn(today()))->count(),
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
