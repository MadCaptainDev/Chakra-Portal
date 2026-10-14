<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\TodoUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Today's to-do moves as JSON -- the feed behind the "Editor Updates" live
 * pane in Claude Code (a mod that runs this every few seconds). The same
 * moves the Editor Updates page shows, newest first, plus per-person counts.
 */
class EditorUpdatesFeed extends Command
{
    protected $signature = 'editor-updates:feed {--date= : Day to read, default today} {--limit=40 : Newest moves to include}';

    protected $description = 'Print a day\'s to-do moves (added, started, corrections, done, posted) as JSON.';

    public function handle(): int
    {
        $day = Carbon::parse($this->option('date') ?: today()->toDateString())->startOfDay();

        $moves = TodoUpdate::query()
            ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
            ->with(['todo.parent', 'todo.user', 'user'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TodoUpdate $u) => $this->move($u))
            ->filter()
            ->values();

        $people = $moves->groupBy('person_id')->map(fn ($mine, $userId) => [
            'person' => $mine->first()['person'],
            'added' => $mine->where('type', 'added')->count(),
            'started' => $mine->where('type', 'started')->count(),
            'fixes' => $mine->where('type', 'fix')->count(),
            'held' => $mine->where('type', 'hold')->count(),
            'done' => $mine->where('type', 'done')->count(),
            'posted' => $mine->where('type', 'posted')->count(),
            'last' => $mine->first()['time'],
            'working' => Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_STARTED)
                ->where('user_id', $userId)->pluck('title')->all(),
            'holding' => Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_BLOCKED)
                ->where('user_id', $userId)->pluck('title')->all(),
            'last_type' => $mine->first()['type'],
            'last_label' => $mine->first()['label'],
            'last_title' => $mine->first()['title'],
            'last_ago' => (int) Carbon::parse($mine->first()['at'])->diffInMinutes(now()),
        ])->map(fn (array $p) => $p + [
            // What the editor is doing now, for the live panes to act out.
            'state' => match (true) {
                $p['working'] !== [] => 'working',
                $p['holding'] !== [] => 'hold',
                $p['last_type'] === 'posted' && $p['last_ago'] < 15 => 'celebrate',
                $p['last_ago'] < 20 => 'active',
                default => 'idle',
            },
        ])->values();

        $this->line(json_encode([
            'board' => $this->board($moves),
            'date' => $day->format('l j F'),
            'generated' => now()->format('g:i:s A'),
            'totals' => [
                'added' => $moves->where('type', 'added')->count(),
                'fixes' => $moves->where('type', 'fix')->count(),
                'done' => $moves->where('type', 'done')->count(),
                'posted' => $moves->where('type', 'posted')->count(),
            ],
            'people' => $people,
            'moves' => $moves->take((int) $this->option('limit'))->values(),
            // Video ready past its publishing time and not posted, for the pane.
            'late' => Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_COMPLETED)
                ->whereNotNull('ready_at')->whereNull('posted_at')->where('publish_at', '<', now())->where('publish_at', '>=', now()->subDay())
                ->with('user')->orderBy('publish_at')->get()
                ->map(fn (Todo $t) => ['id' => $t->id, 'title' => $t->title, 'person' => $t->user?->name, 'due' => $t->publish_at->format('g:i A'), 'minutes' => (int) $t->publish_at->diffInMinutes(now())])->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * The admin's view of the editing floor, in the order it is read: what
     * needs them, then each editor in one line, then the day's flow, then a
     * short de-duplicated list of recent moves.
     *
     * @return array<string, mixed>
     */
    private function board(Collection $moves): array
    {
        $now = now();
        $ago = fn (?Carbon $at) => $at ? (int) $at->diffInMinutes($now) : null;

        $open = Todo::query()->whereNull('parent_id')
            ->whereIn('status', [Todo::STATUS_STARTED, Todo::STATUS_BLOCKED])
            ->with(['user', 'updates'])->get();
        $review = Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_COMPLETED)
            ->whereNull('posted_at')->whereNull('ready_at')->whereDate('closed_on', '>=', today()->subDays(14))
            ->with(['user', 'updates'])->get()
            ->map(fn (Todo $t) => ['title' => $t->title, 'person' => $t->user?->name,
                'waiting' => $ago($t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_COMPLETED)?->created_at ?? $t->updated_at)])
            ->sortByDesc('waiting')->values();
        $ready = Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_COMPLETED)
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
        $people = $moves->pluck('person')->merge($open->map(fn (Todo $t) => $t->user?->name))->filter()->unique()->values();
        $editors = $people->map(function (string $person) use ($moves, $open, $since, $ago, $todayOut, $now) {
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
                'done' => $moves->where('person', $person)->where('type', 'done')->count(),
                'out' => $todayOut($person),
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

        return [
            'needs' => $needs,
            'editors' => $editors->all(),
            'flow' => [
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

    private function span(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m';
    }

    /** @return array<string, mixed>|null */
    private function move(TodoUpdate $u): ?array
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
            $u->action === TodoUpdate::UNPOSTED => ['other', 'Post undone', null],
            $u->action === TodoUpdate::MOVED => ['moved', $u->to_on?->isSameDay($u->created_at) ? 'Back to today' : 'Moved to '.$u->to_on?->format('D j M'), null],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_BLOCKED => ['hold', 'On hold', $u->note],
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
        ];
    }
}
