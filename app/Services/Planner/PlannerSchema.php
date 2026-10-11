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
    private const CACHE_KEY = 'planner.schema.statuses';

    /** Notion's option colours, which the app maps to its own palette. */
    public const COLORS = ['default', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];

    /** @var array<string, list<array{name: string, color: string}>>|null */
    private static ?array $memo = null;

    /** @return array<string, list<array{name: string, color: string}>> source => options in Notion's order */
    public static function statuses(): array
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

    /** @return array<string, list<array{name: string, color: string}>> */
    private static function read(): array
    {
        $fallback = collect(config('notion.boards.reel', []))
            ->map(fn (array $o) => ['name' => $o['status'], 'color' => $o['color']])->values()->all();
        $key = NotionSetting::query()->whereKey(1)->first()?->api_key;
        $out = [];

        foreach (self::sources() as $source) {
            $options = [];
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
                $status = collect($properties)->first(fn ($p, $name) => in_array(trim((string) $name), ['Status', 'Stage', ''], true)
                    && in_array($p['type'] ?? null, ['status', 'select'], true));
                $type = $status['type'] ?? null;
                foreach ($type ? ($status[$type]['options'] ?? []) : [] as $option) {
                    // Two Post databases: the first one's colour wins a shared name.
                    $options[$option['name']] ??= ['name' => $option['name'], 'color' => $option['color'] ?? 'default'];
                }
            }
            $out[$source] = $options !== [] ? array_values($options) : $fallback;
        }

        return $out;
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return [ContentItem::SOURCE_REEL, ContentItem::SOURCE_POST, ContentItem::SOURCE_STORY, ContentItem::SOURCE_YOUTUBE];
    }
}
