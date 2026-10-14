<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\TodoUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
