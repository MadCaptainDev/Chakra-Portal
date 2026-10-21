<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Shoot;

/**
 * The top of the admin dashboard, as designed ("Today"): the week's shoots,
 * who is editing now, what the planner has due today, and money that is
 * late. Read-only, each from the module that owns it.
 */
class TodayBoard
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        $shoots = Shoot::query()->with('client')->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereBetween('starts_at', [today(), today()->addDays(7)->endOfDay()])
            ->orderBy('starts_at')->limit(5)->get();

        $board = app(EditorBoard::class);
        $editing = $board->board($board->moves(today()));

        $due = ContentItem::query()->visible()->where('source', ContentItem::SOURCE_REEL)
            ->whereDate('published_date', today())
            ->whereNotIn('status', ContentDashboard::STATUS_GROUPS['published'])
            ->orderBy('status')->get(['id', 'title', 'status', 'venture', 'notion_url']);

        $late = Invoice::query()->with('client', 'payments')->where('status', Invoice::STATUS_UNPAID)
            ->whereDate('due_date', '<', today())->orderBy('due_date')->get();

        return [
            'shoots' => $shoots,
            'shootsToday' => $shoots->filter(fn (Shoot $s) => $s->starts_at->isToday())->count(),
            'editors' => collect($editing['editors'])->take(5),
            'flow' => $editing['flow'],
            'due' => $due,
            'late' => $late->take(4),
            'lateCount' => $late->count(),
            'lateTotal' => (float) $late->sum(fn (Invoice $i) => $i->balanceDue()),
        ];
    }
}
