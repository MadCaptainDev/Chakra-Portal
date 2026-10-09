<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Services\Notion\ContentSyncService;
use App\Support\ClientGuess;
use App\Support\TimesheetVenture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'cards' => $this->cards($user),
            'suggestions' => $this->suggestions($user),
            'hints' => $this->hints($user),
            'kinds' => Todo::KINDS,
        ]);
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

    /** start | done | reopen | post | unpost | kind */
    public function act(Request $request, Todo $todo): JsonResponse
    {
        $user = $request->user();
        $this->authorise($todo, $user);
        $data = $request->validate([
            'action' => ['required', 'in:start,done,reopen,post,unpost,kind'],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(Todo::KINDS))],
            'post_url' => ['nullable', 'url', 'max:500'],
        ]);

        switch ($data['action']) {
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
                if ($todo->parent_id) {
                    break;
                }
                if (isset($data['kind'])) {
                    $todo->forceFill(['kind' => $data['kind']])->save();
                }
                break;
            case 'reopen':
                $todo->moveTo(Todo::STATUS_STARTED, $user, 'Reopened');
                $todo->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null])->save();
                break;
            case 'kind':
                $todo->forceFill(['kind' => $data['kind'] ?? null])->save();
                break;
            case 'post':
                if ($todo->status !== Todo::STATUS_COMPLETED) {
                    $todo->moveTo(Todo::STATUS_COMPLETED, $user);
                }
                $todo->forceFill([
                    'kind' => $data['kind'] ?? $todo->kind ?? 'reel',
                    'posted_at' => now(),
                    'post_url' => $data['post_url'] ?? null,
                    'posted_by_id' => $user->id,
                ])->save();
                break;
            case 'unpost':
                $todo->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null])->save();
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
            $todo->forceFill(['posted_at' => null, 'post_url' => null, 'posted_by_id' => null])->save();
        }

        return response()->json(['card' => $this->card($todo->fresh()->load('corrections', 'updates', 'contentItem'))]);
    }

    /** The small refresh: pull the Reel Planner from Notion again. */
    public function refresh(ContentSyncService $sync): JsonResponse
    {
        $count = $sync->syncSource(ContentItem::SOURCE_REEL);

        return response()->json(['message' => "Reel Planner refreshed — {$count} items."]);
    }

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
            ->where(fn ($q) => $q->open()->orWhereDate('closed_on', today())->orWhereDate('posted_at', today())
                ->orWhere(fn ($q) => $q->where('status', Todo::STATUS_COMPLETED)->whereNull('posted_at')->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'none'))->whereDate('closed_on', '>=', today()->subDays(3))))
            ->with('corrections', 'updates', 'contentItem')
            ->orderBy('starts_on')->orderBy('id')
            ->get()
            ->map(fn (Todo $t) => $this->card($t))
            ->values()
            ->all();
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
            'post_url' => $t->post_url,
            'working_minutes' => $t->workingMinutes(),
            'working_since' => $t->status === Todo::STATUS_STARTED ? optional($t->updates->last(fn ($u) => $u->to_status === Todo::STATUS_STARTED))->created_at?->getTimestampMs() : null,
            'sent_back' => $t->wasSentBack() ? $t->review_note : null,
            'corrections' => $t->corrections->map(fn (Todo $c) => ['id' => $c->id, 'title' => $c->title, 'status' => $c->status])->values()->all(),
        ];
    }

    /**
     * The editor's own upcoming Reel Planner items not on their board yet:
     * the publishing calendar, read as "what to edit next".
     */
    private function suggestions(User $user): array
    {
        $first = strtok($user->name, ' ');
        $taken = Todo::query()->where('user_id', $user->id)->whereNotNull('content_item_id')->pluck('content_item_id');

        return ContentItem::query()
            ->whereIn('status', self::TO_EDIT)
            ->where(fn ($q) => $q->where('assigned_user_id', $user->id)->orWhere('editor', 'like', "%{$first}%"))
            ->where(fn ($q) => $q->whereNull('published_date')->orWhereBetween('published_date', [today()->subDays(3), today()->addDays(14)]))
            ->whereNotIn('id', $taken)
            ->orderByRaw('published_date is null')->orderBy('published_date')
            ->limit(30)
            ->get()
            ->map(fn (ContentItem $i) => [
                'id' => $i->id,
                'title' => trim($i->title),
                'client' => $i->venture,
                'status' => $i->status,
                'source' => $i->source,
                'publishes' => $i->published_date ? ($i->published_date->isToday() ? 'Today' : ($i->published_date->isTomorrow() ? 'Tomorrow' : $i->published_date->format('D j M'))) : 'No date',
                'late' => $i->published_date?->lt(today()) ?? false,
            ])
            ->values()
            ->all();
    }

    /**
     * Hints from what this editor usually does: their most frequent clients
     * (as the prefix they write), their recent titles for autocomplete, and
     * how many videos a day they usually get through.
     */
    private function hints(User $user): array
    {
        $recent = Todo::query()->where('user_id', $user->id)->whereNull('parent_id')
            ->whereDate('starts_on', '>=', today()->subDays(45))->get(['title', 'starts_on', 'status']);
        $prefixes = $recent->map(fn ($t) => ClientGuess::prefix($t->title))->filter()
            ->countBy()->sortDesc()->keys()->take(6)->values()->all();
        $doneByDay = $recent->where('status', Todo::STATUS_COMPLETED)->groupBy(fn ($t) => $t->starts_on?->toDateString())->map->count();

        return [
            'clients' => $prefixes,
            'titles' => $recent->pluck('title')->unique()->take(60)->values()->all(),
            'per_day' => $doneByDay->isEmpty() ? null : (int) round($doneByDay->avg()),
        ];
    }
}
