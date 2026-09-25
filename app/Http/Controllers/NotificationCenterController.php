<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\ProposalComment;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The bell in the top bar. Not a new subsystem -- just a read-only merge of
 * things that already exist (a person's own open to-dos, active
 * announcements, client comments on their proposals) into one feed, newest
 * first. Nothing here is persisted:
 * "seen" state lives client-side (see topbar.js), so adding a source is
 * exactly one more query pushed onto $items below, no migration required.
 */
class NotificationCenterController extends Controller
{
    public function feed(): JsonResponse
    {
        $user = auth()->user();
        $items = collect();

        // Open work assigned to this person, most recently touched first.
        // Everyone who logs work has a stake in this, admin or not.
        if ($user->logsWork() || $user->isAdmin()) {
            Todo::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [Todo::STATUS_WAITING, Todo::STATUS_STARTED, Todo::STATUS_BLOCKED])
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->each(function (Todo $todo) use ($items) {
                    $items->push([
                        'type' => 'todo',
                        'title' => $todo->title,
                        'subtitle' => $todo->due_on
                            ? 'Due '.$todo->due_on->format('j M')
                            : Todo::STATUSES[$todo->status] ?? ucfirst($todo->status),
                        'url' => Route::has('my.todos') ? route('my.todos') : null,
                        'at' => $todo->updated_at?->toIso8601String(),
                    ]);
                });
        }

        // Active announcements, visible to whoever can see the module (and,
        // separately, to clients when flagged -- but the bell only ships to
        // signed-in staff for now).
        if (Route::has('announcements.index') && $user->can('announcements.view')) {
            Announcement::query()
                ->active()
                ->latest('created_at')
                ->limit(5)
                ->get()
                ->each(function (Announcement $announcement) use ($items) {
                    $items->push([
                        'type' => 'announcement',
                        'title' => $announcement->title,
                        'subtitle' => 'Announcement',
                        'url' => route('announcements.index'),
                        'at' => $announcement->created_at?->toIso8601String(),
                    ]);
                });
        }

        // Clients commenting on a proposal through its public link, still
        // unresolved -- on the proposals this person created, or on every
        // proposal for an admin. Resolving one on the proposal page drops it.
        if (Route::has('proposals.show') && $user->can('proposals.view')) {
            ProposalComment::query()
                ->whereNull('user_id')
                ->whereNull('resolved_at')
                ->whereHas('proposal', fn ($q) => $user->isAdmin() ? $q : $q->where('created_by_id', $user->id))
                ->with('proposal:id,title')
                ->latest()
                ->limit(5)
                ->get()
                ->each(function (ProposalComment $comment) use ($items) {
                    $items->push([
                        'type' => 'proposal-comment',
                        'title' => $comment->author_name.' on '.$comment->proposal->title,
                        'subtitle' => Str::limit($comment->body, 80),
                        'url' => route('proposals.show', $comment->proposal).'#comment-'.$comment->id,
                        'at' => $comment->created_at?->toIso8601String(),
                    ]);
                });
        }

        $sorted = $items
            ->filter(fn (array $item) => $item['at'] !== null)
            ->sortByDesc('at')
            ->values()
            ->take(10);

        return response()->json(['items' => $sorted]);
    }
}
