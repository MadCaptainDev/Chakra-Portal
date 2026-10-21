<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Support\EditorBoard;
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

        $board = app(EditorBoard::class);
        $moves = $board->moves($day);

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
            'board' => $board->board($moves),
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
}
