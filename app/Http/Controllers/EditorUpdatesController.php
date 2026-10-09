<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Editor Updates: one day, person by person -- every to-do on their board
 * and how it moved that day (added, started, corrections, done, posted),
 * read from the to-do history that My Editing and My To-dos already write.
 * Admin-only. Replaces the WhatsApp "EOD CHECK" page: nobody has to report
 * anything, the moves are the report.
 */
class EditorUpdatesController extends Controller
{
    public function index(Request $request): View
    {
        $day = rescue(fn () => Carbon::parse($request->query('date', today()->toDateString()))->startOfDay(), today(), false);
        $dayEnd = $day->copy()->endOfDay();

        // Every video touched that day, plus everything still on a board.
        $touched = TodoUpdate::query()
            ->whereBetween('created_at', [$day, $dayEnd])
            ->with('todo:id,parent_id')
            ->get()
            ->map(fn (TodoUpdate $u) => $u->todo?->parent_id ?? $u->todo_id)
            ->filter();
        $onBoard = Todo::query()->whereNull('parent_id')->onDay($day)
            ->where('status', '!=', Todo::STATUS_CANCELLED)->pluck('id');

        $todos = Todo::query()
            ->whereNull('parent_id')
            ->whereIn('id', $touched->merge($onBoard)->unique()->values())
            ->with(['user', 'contentItem', 'updates.user', 'corrections.updates.user'])
            ->get();

        $people = $todos
            ->filter(fn (Todo $t) => $t->user !== null)
            ->groupBy('user_id')
            ->map(fn (Collection $mine) => $this->person($mine->first()->user, $mine, $day, $dayEnd))
            ->sortByDesc(fn (array $p) => [$p['moves'], $p['open']])
            ->values();

        return view('editor-updates.index', [
            'day' => $day,
            'people' => $people,
            'totals' => [
                'added' => $people->sum('stats.added'),
                'done' => $people->sum('stats.done'),
                'posted' => $people->sum('stats.posted'),
                'fixes' => $people->sum('stats.fixes'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function person(User $user, Collection $todos, Carbon $day, Carbon $dayEnd): array
    {
        $videos = $todos->map(fn (Todo $t) => $this->video($t, $user, $day, $dayEnd));
        $moved = $videos->filter(fn (array $v) => $v['events'] !== [])->sortBy('first_at')->values();
        $still = $videos->filter(fn (array $v) => $v['events'] === [] && in_array($v['status'], Todo::OPEN_STATUSES, true))->values();
        $events = $moved->flatMap(fn (array $v) => $v['events']);
        $count = fn (string $type) => $events->where('type', $type)->count();

        return [
            'user' => $user,
            'moved' => $moved,
            'still' => $still,
            'moves' => $events->count(),
            'open' => $still->count(),
            'first' => $events->min('at'),
            'last' => $events->max('at'),
            'minutes' => $videos->sum('minutes'),
            'stats' => [
                'added' => $count('added'),
                'started' => $count('started'),
                'fixes' => $count('fix'),
                'done' => $count('done'),
                'posted' => $count('posted'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function video(Todo $t, User $owner, Carbon $day, Carbon $dayEnd): array
    {
        $inDay = fn (TodoUpdate $u) => $u->created_at->between($day, $dayEnd);
        $events = [];

        foreach ($t->updates->filter($inDay) as $u) {
            if ($event = $this->event($u, $owner)) {
                $events[] = $event;
            }
        }
        foreach ($t->corrections as $fix) {
            foreach ($fix->updates->filter($inDay) as $u) {
                if ($u->action === TodoUpdate::CREATED) {
                    $events[] = $this->make($u, $owner, 'fix', 'Correction', $fix->title);
                } elseif ($u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_COMPLETED) {
                    $events[] = $this->make($u, $owner, 'fixed', 'Fixed', $fix->title);
                }
            }
        }
        usort($events, fn ($a, $b) => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        $publish = $t->contentItem?->published_date;

        return [
            'id' => $t->id,
            'title' => $t->title,
            'client' => $t->venture,
            'kind' => $t->kind ? (Todo::KINDS[$t->kind] ?? null) : null,
            'publishes' => $publish ? ($publish->isSameDay($day) ? 'publishes that day' : 'publishes '.$publish->format('D j M')) : null,
            'status' => $this->statusAt($t, $dayEnd),
            'carried' => $t->created_at->lt($day) ? $t->created_at : null,
            'minutes' => $this->minutesOn($t, $day, $dayEnd),
            'open_fixes' => $t->corrections->filter(fn (Todo $c) => $c->isOpen())->count(),
            'events' => $events,
            'first_at' => $events[0]['at'] ?? null,
        ];
    }

    /** One history row as a step on the timeline, or null for noise. */
    private function event(TodoUpdate $u, User $owner): ?array
    {
        return match (true) {
            $u->action === TodoUpdate::CREATED => $this->make($u, $owner, 'added', 'Added'),
            $u->action === TodoUpdate::POSTED => $this->make($u, $owner, 'posted', 'Posted', $u->note),
            $u->action === TodoUpdate::UNPOSTED => $this->make($u, $owner, 'other', 'Post undone'),
            $u->action === TodoUpdate::REVIEWED => $this->make($u, $owner, 'other', 'Checked', $u->note),
            $u->action === TodoUpdate::MOVED => $this->make($u, $owner, 'other', 'Moved to '.$u->to_on?->format('j M')),
            $u->action !== TodoUpdate::STATUS => null,
            $u->to_status === Todo::STATUS_STARTED => $u->from_status === Todo::STATUS_COMPLETED
                ? $this->make($u, $owner, 'started', 'Back to working', $u->note)
                : $this->make($u, $owner, 'started', 'Started'),
            $u->to_status === Todo::STATUS_COMPLETED => $this->make($u, $owner, 'done', 'Done'),
            default => $this->make($u, $owner, 'other', Todo::STATUSES[$u->to_status] ?? ucfirst((string) $u->to_status), $u->note),
        };
    }

    /** @return array<string, mixed> */
    private function make(TodoUpdate $u, User $owner, string $type, string $label, ?string $detail = null): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'detail' => filled($detail) ? $detail : null,
            'at' => $u->created_at,
            'order' => $u->id,
            // Someone else moving your card (a manager, an admin) is worth saying.
            'by' => $u->user_id && $u->user_id !== $owner->id ? $u->user?->name : null,
        ];
    }

    /** Where the to-do stood at the end of that day (now, for today). */
    private function statusAt(Todo $t, Carbon $dayEnd): string
    {
        if ($dayEnd->isFuture()) {
            return $t->status;
        }
        $last = $t->updates
            ->filter(fn (TodoUpdate $u) => in_array($u->action, [TodoUpdate::CREATED, TodoUpdate::STATUS], true) && $u->to_status && $u->created_at->lte($dayEnd))
            ->last();

        return $last?->to_status ?? $t->status;
    }

    /** Minutes spent Working on it within that day. */
    private function minutesOn(Todo $t, Carbon $day, Carbon $dayEnd): int
    {
        $total = 0;
        $since = null;
        $clip = function (Carbon $from, Carbon $to) use ($day, $dayEnd): int {
            $a = $from->max($day);
            $b = $to->min($dayEnd->isFuture() ? now() : $dayEnd);

            return $b->gt($a) ? (int) $a->diffInMinutes($b) : 0;
        };
        foreach ($t->updates as $u) {
            if (! in_array($u->action, [TodoUpdate::STATUS, TodoUpdate::CREATED], true)) {
                continue;
            }
            if ($u->to_status === Todo::STATUS_STARTED && $since === null) {
                $since = $u->created_at;
            } elseif ($u->to_status !== Todo::STATUS_STARTED && $since !== null) {
                $total += $clip($since, $u->created_at);
                $since = null;
            }
        }
        if ($since !== null && $t->status === Todo::STATUS_STARTED) {
            $total += $clip($since, now());
        }

        return $total;
    }
}
