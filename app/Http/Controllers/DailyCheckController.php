<?php

namespace App\Http\Controllers;

use App\Models\DailyCheck;
use Illuminate\View\View;

/**
 * The teams' end-of-day checklists, newest first -- one card per team per
 * day, each line ticked or crossed as sent, with the note that came with a
 * cross. Admin-only, like the push that announces a crossed-off line.
 */
class DailyCheckController extends Controller
{
    public function index(): View
    {
        $checks = DailyCheck::query()
            ->with('reportedBy')
            ->whereDate('date', '>=', today()->subDays(30))
            ->orderByDesc('date')
            ->orderBy('team')
            ->get();

        return view('daily-checks.index', [
            'days' => $checks->groupBy(fn (DailyCheck $c) => $c->date->toDateString()),
            'pending' => $checks->filter(fn (DailyCheck $c) => $c->missed() !== [])->count(),
        ]);
    }
}
