<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Support\EditorBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Editor Updates: one day, person by person -- every to-do on their board
 * and how it moved that day (added, started, corrections, on hold, moved to
 * the next day, done, posted),
 * read from the to-do history that My Editing and My To-dos already write.
 * Admin-only. Replaces the WhatsApp "EOD CHECK" page: nobody has to report
 * anything, the moves are the report.
 */
class EditorUpdatesController extends Controller
{
    public function index(Request $request): View
    {
        $day = $this->day($request);
        [$people, $todos] = $this->people($day);

        return view('editor-updates.index', [
            'day' => $day,
            'review' => $this->queue(false),
            'ready' => $this->queue(true),
            'people' => $people,
            'timeline' => $this->timeline($day, $people, $todos),
            'totals' => $this->totals($people),
        ]);
    }

    /**
     * The timeline as JSON, for the page to poll while it is open -- the same
     * thing the "Editor Updates" live pane in Claude Code reads, drawn as lanes.
     */
    public function live(Request $request): JsonResponse
    {
        $day = $this->day($request);
        [$people, $todos] = $this->people($day);

        return response()->json($this->timeline($day, $people, $todos));
    }

    private function day(Request $request): Carbon
    {
        return rescue(fn () => Carbon::parse($request->query('date', today()->toDateString()))->startOfDay(), today(), false);
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, Todo>} */
    private function people(Carbon $day): array
    {
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

        return [$people, $todos];
    }

    /**
     * Now, not the day shown: finished videos waiting in review (ready =
     * false), or Video ready and waiting to be posted (ready = true, by
     * publishing time, so the late ones lead).
     *
     * @return Collection<int, Todo>
     */
    private function queue(bool $ready): Collection
    {
        return Todo::query()
            ->whereNull('parent_id')
            ->where('status', Todo::STATUS_COMPLETED)
            ->whereNull('posted_at')
            ->when($ready,
                fn ($q) => $q->whereNotNull('ready_at')->orderBy('publish_at'),
                fn ($q) => $q->whereNull('ready_at')->whereDate('closed_on', '>=', today()->subDays(14))->orderBy('closed_on')->orderBy('updated_at'))
            ->with(['user', 'contentItem', 'corrections'])
            ->get();
    }

    /** @return array<string, int> */
    private function totals(Collection $people): array
    {
        return [
            'added' => $people->sum('stats.added'),
            'done' => $people->sum('stats.done'),
            'posted' => $people->sum('stats.posted'),
            'fixes' => $people->sum('stats.fixes'),
        ];
    }

    /**
     * One lane per person across the day: blocks of time Working (and On
     * hold) on each video, a marker for every move, and where they are now --
     * what the animated timeline draws.
     *
     * @return array<string, mixed>
     */
    private function timeline(Carbon $day, Collection $people, Collection $todos): array
    {
        $isToday = $day->isToday();
        $dayEnd = $day->copy()->endOfDay();
        $until = $isToday ? now() : $dayEnd;
        $ms = fn (Carbon $c) => $c->getTimestampMs();

        $lanes = $people->map(function (array $p) use ($todos, $day, $until, $isToday, $ms) {
            $mine = $todos->where('user_id', $p['user']->id);
            $blocks = [];
            foreach ($mine as $t) {
                $from = null;
                $type = null;
                $close = function (Carbon $to) use (&$blocks, &$from, &$type, $t, $day, $until, $ms) {
                    if ($from !== null) {
                        $a = $from->max($day);
                        $b = $to->min($until);
                        if ($b->gt($a)) {
                            $blocks[] = ['id' => $t->id.'-'.$ms($a), 'type' => $type, 'title' => $t->title, 'client' => $t->venture,
                                'from' => $ms($a), 'to' => $ms($b), 'open' => $to->gte($until)];
                        }
                    }
                    $from = null;
                };
                foreach ($t->updates as $u) {
                    if (! in_array($u->action, [TodoUpdate::STATUS, TodoUpdate::CREATED], true) || ! $u->to_status) {
                        continue;
                    }
                    $close($u->created_at);
                    if (in_array($u->to_status, [Todo::STATUS_STARTED, Todo::STATUS_BLOCKED], true)) {
                        $from = $u->created_at;
                        $type = $u->to_status === Todo::STATUS_STARTED ? 'work' : 'hold';
                    }
                }
                $close($until);
            }

            $marks = $p['moved']->flatMap(fn (array $v) => collect($v['events'])->map(fn (array $e) => [
                'id' => $e['order'].'-'.$e['type'],
                'at' => $ms($e['at']),
                'time' => $e['at']->format('g:i A'),
                'type' => $e['type'],
                'label' => $e['label'],
                'detail' => $e['detail'],
                'title' => $v['title'],
                'by' => $e['by'],
            ]))->sortBy('at')->values();

            $working = $isToday ? $mine->firstWhere('status', Todo::STATUS_STARTED) : null;
            $holding = $isToday && ! $working ? $mine->firstWhere('status', Todo::STATUS_BLOCKED) : null;
            $last = $marks->last();
            $state = match (true) {
                $working !== null => 'working',
                $holding !== null => 'hold',
                $last && $last['type'] === 'posted' && $isToday && now()->getTimestampMs() - $last['at'] < 15 * 60_000 => 'celebrate',
                $last !== null && $isToday && now()->getTimestampMs() - $last['at'] < 20 * 60_000 => 'active',
                default => 'idle',
            };

            return [
                'id' => $p['user']->id,
                'name' => $p['user']->name,
                'initial' => mb_strtoupper(mb_substr($p['user']->name, 0, 1)),
                'hue' => crc32($p['user']->name) % 360,
                'state' => $state,
                'doing' => $working?->title ?? $holding?->title ?? ($last ? $last['label'].' · '.$last['title'] : null),
                'at' => $working || $holding ? $ms($until) : ($last['at'] ?? null),
                'minutes' => $p['minutes'],
                'stats' => $p['stats'],
                'blocks' => $blocks,
                'marks' => $marks->all(),
            ];
        })->values();

        $edges = $lanes->flatMap(fn (array $l) => array_merge(array_column($l['blocks'], 'from'), array_column($l['blocks'], 'to'), array_column($l['marks'], 'at')));
        $start = $day->copy()->setTime(9, 0);
        $end = $day->copy()->setTime(21, 0);
        if ($edges->isNotEmpty()) {
            $start = $start->min(Carbon::createFromTimestampMs($edges->min())->startOfHour());
            $end = $end->max(Carbon::createFromTimestampMs($edges->max())->addHour()->startOfHour());
        }
        if ($isToday) {
            $end = $end->max(now()->addHour()->startOfHour());
        }

        $ready = $this->queue(true);

        return [
            'date' => $day->toDateString(),
            'is_today' => $isToday,
            'now' => $ms(now()),
            'generated' => now()->format('g:i:s A'),
            'from' => $ms($start->max($day)),
            'to' => $ms($end->min($day->copy()->endOfDay())),
            'lanes' => $lanes->all(),
            'waiting' => [
                'review' => $this->queue(false)->count(),
                'ready' => $ready->count(),
                'late' => $ready->filter->isLateToPublish()->count(),
            ],
            'totals' => $this->totals($people),
            // Now, for today only: what needs the admin and each editor in a card.
            'board' => $isToday ? (fn (EditorBoard $b) => $b->board($b->moves($day)))(app(EditorBoard::class)) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function person(User $user, Collection $todos, Carbon $day, Carbon $dayEnd): array
    {
        $videos = $todos->map(fn (Todo $t) => $this->video($t, $user, $day, $dayEnd));
        $moved = $videos->filter(fn (array $v) => $v['events'] !== [])->sortBy('first_at')->values();
        $still = $videos->filter(fn (array $v) => $v['events'] === [] && (in_array($v['status'], Todo::OPEN_STATUSES, true) || in_array($v['status'], ['review', 'ready'], true)))->values();
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
                'held' => $count('hold'),
                'moved' => $events->where('type', 'moved')->where('label', '!=', 'Back to today')->count(),
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
            $u->action === TodoUpdate::DELIVERED => $this->make($u, $owner, 'posted', 'Delivered', $u->note),
            $u->action === TodoUpdate::READY => $this->make($u, $owner, 'ready', 'Video ready', $u->note),
            $u->action === TodoUpdate::UNPOSTED => $this->make($u, $owner, 'other', 'Post undone'),
            $u->action === TodoUpdate::REVIEWED => $this->make($u, $owner, 'other', 'Checked', $u->note),
            $u->action === TodoUpdate::MOVED => $u->to_on?->isSameDay($u->created_at)
                ? $this->make($u, $owner, 'moved', 'Back to today')
                : $this->make($u, $owner, 'moved', 'Moved to '.($u->to_on?->isSameDay($u->created_at->copy()->addDay()) ? 'next day' : $u->to_on?->format('D j M')), $u->note),
            $u->action !== TodoUpdate::STATUS => null,
            $u->to_status === Todo::STATUS_BLOCKED => $this->make($u, $owner, 'hold', 'On hold', $u->note),
            $u->to_status === Todo::STATUS_CANCELLED => $this->make($u, $owner, 'other', 'Removed'),
            $u->to_status === Todo::STATUS_STARTED => match ($u->from_status) {
                Todo::STATUS_COMPLETED => $this->make($u, $owner, 'started', 'Back to working', $u->note),
                Todo::STATUS_BLOCKED => $this->make($u, $owner, 'started', 'Resumed'),
                default => $this->make($u, $owner, 'started', 'Started'),
            },
            $u->to_status === Todo::STATUS_WAITING && $u->from_status === Todo::STATUS_STARTED => $this->make($u, $owner, 'other', 'Paused'),
            $u->to_status === Todo::STATUS_COMPLETED => $this->make($u, $owner, 'done', 'Done → review'),
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

    /**
     * Where the to-do stood at the end of that day (now, for today): its
     * status, except that a finished video is 'review' until it went out,
     * then 'posted' or 'delivered'.
     */
    private function statusAt(Todo $t, Carbon $dayEnd): string
    {
        $status = $this->rawStatusAt($t, $dayEnd);
        if ($status !== Todo::STATUS_COMPLETED) {
            return $status;
        }
        if ($t->posted_at && $t->posted_at->lte($dayEnd)) {
            return $t->kind === Todo::KIND_DELIVERED ? 'delivered' : 'posted';
        }

        return $t->ready_at && $t->ready_at->lte($dayEnd) ? 'ready' : 'review';
    }

    private function rawStatusAt(Todo $t, Carbon $dayEnd): string
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
