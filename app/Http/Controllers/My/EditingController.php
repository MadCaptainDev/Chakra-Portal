<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Services\Notion\ContentSyncService;
use App\Support\ClientGuess;
use App\Support\PlannerVisuals;
use App\Support\TimesheetVenture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * "My Editing": an editor's videos for the day, on one screen, each a card
 * that moves To do → Working → Done → Posted with a tap, corrections hanging
 * off it as branches.
 *
 * The cards are ordinary to-dos (the board, the reviews and the history all
 * still apply); this screen is a faster way in. Adding is meant to take one
 * tap: the editor's own upcoming Reel Planner items are offered first --
 * the planner is the publishing calendar, so tomorrow's and next week's
 * videos are there to be edited ahead -- and their usual clients and recent
 * titles are offered as hints when typing.
 *
 * Every action answers JSON with the card as it now stands; the page never
 * reloads except after a Notion refresh.
 */
class EditingController extends Controller
{
    /** Planner statuses that still need an editor. */
    private const TO_EDIT = ['To Be Edited', 'Edit in Progress', 'Under Review', 'To Be Shooted', 'Video Ready'];

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('my.editing', [
            'teamReady' => $this->teamReady($user),
            'teamReview' => $this->teamReview($user),
            'editors' => $this->editors($user),
            'cards' => $this->cards($user),
            'planner' => $this->planner($user),
            'hints' => $this->hints($user),
            'kinds' => Todo::KINDS,
        ]);
    }

    /**
     * The board as JSON, for Chakra Planner's top-bar widget
     * (GET api/planner/editing) and anything else that wants it: your
     * videos, the team's ready-to-post videos anyone can post, and the
     * planner's suggestions to add.
     */
    public function board(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isClient(), 403);

        // The planner videos this editor can add, as items the app can add by id.
        $items = collect($this->planner($user))->take(60)->map(function (array $p) {
            $client = PlannerVisuals::client($p['client']);

            return ['content_item_id' => $p['id'], 'title' => $p['title'], 'client' => $p['client'], 'client_logo_url' => $client['logo_url'] ?? null];
        })->values()->all();

        return response()->json([
            'cards' => $this->cards($user),
            'team_ready' => $this->teamReady($user),
            'team_review' => $this->teamReview($user),
            'suggestions' => $this->hints($user) + ['items' => $items],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** The walkthrough: a self-playing "how this screen works" for editors. */
    public function guide(Request $request): View
    {
        abort_if($request->user()->isClient(), 403);

        return view('my.editing-guide');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'title' => ['required_without:content_item_id', 'nullable', 'string', 'max:255'],
            'content_item_id' => ['nullable', 'integer', 'exists:content_items,id'],
            'starts_on' => ['nullable', 'date'],
        ]);

        $item = isset($data['content_item_id']) ? ContentItem::find($data['content_item_id']) : null;
        $title = trim($item?->title ?? $data['title']);

        // Already on the board? Hand that card back rather than a second one.
        $existing = Todo::query()->where('user_id', $user->id)->whereNull('parent_id')
            ->where('status', '!=', Todo::STATUS_CANCELLED)
            ->when($item, fn ($q) => $q->where('content_item_id', $item->id), fn ($q) => $q->where('title', $title)->open())
            ->first();
        if ($existing) {
            return response()->json(['card' => $this->card($existing->load('corrections', 'updates', 'contentItem')), 'existing' => true]);
        }

        $todo = Todo::create([
            'user_id' => $user->id,
            'assigned_by_id' => $user->id,
            'title' => $title,
            'venture' => $item?->venture && in_array($item->venture, TimesheetVenture::allowedValues(), true) ? $item->venture : ClientGuess::forTitle($title),
            'content_item_id' => $item?->id,
            'kind' => $item ? (in_array($item->source, ['reel', 'post', 'story'], true) ? $item->source : null) : null,
            'starts_on' => ($data['starts_on'] ?? null) ?: today()->toDateString(),
            'due_on' => ($data['starts_on'] ?? null) ?: today()->toDateString(),
        ]);
        TodoUpdate::record($todo, $user, TodoUpdate::CREATED, ['to_status' => $todo->status, 'from_on' => $todo->starts_on, 'to_on' => $todo->due_on]);

        return response()->json(['card' => $this->card($todo->load('corrections', 'updates', 'contentItem'))]);
    }

    /**
     * start | done | reopen | ready | schedule | unschedule | post | deliver | unpost | kind | hold | next | today | reassign
     *
     * Done sends a video to review (completed, not out yet). From review it
     * gets a correction (back to Working, see correct()), is delivered to the
     * client, or is approved as Video ready with the time it is to be
     * published (ready, again to change the time). Only a ready video is
     * marked posted, as a Reel, Post or Story; PublishReminders pushes when
     * one is still not posted 30 minutes after its time.
     */
    public function act(Request $request, Todo $todo): JsonResponse
    {
        $user = $request->user();
        // Getting a ready video out is anyone's job; editing it is its editor's.
        match (true) {
            in_array($request->input('action'), self::ANYONE, true) && ! $user->isClient() => abort_unless($todo->parent_id === null && $todo->user_id !== null, 404),
            // Handing a video on: its editor, whoever gave it, an admin, or the editor's manager.
            $request->input('action') === 'reassign' && $todo->user && $user->manages($todo->user) => null,
            default => $this->authorise($todo, $user),
        };
        $data = $request->validate([
            'action' => ['required', 'in:start,done,reopen,ready,schedule,unschedule,post,deliver,unpost,kind,hold,next,today,remove,reassign'],
            'user_id' => ['required_if:action,reassign', 'nullable', 'integer'],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(Todo::KINDS))],
            'post_url' => ['nullable', 'url', 'max:500'],
            'note' => ['nullable', 'string', 'max:255'],
            'publish_at' => ['required_if:action,ready', 'required_if:action,schedule', 'nullable', 'date'],
        ], ['publish_at.required_if' => 'Set when it is to be published.', 'user_id.required_if' => 'Pick who takes it over.']);
        if (in_array($data['action'], ['hold', 'next', 'today'], true) && ($todo->parent_id || ! $todo->isOpen())) {
            return response()->json(['message' => 'Only a video still on your board can be held or moved.'], 422);
        }

        // Remove: a video added by mistake, or not this editor's after all. It is
        // cancelled, not deleted -- its history stays -- and leaves the board;
        // a planner item comes back to Up next. Not once it has gone out.
        if ($data['action'] === 'remove') {
            if ($todo->parent_id || $todo->posted_at) {
                return response()->json(['message' => 'A video that has gone out cannot be removed.'], 422);
            }
            if ($todo->status !== Todo::STATUS_CANCELLED) {
                $todo->moveTo(Todo::STATUS_CANCELLED, $user, $data['note'] ?? 'Removed from the board');
                $todo->corrections()->open()->get()->each(fn (Todo $fix) => $fix->moveTo(Todo::STATUS_CANCELLED, $user, 'Video removed'));
                $todo->forceFill(['ready_at' => null, 'publish_at' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null])->save();
            }

            return response()->json(['removed' => $todo->id]);
        }

        if ($data['action'] === 'reassign') {
            return $this->reassign($todo, $user, (int) $data['user_id']);
        }

        switch ($data['action']) {
            case 'schedule':
                // Scheduled on Instagram/YouTube for its time: no reminder, and
                // it counts as posted when the time comes (PublishReminders).
                if (! $todo->isReady()) {
                    return response()->json(['message' => 'Only a Video ready can be scheduled.'], 422);
                }
                $at = Carbon::parse($data['publish_at']);
                $kind = in_array($data['kind'] ?? null, Todo::POST_KINDS, true) ? $data['kind'] : (in_array($todo->kind, Todo::POST_KINDS, true) ? $todo->kind : 'reel');
                $todo->forceFill(['kind' => $kind, 'publish_at' => $at, 'scheduled_at' => now(), 'scheduled_by_id' => $user->id, 'publish_reminded_at' => null])->save();
                TodoUpdate::record($todo, $user, TodoUpdate::SCHEDULED, ['note' => Todo::KINDS[$kind].' for '.$at->format('D j M, g:i A')]);
                break;
            case 'unschedule':
                if ($todo->scheduled_at && ! $todo->posted_at) {
                    $todo->forceFill(['scheduled_at' => null, 'scheduled_by_id' => null])->save();
                    TodoUpdate::record($todo, $user, TodoUpdate::SCHEDULED, ['note' => 'Unscheduled']);
                }
                break;
            case 'start':
                if ($todo->status !== Todo::STATUS_STARTED) {
                    $todo->moveTo(Todo::STATUS_STARTED, $user);
                }
                break;
            case 'done':
                if ($todo->corrections()->open()->exists()) {
                    return response()->json(['message' => 'Finish the open corrections first.'], 422);
                }
                $todo->moveTo(Todo::STATUS_COMPLETED, $user);
                if (! $todo->parent_id && isset($data['kind']) && in_array($data['kind'], Todo::POST_KINDS, true)) {
                    $todo->forceFill(['kind' => $data['kind']])->save();
                }
                break;
            case 'reopen':
                $todo->moveTo(Todo::STATUS_STARTED, $user, 'Reopened');
                $todo->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null, 'ready_at' => null, 'publish_at' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null])->save();
                break;
            case 'kind':
                $todo->forceFill(['kind' => $data['kind'] ?? null])->save();
                break;
            case 'ready':
                if ($todo->parent_id || $todo->status !== Todo::STATUS_COMPLETED || $todo->posted_at) {
                    return response()->json(['message' => 'Only a video in review can be made ready.'], 422);
                }
                if ($todo->corrections()->open()->exists()) {
                    return response()->json(['message' => 'Finish the open corrections first.'], 422);
                }
                $at = Carbon::parse($data['publish_at']);
                $wasReady = $todo->ready_at !== null;
                $todo->forceFill([
                    'ready_at' => $todo->ready_at ?? now(),
                    'publish_at' => $at,
                    'publish_reminded_at' => null,
                    // A new time means the post has to be scheduled again.
                    'scheduled_at' => null,
                    'scheduled_by_id' => null,
                ])->save();
                TodoUpdate::record($todo, $user, TodoUpdate::READY, ['note' => ($wasReady ? 'New time: ' : 'Publish ').$at->format('D j M, g:i A')]);
                break;
            case 'post':
                if ($todo->corrections()->open()->exists()) {
                    return response()->json(['message' => 'Finish the open corrections first.'], 422);
                }
                if (! $todo->ready_at) {
                    return response()->json(['message' => 'Make it Video ready, with its publishing time, first.'], 422);
                }
                $kind = $data['kind'] ?? $todo->kind;
                $todo->forceFill([
                    'kind' => in_array($kind, Todo::POST_KINDS, true) ? $kind : 'reel',
                    'posted_at' => now(),
                    'post_url' => $data['post_url'] ?? null,
                    'posted_by_id' => $user->id,
                ])->save();
                TodoUpdate::record($todo, $user, TodoUpdate::POSTED, ['note' => trim((Todo::KINDS[$todo->kind] ?? 'Posted').' '.($todo->post_url ?? ''))]);
                break;
            case 'deliver':
                if ($todo->corrections()->open()->exists()) {
                    return response()->json(['message' => 'Finish the open corrections first.'], 422);
                }
                // From review only its editor or an admin decides; anyone else delivers a ready video.
                if (! $todo->ready_at && ! ($todo->user_id === $user->id || $todo->assigned_by_id === $user->id || $user->isAdmin())) {
                    return response()->json(['message' => 'This video is still in review. Once it is Video ready, anyone can deliver it.'], 422);
                }
                if ($todo->status !== Todo::STATUS_COMPLETED) {
                    $todo->moveTo(Todo::STATUS_COMPLETED, $user);
                }
                $todo->forceFill([
                    'kind' => Todo::KIND_DELIVERED,
                    'posted_at' => now(),
                    'post_url' => $data['post_url'] ?? null,
                    'posted_by_id' => $user->id,
                ])->save();
                TodoUpdate::record($todo, $user, TodoUpdate::DELIVERED, ['note' => $todo->post_url]);
                break;
            case 'unpost':
                $wasDelivered = $todo->kind === Todo::KIND_DELIVERED;
                $todo->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null] + ($wasDelivered ? ['kind' => null] : []))->save();
                TodoUpdate::record($todo, $user, TodoUpdate::UNPOSTED, ['note' => $wasDelivered ? 'Delivery undone' : null]);
                break;
            case 'hold':
                // Stuck on something outside the editor's hands. Stops the
                // working clock; the reason is what Editor Updates shows.
                if ($todo->status !== Todo::STATUS_BLOCKED) {
                    $todo->moveTo(Todo::STATUS_BLOCKED, $user, filled($data['note'] ?? null) ? trim($data['note']) : null);
                }
                break;
            case 'next':
                // Not today: off today's lanes until the next day. A video
                // being worked on is paused first, or its clock would run all
                // night.
                if ($todo->status === Todo::STATUS_STARTED) {
                    $todo->moveTo(Todo::STATUS_WAITING, $user, 'Paused');
                }
                $todo->defer($user);
                break;
            case 'today':
                if (! $todo->due_on->gt(today())) {
                    break;
                }
                $from = $todo->due_on->copy();
                $todo->forceFill([
                    'due_on' => today()->toDateString(),
                    'starts_on' => $todo->starts_on->min(today())->toDateString(),
                ])->save();
                TodoUpdate::record($todo, $user, TodoUpdate::MOVED, ['from_on' => $from, 'to_on' => today(), 'note' => 'Back to today']);
                break;
        }

        $video = $todo->parent_id ? $todo->parent : $todo;

        return response()->json(['card' => $this->card($video->fresh()->load('corrections', 'updates', 'contentItem'))]);
    }

    /** A correction: a branch on the video. A finished video goes back to Working. */
    public function correct(Request $request, Todo $todo): JsonResponse
    {
        $user = $request->user();
        $this->authorise($todo, $user);
        abort_if($todo->parent_id !== null, 422, 'A correction cannot have corrections.');
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $fix = Todo::create([
            'user_id' => $todo->user_id,
            'assigned_by_id' => $user->id,
            'parent_id' => $todo->id,
            'title' => trim($data['title']),
            'venture' => $todo->venture,
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);
        TodoUpdate::record($fix, $user, TodoUpdate::CREATED, ['to_status' => $fix->status, 'from_on' => $fix->starts_on, 'to_on' => $fix->due_on]);

        if ($todo->status === Todo::STATUS_COMPLETED) {
            $todo->moveTo(Todo::STATUS_STARTED, $user, 'Correction: '.$fix->title);
            $todo->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null, 'ready_at' => null, 'publish_at' => null, 'publish_reminded_at' => null, 'scheduled_at' => null, 'scheduled_by_id' => null])->save();
        }

        return response()->json(['card' => $this->card($todo->fresh()->load('corrections', 'updates', 'contentItem'))]);
    }

    /** The small refresh: pull the Reel Planner from Notion again. */
    public function refresh(ContentSyncService $sync): JsonResponse
    {
        $count = $sync->syncSource(ContentItem::SOURCE_REEL);

        return response()->json(['message' => "Reel Planner refreshed — {$count} items."]);
    }

    /**
     * What anyone on the team may do to someone else's video: mark a ready
     * video posted, scheduled or delivered (or undo that), and say what kind
     * of post it was. Starting, holding, finishing and removing stay the editor's.
     */
    private const ANYONE = ['post', 'deliver', 'unpost', 'kind', 'schedule', 'unschedule'];

    private function authorise(Todo $todo, User $user): void
    {
        abort_unless($todo->user_id === $user->id || $todo->assigned_by_id === $user->id || $user->isAdmin(), 404);
    }

    /** Videos on today's board: open ones, and anything finished or posted today. */
    private function cards(User $user): array
    {
        return Todo::query()
            ->where('user_id', $user->id)
            ->whereNull('parent_id')
            ->where('status', '!=', Todo::STATUS_CANCELLED)
            ->where(fn ($q) => $q->open()->orWhereDate('closed_on', today())->orWhereDate('posted_at', today())
                ->orWhere(fn ($q) => $q->where('status', Todo::STATUS_COMPLETED)->whereNull('posted_at')->whereDate('closed_on', '>=', today()->subDays(14))))
            ->with('corrections', 'updates', 'contentItem', 'user:id,name,avatar_path')
            ->orderBy('starts_on')->orderBy('id')
            ->get()
            ->map(fn (Todo $t) => $this->card($t))
            ->values()
            ->all();
    }

    /**
     * Everyone else's approved videos waiting to go out, by publishing time:
     * posting is anyone's job, so whoever is free can mark them posted.
     *
     * @return list<array<string, mixed>>
     */
    private function teamReady(User $user): array
    {
        return Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_COMPLETED)
            ->whereNotNull('ready_at')->whereNull('posted_at')->where('user_id', '!=', $user->id)
            ->with(['user:id,name,avatar_path', 'contentItem:id,venture'])->orderBy('publish_at')->get()
            ->map(fn (Todo $t) => self::visuals($t) + [
                'id' => $t->id,
                'title' => $t->title,
                'client' => $t->venture,
                'editor' => $t->user?->name,
                'kind' => in_array($t->kind, Todo::POST_KINDS, true) ? $t->kind : 'reel',
                'publish_label' => $t->publish_at ? ($t->publish_at->isToday() ? 'Today ' : ($t->publish_at->isTomorrow() ? 'Tomorrow ' : $t->publish_at->format('D j M '))).$t->publish_at->format('g:i A') : null,
                'publish_ms' => $t->publish_at?->getTimestampMs(),
                'late' => $t->isLateToPublish(),
                'scheduled' => $t->scheduled_at !== null,
                'publish_at' => $t->publish_at?->format('Y-m-d\TH:i'),
            ])->values()->all();
    }

    /**
     * Everyone else's videos in review (done, not yet Video ready), oldest
     * first, so the team sees what is waiting -- the same two weeks the
     * board keeps them.
     *
     * @return list<array<string, mixed>>
     */
    private function teamReview(User $user): array
    {
        return Todo::query()->whereNull('parent_id')->where('status', Todo::STATUS_COMPLETED)
            ->whereNull('ready_at')->whereNull('posted_at')->where('user_id', '!=', $user->id)
            ->whereDate('closed_on', '>=', today()->subDays(14))
            ->with(['user:id,name,avatar_path', 'contentItem:id,venture', 'updates', 'corrections'])->orderBy('closed_on')->orderBy('id')->get()
            ->map(function (Todo $t) {
                $doneAt = $t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_COMPLETED)?->created_at ?? $t->updated_at;

                return self::visuals($t) + [
                    'id' => $t->id,
                    'title' => $t->title,
                    'client' => $t->venture,
                    'editor' => $t->user?->name,
                    'waiting' => $doneAt->diffForHumans(null, true),
                    'sent_back' => $t->wasSentBack() ? $t->review_note : null,
                ];
            })->values()->all();
    }

    /**
     * Who a video can be handed to: the people who have edited in the last
     * two months (and the person looking), by name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function editors(User $user): array
    {
        $ids = Todo::query()->whereNull('parent_id')->where(fn ($q) => $q->whereNotNull('content_item_id')->orWhereNotNull('kind'))
            ->where('created_at', '>=', now()->subMonths(2))->distinct()->pluck('user_id')->push($user->id);

        return User::query()->whereIn('id', $ids)->where('role', '!=', User::ROLE_CLIENT)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
    }

    /**
     * Hand a video to another editor, with its open corrections. A video
     * being worked on is paused first, so the old editor's clock stops.
     */
    private function reassign(Todo $todo, User $by, int $toId): JsonResponse
    {
        $to = User::find($toId);
        if (! $to || $to->isClient()) {
            return response()->json(['message' => 'Pick someone on the team.'], 422);
        }
        if ($todo->parent_id || $todo->posted_at || $todo->status === Todo::STATUS_CANCELLED) {
            return response()->json(['message' => 'Only a video still being made can be handed on.'], 422);
        }
        if ($todo->user_id === $to->id) {
            return response()->json(['message' => 'It is already '.$to->name.'’s.'], 422);
        }

        $from = $todo->user;
        if ($todo->status === Todo::STATUS_STARTED) {
            $todo->moveTo(Todo::STATUS_WAITING, $by, 'Paused');
        }
        $todo->forceFill(['user_id' => $to->id])->save();
        $todo->corrections()->open()->update(['user_id' => $to->id]);
        TodoUpdate::record($todo, $by, TodoUpdate::REASSIGNED, ['note' => ($from?->name ?? 'No one').' → '.$to->name]);

        return response()->json([
            'reassigned' => $todo->id,
            'to' => $to->name,
            // Still on this board only if it was handed to the person looking.
            'card' => $to->id === $by->id ? $this->card($todo->fresh()->load('corrections', 'updates', 'contentItem')) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function card(Todo $t): array
    {
        $publish = $t->contentItem?->published_date;

        return [
            'id' => $t->id,
            'title' => $t->title,
            'client' => $t->venture,
            'status' => $t->status,
            'kind' => $t->kind,
            'starts_on' => $t->starts_on?->toDateString(),
            'publishes' => $publish ? ($publish->isToday() ? 'Today' : ($publish->isTomorrow() ? 'Tomorrow' : $publish->format('D j M'))) : null,
            'posted_at' => $t->posted_at?->format('g:i A'),
            'ready' => $t->ready_at !== null,
            'publish_at' => $t->publish_at?->format('Y-m-d\TH:i'),
            'publish_label' => $t->publish_at ? ($t->publish_at->isToday() ? 'Today ' : ($t->publish_at->isTomorrow() ? 'Tomorrow ' : $t->publish_at->format('D j M '))).$t->publish_at->format('g:i A') : null,
            'publish_ms' => $t->publish_at?->getTimestampMs(),
            'scheduled' => $t->scheduled_at !== null && $t->posted_at === null,
            'publish_default' => ($publish && $publish->gte(today()) ? $publish->copy()->setTime(18, 0) : now()->addHour()->startOfHour())->format('Y-m-d\TH:i'),
            'post_url' => $t->post_url,
            'working_minutes' => $t->workingMinutes(),
            'working_since' => $t->status === Todo::STATUS_STARTED ? optional($t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_STARTED))->created_at?->getTimestampMs() : null,
            'sent_back' => $t->wasSentBack() ? $t->review_note : null,
            'held' => $t->status === Todo::STATUS_BLOCKED ? (string) $t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_BLOCKED)?->note : null,
            'next_on' => $this->nextOn($t),
            'corrections' => $t->corrections->map(fn (Todo $c) => ['id' => $c->id, 'title' => $c->title, 'status' => $c->status])->values()->all(),
        ] + self::visuals($t);
    }

    /**
     * For Chakra Planner's panel: the planner video and its portal script,
     * the client and their logo, the editor and their photo. Additive.
     *
     * @return array<string, mixed>
     */
    private static function visuals(Todo $t): array
    {
        $client = PlannerVisuals::client($t->contentItem?->venture ?? $t->venture);

        return [
            'content_item_id' => $t->content_item_id,
            'script_id' => $t->content_item_id ? \App\Models\Script::query()->where('content_item_id', $t->content_item_id)->latest('updated_at')->value('id') : null,
            'client_id' => $client['id'] ?? null,
            'client_logo_url' => $client['logo_url'] ?? null,
            'user' => PlannerVisuals::person($t->relationLoaded('user') ? $t->user : $t->user()->first(['id', 'name', 'avatar_path'])),
        ];
    }

    /**
     * The day a video was moved on to, while that is still ahead: it sits
     * off today's lanes until then. Only a move made with "Next day" counts
     * -- a to-do from My To-dos that simply spans the week is not "later".
     */
    private function nextOn(Todo $t): ?string
    {
        if (! $t->isOpen() || $t->status === Todo::STATUS_STARTED || ! $t->due_on?->gt(today())) {
            return null;
        }
        $moved = $t->updates->last(fn ($u) => $u->action === TodoUpdate::MOVED);
        if (! $moved?->to_on?->gt(today())) {
            return null;
        }

        return $t->due_on->isTomorrow() ? 'Tomorrow' : $t->due_on->format('D j M');
    }

    /**
     * Reel Planner items still to be edited, for "Up next" and the search
     * box: the publishing calendar read as "what to edit next". The editor's
     * own (assigned in the portal, or named as editor in Notion) are marked
     * and come first; everybody else's are still findable by typing, because
     * editors cover for each other. Items already on this board are left out.
     */
    private function planner(User $user): array
    {
        $first = strtok($user->name, ' ');
        $taken = Todo::query()->where('user_id', $user->id)->whereNotNull('content_item_id')
            ->where('status', '!=', Todo::STATUS_CANCELLED)->pluck('content_item_id');

        return ContentItem::query()
            ->whereIn('status', self::TO_EDIT)
            ->where(fn ($q) => $q->whereNull('published_date')->orWhereBetween('published_date', [today()->subDays(3), today()->addDays(21)]))
            ->whereNotIn('id', $taken)
            ->orderByRaw('published_date is null')->orderBy('published_date')
            ->limit(250)
            ->get()
            ->filter(fn (ContentItem $i) => filled($i->title))
            ->map(fn (ContentItem $i) => [
                'id' => $i->id,
                'title' => trim($i->title),
                'client' => $i->venture,
                'editor' => $i->editor,
                'status' => $i->status,
                'source' => $i->source,
                'mine' => $i->assigned_user_id === $user->id || ($first && $i->editor && stripos($i->editor, $first) !== false),
                'publishes' => $i->published_date ? ($i->published_date->isToday() ? 'Today' : ($i->published_date->isTomorrow() ? 'Tomorrow' : $i->published_date->format('D j M'))) : 'No date',
                'late' => $i->published_date?->lt(today()) ?? false,
            ])
            ->sortByDesc('mine')
            ->values()
            ->all();
    }

    /**
     * Hints from what this editor usually does, for the search box: the
     * clients they work on most (as the prefix they write), titles they have
     * used, and how many videos a day they get through. Read from their
     * cards here and from the Reel Planner, where Notion names them as the
     * editor -- months of history, so the hints work from day one.
     */
    private function hints(User $user): array
    {
        $first = strtok($user->name, ' ');
        $cards = Todo::query()->where('user_id', $user->id)->whereNull('parent_id')
            ->whereDate('starts_on', '>=', today()->subDays(45))->latest('id')->get(['title', 'starts_on', 'status']);
        $notion = $first ? ContentItem::query()
            ->where('editor', 'like', "%{$first}%")
            ->whereBetween('published_date', [today()->subDays(60), today()])
            ->latest('published_date')
            ->get(['title', 'published_date']) : collect();

        $titles = $cards->pluck('title')->merge($notion->pluck('title'))->map(fn ($t) => trim(preg_replace('/\s+/', ' ', $t)))->filter();
        $prefixes = $titles->map(fn ($t) => ClientGuess::prefix($t))->filter()->countBy()->sortDesc()->keys()->take(8)->values()->all();

        $doneByDay = $cards->where('status', Todo::STATUS_COMPLETED)->groupBy(fn ($t) => $t->starts_on?->toDateString())->map->count();
        if ($doneByDay->isEmpty()) {
            $doneByDay = $notion->where('published_date', '>=', today()->subDays(30))->groupBy(fn ($i) => $i->published_date->toDateString())->map->count();
        }

        return [
            'clients' => $prefixes,
            'titles' => $titles->unique(fn ($t) => mb_strtolower($t))->take(150)->values()->all(),
            'per_day' => $doneByDay->isEmpty() ? null : (int) round($doneByDay->avg()),
        ];
    }
}
