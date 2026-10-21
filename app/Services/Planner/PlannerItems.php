<?php

namespace App\Services\Planner;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\Todo;

/**
 * How a planner item looks to Chakra Planner. One shape for the list, the
 * live events and the detail panel (which adds the heavy fields), so the app
 * can drop any of them straight into its local cache.
 *
 * Documented field by field in docs/chakra-planner/API.md -- change both.
 */
class PlannerItems
{
    /** Eager loads row() needs, so a list of two thousand is a handful of queries. */
    public const RELATIONS = [
        'assignedUser:id,name,avatar_path',
        'notionShoot:id,title,shoot_date',
        'editingTodos:id,content_item_id,user_id,status,posted_at',
        'editingTodos.user:id,name,avatar_path',
        'lastChange.user:id,name',
        'scriptRecord:id,content_item_id,title,status,shot_at',
        'video:id,content_item_id,short_content_item_id,posted_as',
        'video.shortItem:id,title,published_date,notion_url',
    ];

    /** @return array<string, mixed> */
    public static function row(ContentItem $i): array
    {
        return [
            'id' => $i->id,
            'source' => $i->source,
            'title' => $i->title,
            'client' => $i->venture,
            'status' => $i->status,
            'status_color' => PlannerSchema::color($i->source, $i->status),
            'published_date' => $i->published_date?->toDateString(),
            'shoot_date' => $i->shoot_date?->toDateString(),
            'editor' => $i->editor,
            'assigned_user' => \App\Support\PlannerVisuals::person($i->assignedUser),
            'client_id' => \App\Support\PlannerVisuals::client($i->venture)['id'] ?? null,
            'client_logo_url' => \App\Support\PlannerVisuals::client($i->venture)['logo_url'] ?? null,
            'tier' => $i->tier,
            'post_type' => $i->post_type,
            'shoot' => $i->notionShoot ? ['title' => $i->notionShoot->title, 'date' => $i->notionShoot->shoot_date?->toDateString()] : null,
            'editing' => $i->editingTodos->map(fn (Todo $t) => [
                'user' => \App\Support\PlannerVisuals::person($t->user),
                'status' => $t->posted_at ? 'posted' : $t->status,
            ])->values()->all(),
            // A script written in Notion, or one written in the portal and linked to this row.
            'has_script' => filled($i->script) || ($i->relationLoaded('scriptRecord') && $i->scriptRecord !== null),
            'portal_script' => $i->relationLoaded('scriptRecord') && $i->scriptRecord ? [
                'id' => $i->scriptRecord->id,
                'title' => $i->scriptRecord->title,
                'status' => $i->scriptRecord->status === \App\Models\Script::STATUS_COMPLETED ? 'Approved' : $i->scriptRecord->statusLabel(),
                'url' => route('scripts.show', $i->scriptRecord->id),
            ] : null,
            // From the Reel and Shorts planners; null/false when the relations were not loaded.
            'script_shot' => $i->relationLoaded('scriptRecord') ? $i->scriptRecord?->shot_at !== null : null,
            'posted_as' => $i->relationLoaded('video') ? $i->video?->posted_as : null,
            'short' => $i->relationLoaded('video') && $i->video?->shortItem ? [
                'title' => $i->video->shortItem->title,
                'date' => $i->video->shortItem->published_date?->toDateString(),
                'notion_url' => $i->video->shortItem->notion_url,
            ] : null,
            'notion_url' => $i->notion_url,
            'missing_from_notion' => $i->notion_missing_since !== null,
            'last_change' => $i->lastChange ? self::change($i->lastChange) : null,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(ContentItem $i): array
    {
        $i->loadMissing(['changes' => fn ($q) => $q->limit(50), 'changes.user:id,name', 'scriptRecord:id,content_item_id,title,status,shot_at', 'scriptRecord.sections']);

        return self::row($i) + [
            'script' => $i->script,
            'show_notes' => $i->show_notes,
            'ssd' => $i->ssd,
            'effort_hours' => $i->effort_hours,
            'insta_csv_link' => $i->insta_csv_link,
            'yt_csv_link' => $i->yt_csv_link,
            'notion_created_at' => $i->notion_created_at?->toIso8601String(),
            'portal_script_url' => $i->scriptRecord ? route('scripts.show', $i->scriptRecord->id) : null,
            // The portal script's words, part by part, for reading in the planner.
            'portal_script_text' => $i->scriptRecord
                ? $i->scriptRecord->sections->reject->isEmpty()->map(fn ($sec) => ['heading' => $sec->heading, 'text' => trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $sec->body))))])->values()->all()
                : null,
            'history' => $i->changes->map(fn (ContentItemChange $c) => self::change($c))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function change(ContentItemChange $c): array
    {
        return [
            'field' => $c->field,
            'from' => $c->from_value,
            'to' => $c->to_value,
            'origin' => $c->origin,
            'by' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
            'at' => $c->created_at?->toIso8601String(),
        ];
    }
}
