<?php

namespace App\Tools\Editing;

use App\Models\Todo;
use App\Models\User;
use App\Tools\Tool;

/**
 * The caller's own My Editing board: today's videos and where each stands,
 * corrections to fix, and what is waiting for review or publishing.
 */
class MyEditing extends Tool
{
    public function name(): string
    {
        return 'my_editing';
    }

    public function title(): string
    {
        return 'My editing';
    }

    public function group(): string
    {
        return 'Editing';
    }

    public function description(): string
    {
        return 'Your own My Editing board: the videos on it today (to start, editing, on hold), '
            .'corrections the admin sent back, videos you finished that wait for review, and approved ones '
            .'waiting to be posted with their publishing time. USE WHEN an editor asks "what do I edit next", '
            .'"any corrections for me", "what is waiting". To see the whole team use editor_updates. Read-only.';
    }

    public function availableTo(User $user): bool
    {
        return ! $user->isClient();
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        $mine = Todo::query()->where('user_id', $user->id)->whereNull('parent_id');
        $row = fn (Todo $t) => array_filter([
            'id' => $t->id,
            'title' => $t->title,
            'client' => $t->venture,
            'status' => Todo::STATUSES[$t->status] ?? $t->status,
            'publish_at' => $t->publish_at?->format('Y-m-d H:i'),
        ], fn ($v) => $v !== null);

        $fixes = Todo::query()->where('user_id', $user->id)->whereNotNull('parent_id')
            ->whereIn('status', Todo::OPEN_STATUSES)->with('parent')->get()
            ->map(fn (Todo $t) => ['video' => $t->parent?->title, 'fix' => $t->title]);

        return [
            'today' => (clone $mine)->whereIn('status', Todo::OPEN_STATUSES)->onDay(today())->orderBy('id')->get()->map($row)->values()->all(),
            'corrections_to_fix' => $fixes->values()->all(),
            'waiting_for_review' => (clone $mine)->where('status', Todo::STATUS_COMPLETED)->whereNull('ready_at')->whereNull('posted_at')
                ->whereDate('closed_on', '>=', today()->subDays(14))->get()->map($row)->values()->all(),
            'ready_to_publish' => (clone $mine)->where('status', Todo::STATUS_COMPLETED)->whereNotNull('ready_at')->whereNull('posted_at')
                ->orderBy('publish_at')->get()->map($row)->values()->all(),
            'out_today' => (clone $mine)->whereDate('posted_at', today())->count(),
        ];
    }
}
