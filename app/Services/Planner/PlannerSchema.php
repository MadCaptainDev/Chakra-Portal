<?php

namespace App\Services\Planner;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Each planner's Status options and their Notion colours, read from the
 * databases themselves so the app's chips match Notion exactly -- the five
 * planners do not share one vocabulary (the Reel planner alone has "Hold"
 * and "Delivered"; Post (1) uses a select where Post uses a status).
 *
 * Cached for six hours and dropped on every sync. Falls back to the board
 * colours in config/notion.php when Notion cannot be reached or no key is
 * saved, so the app always gets something to colour with.
 */
class PlannerSchema
{
    private const CACHE_KEY = 'planner.schema.v2';

    /** Notion's option colours, which the app maps to its own palette. */
    public const COLORS = ['default', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];

    /** @var array{statuses: array<string, mixed>, fields: array<string, mixed>}|null */
    private static ?array $memo = null;

    /**
     * What the planner can edit, as planner field => the Notion property key
     * in config('notion.properties'). "title" is Notion's title property.
     */
    public const FIELDS = [
        'title' => 'title',
        'status' => 'status',
        'published_date' => 'published_date',
        'client' => 'venture',
        'editor' => 'editor',
        'tier' => 'tier',
        'ssd' => 'ssd',
        'post_type' => 'post_type',
        'effort_hours' => 'effort_hours',
        'script' => 'script',
        'show_notes' => 'show_notes',
    ];

    /** Notion property types the planner knows how to write. */
    public const WRITABLE = ['title', 'rich_text', 'select', 'status', 'multi_select', 'date', 'number', 'url'];

    /** @return array<string, list<array{name: string, color: string}>> source => options in Notion's order */
    public static function statuses(): array
    {
        return self::schema()['statuses'];
    }

    /**
     * Each planner's editable fields: the Notion type and, for selects, the
     * options with their colours. A field a planner does not have is absent.
     *
     * @return array<string, array<string, array{type: string, options: list<array{name: string, color: string}>}>>
     */
    public static function fields(): array
    {
        return self::schema()['fields'];
    }

    /** @return array{statuses: array<string, mixed>, fields: array<string, mixed>} */
    private static function schema(): array
    {
        return self::$memo ??= Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => self::read());
    }

    public static function color(?string $source, ?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        foreach (self::statuses()[$source] ?? [] as $option) {
            if ($option['name'] === $status) {
                return $option['color'];
            }
        }

        return 'default';
    }

    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Read it from Notion now and keep it, so no page load has to. Called at
     * the end of every sync.
     */
    public static function warm(): void
    {
        self::forget();
        rescue(fn () => self::schema(), null, false);
    }

    /** @return array{statuses: array<string, mixed>, fields: array<string, mixed>} */
    private static function read(): array
    {
        $fallback = collect(config('notion.boards.reel', []))
            ->map(fn (array $o) => ['name' => $o['status'], 'color' => $o['color']])->values()->all();
        $key = NotionSetting::query()->whereKey(1)->first()?->api_key;
        $sync = app(\App\Services\Notion\ContentSyncService::class);
        $statuses = $fields = [];

        foreach (self::sources() as $source) {
            $fields[$source] = [];
            foreach ($key ? config("notion.databases.{$source}.ids", []) : [] as $databaseId) {
                try {
                    $properties = Http::withToken($key)
                        ->withHeaders(['Notion-Version' => '2022-06-28'])
                        ->timeout(10)
                        ->get('https://api.notion.com/v1/databases/'.$databaseId)
                        ->throw()
                        ->json('properties', []);
                } catch (Throwable) {
                    continue;
                }

                foreach (self::FIELDS as $field => $propertyKey) {
                    $found = $sync->namedProperty($properties, $propertyKey);
                    $type = $found['property']['type'] ?? null;
                    if ($found === null || ! in_array($type, self::WRITABLE, true)) {
                        continue;
                    }
                    // Two Post databases: the first one's type and colours win.
                    $fields[$source][$field] ??= ['type' => $type, 'options' => []];
                    $known = array_column($fields[$source][$field]['options'], 'name');
                    foreach ($found['property'][$type]['options'] ?? [] as $option) {
                        if (! in_array($option['name'], $known, true)) {
                            $fields[$source][$field]['options'][] = ['name' => $option['name'], 'color' => $option['color'] ?? 'default'];
                        }
                    }
                }
            }
            $statuses[$source] = ($fields[$source]['status']['options'] ?? []) ?: $fallback;
        }

        return ['statuses' => $statuses, 'fields' => $fields];
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return [ContentItem::SOURCE_REEL, ContentItem::SOURCE_POST, ContentItem::SOURCE_STORY, ContentItem::SOURCE_YOUTUBE];
    }
}
