<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ShootCrew;
use App\Models\User;
use App\Support\ContentDashboard;
use Illuminate\View\View;

/**
 * Who has how much on their plate, right now -- not a ranking over time
 * (see EditorOutputController for that; this is the current queue, not a
 * historical comparison). Admin only, and deliberately not module-gated,
 * same convention EditorOutputController documents: a screen that counts
 * one person's load against another's is the owner's call to show, not a
 * permission checkbox.
 */
class WorkloadController extends Controller
{
    /** A piece still "in progress" whose shoot date is this many days past counts overdue. */
    private const OVERDUE_AFTER_DAYS = 7;

    public function index(): View
    {
        $staff = User::staff()->orderBy('name')->get();

        $activeShoots = ShootCrew::query()
            ->whereHas('shoot', fn ($q) => $q->upcoming())
            ->selectRaw('user_id, count(*) as c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        $items = ContentItem::query()
            ->whereNotNull('assigned_user_id')
            ->select(['assigned_user_id', 'status', 'shoot_date', 'notion_shoot_id'])
            ->with('notionShoot:id,shoot_date')
            ->get();

        $inProgressStatuses = array_merge(
            ContentDashboard::STATUS_GROUPS['scheduled'],
            ContentDashboard::STATUS_GROUPS['in_progress'],
        );

        $overdueCutoff = now()->subDays(self::OVERDUE_AFTER_DAYS)->startOfDay();

        $rows = $staff->map(function (User $user) use ($items, $inProgressStatuses, $overdueCutoff, $activeShoots) {
            $mine = $items->where('assigned_user_id', $user->id);

            $active = $mine->whereIn('status', $inProgressStatuses);

            $overdue = $active->filter(function (ContentItem $item) use ($overdueCutoff) {
                $date = $item->notionShoot?->shoot_date ?? $item->shoot_date;

                return $date !== null && $date->lt($overdueCutoff);
            });

            $delivered = $mine->where('status', 'Published')->count();

            return [
                'user' => $user,
                'active_shoots' => (int) ($activeShoots[$user->id] ?? 0),
                'active_content' => $active->count(),
                'overdue' => $overdue->count(),
                'delivered' => $delivered,
            ];
        })->sortByDesc(fn (array $row) => $row['active_content'] + $row['active_shoots'])
            ->values();

        return view('workload.index', [
            'rows' => $rows,
            'overdueAfterDays' => self::OVERDUE_AFTER_DAYS,
        ]);
    }
}
