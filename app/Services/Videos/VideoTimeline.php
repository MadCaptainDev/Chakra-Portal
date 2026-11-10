<?php

namespace App\Services\Videos;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\ShootEvent;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\Video;
use Illuminate\Support\Collection;

/**
 * One video's whole story, in order: planned, scripted, shot, footage filed,
 * every editing move and correction, the changes made in the planner, and
 * the post going live.
 *
 * Nothing is stored for this. Each part already keeps its own history
 * (shoot_events, todo_updates, content_item_changes); this reads them side
 * by side so "what happened to this video?" has one answer.
 */
class VideoTimeline
{
    /** @return array{item: ?ContentItem, todo: ?Todo, video: ?Video, events: Collection<int, array<string, mixed>>} */
    public static function forItem(ContentItem $item): array
    {
        $item->loadMissing(['video.shoot', 'video.shootVideo.recordedBy', 'video.mediaItem', 'video.script', 'editingTodos']);
        $todos = $item->editingTodos->whereNull('parent_id');

        return self::build($item, $item->video, $todos);
    }

    /** @return array{item: ?ContentItem, todo: ?Todo, video: ?Video, events: Collection<int, array<string, mixed>>} */
    public static function forTodo(Todo $todo): array
    {
        $card = $todo->parent ?? $todo;
        $item = $card->content_item_id ? ContentItem::find($card->content_item_id) : null;

        if ($item) {
            $item->loadMissing(['video.shoot', 'video.shootVideo.recordedBy', 'video.mediaItem', 'video.script', 'editingTodos']);
            $todos = $item->editingTodos->whereNull('parent_id');
            if (! $todos->contains('id', $card->id)) {
                $todos = $todos->push($card);
            }

            return self::build($item, $item->video, $todos, $card);
        }

        return self::build(null, null, collect([$card]), $card);
    }

    /**
     * @param  Collection<int, Todo>  $todos
     * @return array{item: ?ContentItem, todo: ?Todo, video: ?Video, events: Collection<int, array<string, mixed>>}
     */
    private static function build(?ContentItem $item, ?Video $video, Collection $todos, ?Todo $card = null): array
    {
        $events = collect();
        $add = function ($at, string $stage, string $label, ?string $note = null, ?string $by = null, ?string $url = null) use (&$events) {
            if ($at) {
                $events->push(compact('at', 'stage', 'label', 'note', 'by', 'url'));
            }
        };

        if ($item) {
            $add($item->notion_created_at, 'plan', 'Added to the planner', $item->venture);

            ContentItemChange::query()->where('content_item_id', $item->id)->with('user:id,name')->get()
                ->each(fn (ContentItemChange $c) => $add(
                    $c->created_at, 'plan',
                    match ($c->field) {
                        'status' => 'Status: '.($c->to_value ?: 'cleared'),
                        'published_date' => 'Publish date '.($c->to_value ? 'set to '.\Illuminate\Support\Carbon::parse($c->to_value)->format('j M') : 'cleared'),
                        default => ucfirst(str_replace('_', ' ', $c->field)).': '.($c->to_value ?: 'cleared'),
                    },
                    $c->from_value ? 'was '.$c->from_value : null,
                    $c->user?->name ?? ($c->origin === 'notion' ? 'In Notion' : null),
                ));
        }

        if ($video?->script) {
            $add($video->script->created_at, 'script', 'Script written', $video->script->title, null, route('scripts.show', $video->script->id));
        }

        if ($video?->shoot) {
            $shoot = $video->shoot;
            $shootUrl = route('shoots.show', $shoot);
            $shootEvents = ShootEvent::query()->where('shoot_id', $shoot->id)->with('user:id,name')
                ->whereIn('type', [ShootEvent::STARTED, ShootEvent::WRAPPED, ShootEvent::FOOTAGE_FILED, ShootEvent::CANCELLED])->get();

            foreach ($shootEvents as $e) {
                $add($e->created_at, 'shoot', match ($e->type) {
                    ShootEvent::STARTED => 'Shoot started',
                    ShootEvent::WRAPPED => 'Shoot wrapped',
                    ShootEvent::FOOTAGE_FILED => 'Footage on the drive',
                    default => 'Shoot cancelled',
                }, $e->type === ShootEvent::STARTED ? $shoot->title : $e->note, $e->user?->name, $shootUrl);
            }
        }

        if ($video?->shootVideo) {
            $clip = $video->shootVideo;
            $add($clip->created_at, 'shoot', 'Filmed', $clip->name.($clip->sectionSummary() ? ' · '.$clip->sectionSummary() : ''), $clip->recordedBy?->name);
        }

        $cards = Todo::query()->whereIn('id', $todos->pluck('id'))->orWhereIn('parent_id', $todos->pluck('id'))
            ->with(['updates.user:id,name', 'user:id,name'])->get();

        foreach ($cards as $t) {
            foreach ($t->updates as $u) {
                [$label, $note] = self::editing($t, $u);
                $add($u->created_at, $u->action === TodoUpdate::POSTED || $u->action === TodoUpdate::DELIVERED ? 'post' : 'edit',
                    $label, $note, $u->user?->name ?? $t->user?->name,
                    $u->action === TodoUpdate::POSTED && $t->post_url && str_starts_with($t->post_url, 'http') ? $t->post_url : null);
            }
        }

        if ($video?->mediaItem?->posted_at) {
            $add($video->mediaItem->posted_at, 'post', 'Live on Instagram', null, null, $video->mediaItem->permalink);
        }

        return [
            'item' => $item,
            'todo' => $card ?? $todos->first(),
            'video' => $video,
            'events' => $events->sortBy(fn (array $e) => $e['at']->getTimestamp())->values(),
        ];
    }

    /** @return array{0: string, 1: ?string} label and note for one editing move */
    private static function editing(Todo $t, TodoUpdate $u): array
    {
        $fix = $t->parent_id !== null;

        return match (true) {
            $u->action === TodoUpdate::CREATED => [$fix ? 'Correction asked' : 'Editing card added', $fix ? $t->title : $t->user?->name],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_STARTED => [$fix ? 'Correction started' : ($u->from_status === Todo::STATUS_BLOCKED ? 'Editing resumed' : ($u->from_status === Todo::STATUS_COMPLETED ? 'Back to editing' : 'Editing started')), $u->note],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_BLOCKED => ['On hold', $u->note],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_COMPLETED => [$fix ? 'Correction done' : 'Edit done, sent for review', $u->note],
            $u->action === TodoUpdate::STATUS && $u->to_status === Todo::STATUS_CANCELLED => ['Removed', $u->note],
            $u->action === TodoUpdate::MOVED => ['Moved to '.($u->to_on?->format('j M') ?? 'another day'), $u->note],
            default => [$u->actionLabel(), $u->note],
        };
    }

    /**
     * The shape the planner's side peek reads.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPlanner(ContentItem $item): array
    {
        return self::forItem($item)['events']->map(fn (array $e) => [
            'at' => $e['at']->toIso8601String(),
            'stage' => $e['stage'],
            'label' => $e['label'],
            'note' => $e['note'],
            'by' => $e['by'],
            'url' => $e['url'],
        ])->all();
    }
}
