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
        'assignedUser:id,name',
        'notionShoot:id,title,shoot_date',
        'editingTodos:id,content_item_id,user_id,status,posted_at',
        'editingTodos.user:id,name',
        'lastChange.user:id,name',
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
            'assigned_user' => $i->assignedUser ? ['id' => $i->assignedUser->id, 'name' => $i->assignedUser->name] : null,
            'tier' => $i->tier,
            'post_type' => $i->post_type,
            'shoot' => $i->notionShoot ? ['title' => $i->notionShoot->title, 'date' => $i->notionShoot->shoot_date?->toDateString()] : null,
            'editing' => $i->editingTodos->map(fn (Todo $t) => [
                'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
                'status' => $t->posted_at ? 'posted' : $t->status,
            ])->values()->all(),
            'has_script' => filled($i->script),
            'notion_url' => $i->notion_url,
            'missing_from_notion' => $i->notion_missing_since !== null,
            'last_change' => $i->lastChange ? self::change($i->lastChange) : null,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(ContentItem $i): array
    {
        $i->loadMissing(['changes' => fn ($q) => $q->limit(50), 'changes.user:id,name', 'scriptRecord:id,content_item_id']);

        return self::row($i) + [
            'script' => $i->script,
            'show_notes' => $i->show_notes,
            'ssd' => $i->ssd,
            'effort_hours' => $i->effort_hours,
            'insta_csv_link' => $i->insta_csv_link,
            'yt_csv_link' => $i->yt_csv_link,
            'notion_created_at' => $i->notion_created_at?->toIso8601String(),
            'portal_script_url' => $i->scriptRecord ? route('scripts.show', $i->scriptRecord->id) : null,
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
