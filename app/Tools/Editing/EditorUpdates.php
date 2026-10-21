<?php

namespace App\Tools\Editing;

use App\Models\Todo;
use App\Models\User;
use App\Support\EditorBoard;
use App\Tools\Tool;

/**
 * The Editor Updates page as data: what needs the admin, each editor right
 * now, the day's flow, the review and ready queues and the latest moves.
 * Admins see everyone; a manager sees their own team, as on the page.
 */
class EditorUpdates extends Tool
{
    public function name(): string
    {
        return 'editor_updates';
    }

    public function title(): string
    {
        return 'Editor updates';
    }

    public function group(): string
    {
        return 'Editing';
    }

    public function description(): string
    {
        return 'The editing floor right now: what needs you (videos waiting for review, late to publish, '
            .'stuck on hold), each editor\'s state (editing what and for how long, on hold and why, idle), '
            .'today\'s counts (done, out, fixes), what each will pick up next, the review and ready-to-publish '
            .'queues, and the latest moves with times. USE WHEN asked "what are the editors doing", '
            .'"any update from editors", "what is waiting for my review". Admins see every editor; a manager '
            .'sees their own team. Read-only.';
    }

    public function availableTo(User $user): bool
    {
        return $user->isAdmin() || $user->managesAnyone();
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
        $only = $user->isAdmin() ? null : $user->reports()->pluck('users.id')->push($user->id)->all();
        $board = app(EditorBoard::class)->only($only);
        $b = $board->board($board->moves(today()));

        $queue = fn (bool $ready) => Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_COMPLETED)->whereNull('posted_at')
            ->when($only !== null, fn ($q) => $q->whereIn('user_id', $only))
            ->when($ready, fn ($q) => $q->whereNotNull('ready_at')->orderBy('publish_at'), fn ($q) => $q->whereNull('ready_at')->whereDate('closed_on', '>=', today()->subDays(14))->orderBy('closed_on'))
            ->with('user')->limit(20)->get()
            ->map(fn (Todo $t) => array_filter([
                'title' => $t->title,
                'editor' => $t->user?->name,
                'client' => $t->venture,
                'publish_at' => $ready ? $t->publish_at?->format('Y-m-d H:i') : null,
                'late' => $ready ? $t->isLateToPublish() : null,
            ], fn ($v) => $v !== null))->values()->all();

        return [
            'as_of' => now()->format('Y-m-d H:i'),
            'scope' => $only === null ? 'every editor' : 'your team',
            'needs_you' => array_map(fn ($n) => array_intersect_key($n, array_flip(['kind', 'person', 'text', 'detail', 'count'])), $b['needs']),
            'editors' => array_map(fn ($e) => [
                'name' => $e['person'],
                'state' => ['working' => 'editing', 'hold' => 'on hold', 'idle' => 'idle'][$e['state']] ?? $e['state'],
                'editing' => $e['title'],
                'for_minutes' => $e['minutes'],
                'idle_minutes' => $e['idle'],
                'on_hold' => $e['holding'],
                'today' => ['done' => $e['done'], 'out' => $e['out'], 'fixes' => $e['fixes'], 'added' => $e['added']],
                'up_next' => $e['next'],
                'last_move' => $e['last'],
            ], $b['editors']),
            'flow_today' => $b['flow'],
            'waiting_for_review' => $queue(false),
            'ready_to_publish' => $queue(true),
            'latest_moves' => $b['recent'],
        ];
    }
}
