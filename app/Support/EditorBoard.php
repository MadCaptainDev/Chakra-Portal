<?php

namespace App\Support;

use App\Models\Todo;
use App\Models\TodoUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The editing floor as the admin reads it, shared by the Editor Updates page
 * (EditorUpdatesController, live every 15 seconds) and the JSON feed
 * (editor-updates:feed): a day's to-do moves, and from them the board --
 * what needs the admin, each editor in a line, the day's flow, recent moves.
 */
class EditorBoard
{
    /** @var list<int>|null Only these people (a manager's team); null is everyone. */
    private ?array $only = null;

    /** A manager sees their own team; an admin passes null for everyone. */
    public function only(?array $userIds): static
    {
        $this->only = $userIds === null ? null : array_values(array_map('intval', $userIds));

        return $this;
    }

    private function scoped($query, string $column = 'user_id')
    {
        return $this->only === null ? $query : $query->whereIn($column, $this->only);
    }

    /**
     * A day's moves, newest first, each as the screens word it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function moves(Carbon $day): Collection
    {
        return TodoUpdate::query()
            ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
            ->with(['todo.parent', 'todo.user', 'user'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TodoUpdate $u) => $this->move($u))
            ->filter()
            ->filter(fn (array $m) => $this->only === null || in_array((int) ($m['person_id'] ?? 0), $this->only, true))
            ->values();
    }

    /**
     * The admin's view of the editing floor, in the order it is read: what
     * needs them, then each editor in one line, then the day's flow, then a
     * short de-duplicated list of recent moves.
     *
     * @return array<string, mixed>
     */
    public function board(Collection $moves): array
    {
        $now = now();
        $ago = fn (?Carbon $at) => $at ? (int) $at->diffInMinutes($now) : null;

        $open = $this->scoped(Todo::query()->whereNull('parent_id'))
            ->whereIn('status', [Todo::STATUS_STARTED, Todo::STATUS_BLOCKED])
            ->with(['user', 'updates'])->get();
        $review = $this->scoped(Todo::query()->whereNull('parent_id'))->where('status', Todo::STATUS_COMPLETED)
            ->whereNull('posted_at')->whereNull('ready_at')->whereDate('closed_on', '>=', today()->subDays(14))
            ->with(['user', 'updates'])->get()
            ->map(fn (Todo $t) => ['title' => $t->title, 'person' => $t->user?->name,
                'waiting' => $ago($t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_COMPLETED)?->created_at ?? $t->updated_at)])
            ->sortByDesc('waiting')->values();
        $ready = $this->scoped(Todo::query()->whereNull('parent_id'))->where('status', Todo::STATUS_COMPLETED)
            ->whereNotNull('ready_at')->whereNull('posted_at')->with('user')->orderBy('publish_at')->get();

        // When this video last went into its current status, and the note it carried.
        $since = fn (Todo $t) => $t->updates->last(fn ($u) => $u->to_status === $t->status);

        // ——— Needs you ———
        $needs = [];
        foreach ($ready->filter(fn (Todo $t) => $t->publish_at?->lt($now)) as $t) {
            $needs[] = ['kind' => 'late', 'text' => $t->title, 'person' => $t->user?->name,
                'detail' => 'was due '.$t->publish_at->format('g:i A').' · '.$this->span($ago($t->publish_at)).' late'];
        }
        if ($review->isNotEmpty()) {
            $oldest = $review->first();
            $needs[] = ['kind' => 'review', 'count' => $review->count(), 'text' => $oldest['title'], 'person' => $oldest['person'],
                'detail' => $review->count().' waiting for review · oldest '.$this->span($oldest['waiting'])];
        }
        foreach ($open->where('status', Todo::STATUS_BLOCKED)->groupBy('user_id') as $held) {
            $longest = $held->sortBy(fn (Todo $t) => $since($t)?->created_at)->first();
            $mins = $ago($since($longest)?->created_at);
            $needs[] = ['kind' => 'hold', 'count' => $held->count(), 'text' => $longest->title, 'person' => $longest->user?->name,
                'detail' => $held->count().' on hold · longest '.$this->span($mins).(filled($since($longest)?->note) ? ' · “'.$since($longest)->note.'”' : '')];
        }
        foreach ($open->where('status', Todo::STATUS_STARTED) as $t) {
            $mins = $ago($since($t)?->created_at);
            if ($mins !== null && $mins >= 180) {
                $needs[] = ['kind' => 'long', 'text' => $t->title, 'person' => $t->user?->name, 'detail' => 'editing for '.$this->span($mins)];
            }
        }

        // ——— Each editor, in one line ———
        $todayOut = fn (string $person) => $moves->where('person', $person)->where('type', 'posted')->count();
        // Waiting on today's board, per editor: what they will pick up next.
        $waiting = $this->scoped(Todo::query()->whereNull('parent_id'))->where('status', Todo::STATUS_WAITING)
            ->onDay(today())->with('user')->orderBy('id')->get();
        // Everyone who has edited in the last two weeks gets a card, moved today or not.
        $crew = \App\Models\User::query()
            ->whereIn('id', $this->scoped(Todo::query()->whereNull('parent_id'))->where('created_at', '>=', now()->subDays(14))->select('user_id'))
            ->get(['id', 'name', 'avatar_path']);
        $people = $moves->pluck('person')->merge($open->map(fn (Todo $t) => $t->user?->name))
            ->merge($waiting->map(fn (Todo $t) => $t->user?->name))->merge($crew->pluck('name'))
            ->filter()->unique()->values();
        $photos = $crew->mapWithKeys(fn ($u) => [$u->name => $u->avatarUrl()])->all();
        $editors = $people->map(function (string $person) use ($moves, $open, $since, $ago, $todayOut, $now, $waiting, $photos) {
            $mine = $open->filter(fn (Todo $t) => $t->user?->name === $person);
            $working = $mine->firstWhere('status', Todo::STATUS_STARTED);
            $last = $moves->firstWhere('person', $person);
            $idle = $last ? $ago(Carbon::parse($last['at'])) : null;

            return [
                'person' => $person,
                'state' => $working ? 'working' : ($mine->isNotEmpty() ? 'hold' : 'idle'),
                'title' => $working?->title,
                'minutes' => $working ? $ago($since($working)?->created_at) : null,
                'held' => $mine->where('status', Todo::STATUS_BLOCKED)->count(),
                'idle' => $working ? null : $idle,
                'last' => $last ? ['label' => $last['label'], 'title' => $last['title'], 'time' => $last['time']] : null,
                'photo' => $photos[$person] ?? null,
                'next' => $waiting->filter(fn (Todo $t) => $t->user?->name === $person)->take(3)->pluck('title')->values()->all(),
                'waiting' => $waiting->filter(fn (Todo $t) => $t->user?->name === $person)->count(),
                'fixes' => $moves->where('person', $person)->where('type', 'fix')->count(),
                'done' => $moves->where('person', $person)->where('type', 'done')->count(),
                'out' => $todayOut($person),
                'added' => $moves->where('person', $person)->where('type', 'added')->count(),
                // For the page: an avatar, and what is on hold with how long and why.
                'initial' => mb_strtoupper(mb_substr($person, 0, 1)),
                'hue' => crc32($person) % 360,
                'started_at' => $working ? $since($working)?->created_at?->getTimestampMs() : null,
                'holding' => $mine->where('status', Todo::STATUS_BLOCKED)
                    ->map(fn (Todo $t) => ['title' => $t->title, 'for' => $this->span($ago($since($t)?->created_at)), 'note' => $since($t)?->note])
                    ->values()->all(),
            ];
        })->sortBy(fn (array $e) => [$e['state'] === 'working' ? 0 : ($e['state'] === 'hold' ? 1 : 2), $e['person']])->values();

        foreach ($editors as $e) {
            $hour = (int) $now->format('G');
            if ($e['state'] === 'idle' && $e['idle'] !== null && $e['idle'] >= 90 && $hour >= 10 && $hour < 20) {
                $needs[] = ['kind' => 'idle', 'text' => $e['person'].' idle '.$this->span($e['idle']), 'person' => $e['person'],
                    'detail' => 'last: '.($e['last']['label'] ?? '').' · '.($e['last']['title'] ?? '')];
            }
        }

        // ——— Recent, without the noise: the last move per video in a 10-minute run ———
        $recent = [];
        foreach ($moves->whereNotIn('type', ['other']) as $m) {
            $prev = end($recent);
            if ($prev && $prev['person'] === $m['person'] && $prev['title'] === $m['title']
                && abs(Carbon::parse($prev['at'])->diffInMinutes(Carbon::parse($m['at']))) < 10) {
                continue; // an older flip on the same video, a moment before
            }
            $recent[] = $m;
            if (count($recent) >= 8) {
                break;
            }
        }

        // Who is who: every need and card carries the editor's id, for a link to their day.
        $ids = $moves->pluck('person_id', 'person')->all() + $open->filter(fn (Todo $t) => $t->user)->mapWithKeys(fn (Todo $t) => [$t->user->name => $t->user_id])->all()
            + $crew->pluck('id', 'name')->all();
        $needs = array_map(fn (array $n) => $n + ['user_id' => $ids[$n['person'] ?? ''] ?? null], $needs);
        $editors = $editors->map(fn (array $e) => $e + ['user_id' => $ids[$e['person']] ?? null]);

        return [
            'needs' => $needs,
            'editors' => $editors->all(),
            'flow' => [
                'editing' => $open->where('status', Todo::STATUS_STARTED)->count(),
                'added' => $moves->where('type', 'added')->count(),
                'done' => $moves->where('type', 'done')->count(),
                'out' => $moves->where('type', 'posted')->count(),
                'fixes' => $moves->where('type', 'fix')->count(),
                'review' => $review->count(),
                'ready' => $ready->count(),
            ],
            'next' => $ready->filter(fn (Todo $t) => $t->publish_at?->gte($now))->take(3)
                ->map(fn (Todo $t) => ['title' => $t->title, 'person' => $t->user?->name, 'at' => $t->publish_at->format('g:i A'),
                    'in' => $this->span((int) $now->diffInMinutes($t->publish_at))])->values()->all(),
            'recent' => array_map(fn (array $m) => array_intersect_key($m, array_flip(['time', 'person', 'type', 'label', 'detail', 'title'])), $recent),
        ];
    }

    public function span(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m';
    }

    /** @return array<string, mixed>|null */
    public function move(TodoUpdate $u): ?array
    {
        $todo = $u->todo;
        if (! $todo) {
            return null;
        }
        $video = $todo->parent ?? $todo;
        $isFix = $todo->parent_id !== null;

        [$type, $label, $detail] = match (true) {
            $isFix && $u->action === TodoUpdate::CREATED => ['fix', 'Correction', $todo->title],
            $isFix && $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_COMPLETED => ['fixed', 'Fixed', $todo->title],
            $isFix => [null, null, null],
            $u->action === TodoUpdate::CREATED => ['added', 'Added', null],
            $u->action === TodoUpdate::POSTED => ['posted', 'Posted', $u->note],
            $u->action === TodoUpdate::DELIVERED => ['posted', 'Delivered', $u->note],
            $u->action === TodoUpdate::READY => ['ready', 'Video ready', $u->note],
            $u->action === TodoUpdate::SCHEDULED => ['ready', 'Scheduled', $u->note],
            $u->action === TodoUpdate::REASSIGNED => ['other', 'Reassigned', $u->note],
            $u->action === TodoUpdate::UNPOSTED => ['other', 'Post undone', null],
            $u->action === TodoUpdate::MOVED => ['moved', $u->to_on?->isSameDay($u->created_at) ? 'Back to today' : 'Moved to '.$u->to_on?->format('D j M'), null],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_BLOCKED => ['hold', 'On hold', $u->note],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_CANCELLED => ['other', 'Removed', null],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_STARTED => ['started', match ($u->from_status) {
                Todo::STATUS_COMPLETED => 'Back to working',
                Todo::STATUS_BLOCKED => 'Resumed',
                default => 'Started',
            }, $u->from_status === Todo::STATUS_COMPLETED ? $u->note : null],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_COMPLETED => ['done', 'Done → review', null],
            $u->action === TodoUpdate::STATUS => ['other', Todo::STATUSES[$u->to_status] ?? (string) $u->to_status, $u->note],
            default => [null, null, null],
        };
        if ($type === null) {
            return null;
        }

        return [
            'id' => $u->id,
            'time' => $u->created_at->format('g:i A'),
            'at' => $u->created_at->toIso8601String(),
            'person' => $video->user?->name ?? 'Someone',
            'person_id' => $video->user_id,
            'by' => $u->user_id && $u->user_id !== $video->user_id ? $u->user?->name : null,
            'type' => $type,
            'label' => $label,
            'detail' => $detail,
            'title' => $video->title,
            'client' => $video->venture,
            'card_id' => $video->id,
            'content_item_id' => $video->content_item_id,
        ];
    }
}
