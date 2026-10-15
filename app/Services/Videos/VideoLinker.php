<?php

namespace App\Services\Videos;

use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootVideo;
use App\Models\SocialMediaItem;
use App\Models\Todo;
use App\Models\Video;

/**
 * Keeps one Video per Notion content item and fills in what it is linked to.
 *
 * Additive: a link that is found is set, one that is not found is left as it
 * was, so a link made by hand survives and a Notion hiccup unlinks nothing.
 * Nothing here writes to the content item or to Notion.
 */
class VideoLinker
{
    /**
     * @return array{videos: int, created: int, updated: int}
     */
    public function run(): array
    {
        $ventureToClient = ContentAccountVenture::query()
            ->with('contentAccount')
            ->get()
            ->mapWithKeys(fn (ContentAccountVenture $row) => [$row->venture => $row->contentAccount?->client_id])
            ->filter();

        $scripts = Script::query()->whereNotNull('content_item_id')->orderBy('id')
            ->get(['id', 'content_item_id', 'client_id'])->unique('content_item_id')->keyBy('content_item_id');

        $shoots = Shoot::query()->whereNotNull('notion_shoot_id')->pluck('id', 'notion_shoot_id');

        $footage = ShootVideo::query()->whereNotNull('script_id')->orderBy('id')
            ->get(['id', 'script_id'])->unique('script_id')->keyBy('script_id');

        $todos = Todo::query()->whereNotNull('content_item_id')->whereNull('parent_id')->orderBy('id')
            ->get(['id', 'content_item_id', 'kind', 'posted_at'])->groupBy('content_item_id')
            ->map(fn ($rows) => $rows->firstWhere(fn (Todo $t) => $t->posted_at !== null) ?? $rows->first());

        $media = SocialMediaItem::query()->get(['id', 'media_product_type'])->keyBy('id');

        $created = $updated = $total = 0;

        ContentItem::query()->visible()->with('video')->chunkById(300, function ($items) use (
            $ventureToClient, $scripts, $shoots, $footage, $todos, $media, &$created, &$updated, &$total,
        ) {
            foreach ($items as $item) {
                $total++;
                $video = $item->video ?? new Video(['content_item_id' => $item->id]);
                $script = $scripts->get($item->id);
                $todo = $todos->get($item->id);
                $mediaItem = $item->social_media_item_id ? $media->get($item->social_media_item_id) : null;

                $found = [
                    'client_id' => $ventureToClient[$item->venture] ?? $script?->client_id,
                    'script_id' => $script?->id,
                    'shoot_id' => $item->notion_shoot_id ? ($shoots[$item->notion_shoot_id] ?? null) : null,
                    'shoot_video_id' => $script ? $footage->get($script->id)?->id : null,
                    'todo_id' => $todo?->id,
                    'social_media_item_id' => $item->social_media_item_id,
                    'posted_as' => $this->postedAs($todo, $mediaItem),
                ];

                $video->fill(array_filter($found, fn ($value) => $value !== null));

                if (! $video->exists) {
                    $video->save();
                    $created++;
                } elseif ($video->isDirty()) {
                    $video->save();
                    $updated++;
                }
            }
        });

        return ['videos' => $total, 'created' => $created, 'updated' => $updated];
    }

    /**
     * What the video really went out as. The editor's own post is the first
     * word; failing that, the Instagram post it was matched to.
     */
    private function postedAs(?Todo $todo, ?SocialMediaItem $media): ?string
    {
        if ($todo && $todo->posted_at !== null && array_key_exists($todo->kind, Todo::KINDS)) {
            return $todo->kind;
        }

        if ($media === null) {
            return null;
        }

        return match (true) {
            $media->isReel() => 'reel',
            $media->media_product_type === 'STORY' => 'story',
            default => 'post',
        };
    }
}
