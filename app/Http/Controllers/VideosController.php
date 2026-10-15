<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\SocialInsight;
use App\Models\Todo;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * Videos: every video with its script, shoot, edit, post and numbers.
 *
 * The pages draw their skeleton at once and fetch the data afterwards, so
 * opening one never waits on the queries. See VideoLinker for the links.
 */
class VideosController extends Controller
{
    private const METRICS = ['views', 'reach', 'likes', 'comments', 'shares', 'saved'];

    public function index(): View
    {
        return view('videos.index');
    }

    public function show(Video $video): View
    {
        return view('videos.show', ['video' => $video]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = Video::query()
            ->whereHas('contentItem', fn ($q) => $q->visible())
            ->with(['contentItem', 'client:id,name'])
            ->when($request->string('q')->trim()->isNotEmpty(), fn ($q) => $q->whereHas(
                'contentItem',
                fn ($c) => $c->where('title', 'like', '%'.$request->string('q')->trim().'%'),
            ))
            ->when($request->filled('posted'), fn ($q) => $q->where('posted_as', $request->string('posted')))
            ->when($request->string('flag')->toString() === 'mismatch', fn ($q) => $q
                ->whereIn('posted_as', ['post', 'story', 'delivered'])
                ->whereHas('contentItem', fn ($c) => $c->where('source', ContentItem::SOURCE_REEL)))
            ->when($request->string('flag')->toString() === 'unposted', fn ($q) => $q->whereNull('posted_as'))
            ->orderByDesc(ContentItem::select('published_date')->whereColumn('content_items.id', 'videos.content_item_id'))
            ->orderByDesc('videos.id');

        $page = $query->paginate(30);
        $numbers = SocialInsight::latestPerItem($page->pluck('social_media_item_id')->filter(), ['views']);

        return response()->json([
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'rows' => $page->getCollection()->map(fn (Video $v) => [
                'id' => $v->id,
                'url' => route('videos.show', $v),
                'title' => $v->contentItem->title,
                'client' => $v->client?->name ?? $v->contentItem->venture,
                'planned' => $v->plannedAs(),
                'posted' => $v->posted_as,
                'mismatch' => $v->hasFormatMismatch(),
                'status' => $v->contentItem->status,
                'editor' => $v->contentItem->editor,
                'published' => $v->contentItem->published_date?->format('j M Y'),
                'views' => $v->social_media_item_id ? ($numbers[$v->social_media_item_id]['views'] ?? null) : null,
                'steps' => [
                    'script' => $v->script_id !== null,
                    'shot' => $v->shoot_video_id !== null || $v->shoot_id !== null || $v->contentItem->shoot_date !== null,
                    'edited' => $v->todo_id !== null,
                    'posted' => $v->posted_as !== null,
                ],
            ])->values(),
        ]);
    }

    public function showData(Video $video): JsonResponse
    {
        $video->load(['contentItem', 'client:id,name', 'script', 'shoot', 'shootVideo', 'todo', 'mediaItem']);
        $item = $video->contentItem;
        $script = $video->script;
        $todo = $video->todo;
        $media = $video->mediaItem;
        $fmt = fn ($date) => $date?->format('j M Y');

        $shotAt = $video->shoot?->starts_at ?? $script?->shot_at ?? $item->shoot_date;
        $postedAt = $todo?->posted_at ?? $media?->posted_at ?? $item->published_date;
        $metrics = $video->social_media_item_id
            ? (SocialInsight::latestPerItem([$video->social_media_item_id], self::METRICS)[$video->social_media_item_id] ?? [])
            : [];
        $editor = $todo?->user_id ? User::query()->whereKey($todo->user_id)->value('name') : null;

        return response()->json([
            'title' => $item->title,
            'client' => $video->client?->name ?? $item->venture,
            'status' => $item->status,
            'planned' => $video->plannedAs(),
            'posted' => $video->posted_as,
            'mismatch' => $video->hasFormatMismatch(),
            'steps' => [
                ['key' => 'script', 'label' => 'Script', 'done' => $script !== null,
                    'detail' => $script ? $script->title.' · '.str_replace('_', ' ', $script->status) : 'No script linked',
                    'date' => $fmt($script?->created_at),
                    'url' => $script ? route('scripts.show', $script) : null],
                ['key' => 'shot', 'label' => 'Shot', 'done' => $shotAt !== null || $video->shoot_video_id !== null,
                    'detail' => $video->shoot?->title ?? ($video->shootVideo ? 'Footage recorded' : ($shotAt ? 'Shoot date from Notion' : 'No shoot linked')),
                    'date' => $fmt($shotAt),
                    'url' => $video->shoot ? route('shoots.show', $video->shoot) : null],
                ['key' => 'edit', 'label' => 'Edited', 'done' => $todo !== null && ($todo->status === Todo::STATUS_COMPLETED || $todo->ready_at !== null),
                    'detail' => $todo ? ($editor ?? $item->editor ?? 'Editor not set').' · '.($todo->stage() ?? $todo->status) : ($item->editor ? $item->editor.' · no to-do yet' : 'No editing to-do'),
                    'date' => $fmt($todo?->ready_at),
                    'url' => null],
                ['key' => 'posted', 'label' => 'Posted', 'done' => $video->posted_as !== null,
                    'detail' => $video->posted_as ? 'Posted as '.$video->posted_as : 'Not posted yet',
                    'date' => $fmt($postedAt),
                    'url' => $media?->permalink ?? $todo?->post_url],
            ],
            'metrics' => $metrics === [] ? null : $metrics,
            'has_media' => $media !== null,
            'links' => array_values(array_filter([
                $item->notion_url ? ['label' => 'Open in Notion', 'url' => $item->notion_url] : null,
                $media?->permalink ? ['label' => 'Open on Instagram', 'url' => $media->permalink] : null,
            ])),
            'short' => ['connected' => false],
        ]);
    }
}
