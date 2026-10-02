<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\TimesheetEntry;
use App\Models\Todo;
use App\Models\User;
use App\Services\InboxDesk;
use App\Services\Notion\NotionSyncRunner;
use App\Support\ContentDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Throwable;

/**
 * Today, in one small JSON document, for the phone home-screen widget
 * (resources/widget/chakra-widget.js, run by the Scriptable app on iOS).
 *
 * Every section is only present when the person could see the same thing in
 * the portal: their own hours if they log work, the team's if they are an
 * admin, every shoot with shoots.view or only the ones they are crew on
 * without it, and the Reel Planner board -- an admin-only screen -- for
 * admins alone.
 */
class WidgetController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = now()->startOfDay();

        $data = [
            'date' => $today->toDateString(),
            'date_label' => $today->format('D, j M'),
            'name' => strtok((string) $user->name, ' ') ?: $user->name,
            'generated_at' => now()->format('g:i A'),
            'portal_url' => url(route($user->homeRoute(), [], false)),
        ];

        if ($user->logsWork()) {
            $data['hours'] = $this->ownHours($user, $today);
        }

        if ($user->isAdmin()) {
            $data['team_hours'] = $this->teamHours($today);
        }

        /*
         * ?date= is the widget's ‹ › arrows: shoots and the Reel Planner for
         * another day. Hours and to-dos stay today's -- "hours logged on a
         * day that has not happened" means nothing. Kept within three months
         * either way; anything else, or anything unparseable, is today.
         */
        $day = $this->requestedDay($request) ?? $today;
        $data['day'] = [
            'date' => $day->toDateString(),
            'label' => $day->format('D, j M'),
            'is_today' => $day->isSameDay($today),
            'prev' => $day->copy()->subDay()->toDateString(),
            'next' => $day->copy()->addDay()->toDateString(),
        ];

        $data['shoots'] = $this->shoots($user, $day);
        $data['todos'] = $this->todos($user, $today);

        // Instagram DMs/comments -- for whoever checks them, and for admins
        // watching it happen. Always today's: it is a live tally, not a plan.
        $desk = app(InboxDesk::class);
        if ($desk->hasAccess($user)) {
            $data['inbox'] = $desk->widget($user);
        }

        if ($user->isAdmin()) {
            $data['reels'] = $this->reels($request->boolean('fresh'), $day);
        }

        return response()->json($data);
    }

    private function ownHours(User $user, $today): array
    {
        $base = TimesheetEntry::query()->counted()->where('user_id', $user->id);

        $todayMinutes = (int) (clone $base)->whereDate('worked_on', $today)->sum('minutes');
        $weekMinutes = (int) (clone $base)
            ->where('worked_on', '>=', $today->copy()->startOfWeek()->toDateString())
            ->where('worked_on', '<=', $today->toDateString())
            ->sum('minutes');

        return [
            'today_minutes' => $todayMinutes,
            'today_label' => $this->hm($todayMinutes),
            'week_minutes' => $weekMinutes,
            'week_label' => $this->hm($weekMinutes),
            'entries' => (clone $base)->whereDate('worked_on', $today)->count(),
            'url' => route('my.timesheet'),
        ];
    }

    private function teamHours($today): array
    {
        $rows = TimesheetEntry::query()->counted()->whereDate('worked_on', $today);

        $minutes = (int) (clone $rows)->sum('minutes');

        return [
            'today_minutes' => $minutes,
            'today_label' => $this->hm($minutes),
            'people' => (clone $rows)->distinct()->count('user_id'),
        ];
    }

    private function shoots(User $user, $today): array
    {
        $query = Shoot::query()
            ->with('client:id,name')
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->where('starts_at', '>=', $today)
            ->where('starts_at', '<', $today->copy()->addDay())
            ->ordered();

        $seesAll = $user->can('shoots.view');

        if (! $seesAll) {
            $query->whereHas('crew', fn ($q) => $q->where('user_id', $user->id));
        }

        $shoots = $query->limit(10)->get();

        return [
            'count' => $shoots->count(),
            'items' => $shoots->map(fn (Shoot $shoot) => [
                'title' => $shoot->title ?: ($shoot->client?->name ?? 'Shoot'),
                'client' => $shoot->client?->name,
                // Shoots imported from Notion carry a date and no time, which
                // lands as midnight -- "12:00 AM" would be a wrong call time.
                'time' => $shoot->starts_at && $shoot->starts_at->format('H:i') !== '00:00'
                    ? $shoot->starts_at->format('g:i A')
                    : null,
                'location' => $shoot->location,
                'status' => $shoot->isInProgress() ? 'live' : $shoot->status,
            ])->values()->all(),
            'url' => $seesAll ? route('shoots.index') : route('my.dashboard'),
        ];
    }

    private function todos(User $user, $today): array
    {
        $open = Todo::query()
            ->where('user_id', $user->id)
            ->open()
            ->onDay($today)
            ->orderByRaw('due_on is null')
            ->orderBy('due_on');

        $count = (clone $open)->count();

        return [
            'count' => $count,
            'overdue' => (clone $open)->whereNotNull('due_on')->where('due_on', '<', $today->toDateString())->count(),
            'items' => (clone $open)->limit(3)->pluck('title')->all(),
            'url' => $user->logsWork() ? route('my.todos') : null,
        ];
    }

    private function requestedDay(Request $request): ?Carbon
    {
        $raw = $request->query('date');

        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (Throwable) {
            return null;
        }

        if (! $day || $day->toDateString() !== $raw || abs($day->diffInDays(now()->startOfDay())) > 92) {
            return null;
        }

        return $day;
    }

    private function reels(bool $fresh, Carbon $day): array
    {
        /*
         * ?fresh=1 is the Refresh button on the full list opened from the
         * widget -- a person in the app, waiting and happy to, not a widget
         * refresh with seconds to spare. So that one syncs first, then reads.
         */
        if ($fresh) {
            try {
                NotionSyncRunner::syncIfOlderThan(60);
            } catch (Throwable $e) {
                report($e);
            }
        }

        /*
         * Served from the cache straight away, and the dashboard's own
         * freshness rule (sync when past fifteen minutes, never two at once)
         * runs after the response has gone. A sync takes up to twenty
         * seconds, and iOS gives a widget refresh far less than that -- so
         * this refresh shows the board as of the last sync and the next one,
         * minutes later, shows the result of this one.
         */
        app()->terminating(function () {
            try {
                NotionSyncRunner::ensureFresh();
            } catch (Throwable $e) {
                report($e);
            }
        });

        $board = ContentDashboard::todayReelBoard($day);
        $board['url'] = route('content-dashboard.index');

        return $board;
    }

    private function hm(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }
}
