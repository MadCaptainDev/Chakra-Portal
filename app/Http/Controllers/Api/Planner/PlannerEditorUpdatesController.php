<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\User;
use App\Support\EditorBoard;
use App\Support\PlannerVisuals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editor Updates for Chakra Planner's Team view and menu-bar widget
 * (GET api/planner/editor-updates): the editing floor right now, the same
 * board the portal page and the editor_updates MCP tool read, plus the ids,
 * photos and logos the app links and draws with. Read-only.
 *
 * Admins see every editor; a manager only their reports and themselves;
 * anyone else gets 403 no_access and no one else's data.
 */
class PlannerEditorUpdatesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->managesAnyone()) {
            return response()->json(['message' => 'Editor Updates are for admins and managers.', 'code' => 'no_access'], 403);
        }
        $only = $user->isAdmin() ? null : $user->reports()->pluck('users.id')->push($user->id)->all();

        $boardMaker = app(EditorBoard::class)->only($only);
        $moves = $boardMaker->moves(today());
        $b = $boardMaker->board($moves);
        $now = now();

        $scoped = fn ($q) => $only === null ? $q : $q->whereIn('user_id', $only);
        $open = $scoped(Todo::query()->whereNull('parent_id'))->whereIn('status', [Todo::STATUS_STARTED, Todo::STATUS_BLOCKED, Todo::STATUS_WAITING])
            ->with(['contentItem:id,venture', 'user:id,name,avatar_path', 'updates'])->get();
        $people = User::query()->whereIn('id', collect($b['editors'])->pluck('user_id')->filter())->get(['id', 'name', 'avatar_path'])->keyBy('id');
        $logo = fn (Todo $t) => PlannerVisuals::client($t->contentItem?->venture ?? $t->venture)['logo_url'] ?? null;
        $find = fn (?string $title, ?string $person) => $open->first(fn (Todo $t) => $t->title === $title && (! $person || $t->user?->name === $person));

        $queue = function (bool $ready) use ($scoped, $now, $logo) {
            return $scoped(Todo::query()->whereNull('parent_id'))->where('status', Todo::STATUS_COMPLETED)->whereNull('posted_at')
                ->when($ready, fn ($q) => $q->whereNotNull('ready_at')->orderBy('publish_at'), fn ($q) => $q->whereNull('ready_at')->whereDate('closed_on', '>=', today()->subDays(14))->orderBy('closed_on'))
                ->with(['user:id,name', 'contentItem:id,venture', 'updates'])->limit(20)->get()
                ->map(function (Todo $t) use ($ready, $now, $logo) {
                    $doneAt = $t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_COMPLETED)?->created_at ?? $t->updated_at;

                    return [
                        'title' => $t->title,
                        'editor' => $t->user?->name,
                        'client' => $t->venture,
                        'client_logo_url' => $logo($t),
                        'content_item_id' => $t->content_item_id,
                        'card_id' => $t->id,
                        'waiting_minutes' => $ready ? null : (int) $doneAt->diffInMinutes($now),
                        'publish_at' => $ready ? $t->publish_at?->format('Y-m-d H:i') : null,
                        'publish_label' => $ready && $t->publish_at ? ($t->publish_at->isToday() ? 'Today ' : ($t->publish_at->isTomorrow() ? 'Tomorrow ' : $t->publish_at->format('D j M '))).$t->publish_at->format('g:i A') : null,
                        'late' => $ready ? $t->isLateToPublish() : null,
                    ];
                })->values()->all();
        };

        return response()->json([
            'as_of' => $now->toIso8601String(),
            'server_time' => $now->toIso8601String(),
            'scope' => $only === null ? 'every editor' : 'your team',
            'flow_today' => $b['flow'],
            'needs_you' => array_map(function ($n) use ($find) {
                $todo = $find($n['text'] ?? null, $n['person'] ?? null);

                return array_intersect_key($n, array_flip(['kind', 'person', 'text', 'detail', 'count']))
                    + ['card_id' => $todo?->id, 'content_item_id' => $todo?->content_item_id];
            }, $b['needs']),
            'editors' => array_map(function ($e) use ($open, $people, $logo, $moves) {
                $mine = $open->where('user_id', $e['user_id']);
                $working = $mine->firstWhere('status', Todo::STATUS_STARTED);
                $lastMove = $moves->firstWhere('person_id', $e['user_id']);

                return [
                    'user' => PlannerVisuals::person($people->get($e['user_id'])),
                    'name' => $e['person'],
                    'state' => ['working' => 'editing', 'hold' => 'on hold', 'idle' => 'idle'][$e['state']] ?? $e['state'],
                    'editing' => $e['title'],
                    'editing_item' => $working ? [
                        'card_id' => $working->id,
                        'content_item_id' => $working->content_item_id,
                        'client' => $working->venture,
                        'client_logo_url' => $logo($working),
                        'working_since' => $e['started_at'],
                    ] : null,
                    'for_minutes' => $e['minutes'],
                    'idle_minutes' => $e['idle'],
                    'on_hold' => array_map(function ($h) use ($mine) {
                        $t = $mine->first(fn (Todo $x) => $x->status === Todo::STATUS_BLOCKED && $x->title === $h['title']);

                        return $h + ['card_id' => $t?->id, 'content_item_id' => $t?->content_item_id];
                    }, $e['holding']),
                    'today' => ['done' => $e['done'], 'out' => $e['out'], 'fixes' => $e['fixes'], 'added' => $e['added']],
                    'up_next' => $e['next'],
                    'up_next_items' => $mine->where('status', Todo::STATUS_WAITING)->filter(fn (Todo $t) => in_array($t->title, $e['next'], true))->take(3)
                        ->map(fn (Todo $t) => ['title' => $t->title, 'card_id' => $t->id, 'content_item_id' => $t->content_item_id, 'client_logo_url' => $logo($t)])->values()->all(),
                    'last_move' => $e['last'] ? $e['last'] + ['at' => $lastMove['at'] ?? null] : null,
                ];
            }, $b['editors']),
            'waiting_for_review' => $queue(false),
            'ready_to_publish' => $queue(true),
            'latest_moves' => $moves->take(30)->map(fn ($m) => [
                'time' => $m['time'], 'at' => $m['at'], 'person' => $m['person'], 'person_id' => $m['person_id'],
                'type' => $m['type'], 'label' => $m['label'], 'detail' => $m['detail'], 'title' => $m['title'],
                'card_id' => $m['card_id'] ?? null, 'content_item_id' => $m['content_item_id'] ?? null,
            ])->values()->all(),
        ]);
    }
}
