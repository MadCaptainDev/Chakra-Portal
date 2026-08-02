<?php

namespace App\Services\Notion;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ContentSyncService
{
    private const API_BASE = 'https://api.notion.com/v1';

    private const API_VERSION = '2022-06-28';

    private const DISCOVERY_CACHE_KEY = 'notion.discovered_databases';

    /**
     * Sync every configured Notion database and return a per-source count.
     *
     * @return array<string, int>
     */
    public function syncAll(bool $fresh = false): array
    {
        if ($fresh) {
            $this->forgetCaches();
        }

        $counts = [];

        foreach (array_keys(config('notion.databases', [])) as $source) {
            $counts[$source] = $this->syncSource($source);
        }

        return $counts;
    }

    /**
     * Sync a single source into content_items.
     * Never throws: failures are logged and counted as zero.
     */
    public function syncSource(string $source): int
    {
        $config = config("notion.databases.{$source}");

        if (! $config || ! $this->apiKey()) {
            return 0;
        }

        $databaseId = $this->resolveDatabaseId($config);

        if (! $databaseId) {
            Log::warning("Notion sync skipped for [{$source}]: no database matching '{$config['name_contains']}' is shared with the integration.");

            return 0;
        }

        $synced = 0;
        $cursor = null;

        try {
            do {
                $payload = array_filter([
                    'page_size' => 100,
                    'start_cursor' => $cursor,
                ]);

                $body = $this->client()
                    ->post(self::API_BASE."/databases/{$databaseId}/query", $payload)
                    ->throw()
                    ->json();

                foreach ($body['results'] ?? [] as $page) {
                    $this->upsertPage($source, $page);
                    $synced++;
                }

                $cursor = ($body['has_more'] ?? false) ? ($body['next_cursor'] ?? null) : null;
            } while ($cursor);
        } catch (Throwable $e) {
            Log::warning("Notion sync failed for source [{$source}]: {$e->getMessage()}");

            return $synced;
        }

        return $synced;
    }

    /**
     * Distinct Venture options across every reachable database, for the
     * client-form dropdown.
     *
     * @return array<int, string>
     */
    public function ventureOptions(): array
    {
        if (! $this->apiKey()) {
            return [];
        }

        return Cache::remember('notion.venture_options', config('notion.cache_ttl', 86400), function () {
            $options = [];

            foreach (config('notion.databases', []) as $config) {
                $databaseId = $this->resolveDatabaseId($config);

                if (! $databaseId) {
                    continue;
                }

                try {
                    $properties = $this->client()
                        ->get(self::API_BASE."/databases/{$databaseId}")
                        ->throw()
                        ->json('properties', []);

                    $property = $this->findProperty($properties, config('notion.properties.venture', []));

                    foreach ($property['select']['options'] ?? [] as $option) {
                        if (! empty($option['name'])) {
                            $options[$option['name']] = true;
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning("Notion venture-options fetch failed for [{$config['label']}]: {$e->getMessage()}");
                }
            }

            $names = array_keys($options);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);

            return $names;
        });
    }

    /**
     * Titles of every database the integration can actually see. Surfaced in
     * Settings so an unshared database is obvious rather than a silent zero.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function discoverDatabases(): array
    {
        if (! $this->apiKey()) {
            return [];
        }

        return Cache::remember(self::DISCOVERY_CACHE_KEY, config('notion.cache_ttl', 86400), function () {
            $databases = [];
            $cursor = null;

            try {
                do {
                    $body = $this->client()
                        ->post(self::API_BASE.'/search', array_filter([
                            'filter' => ['property' => 'object', 'value' => 'database'],
                            'page_size' => 100,
                            'start_cursor' => $cursor,
                        ]))
                        ->throw()
                        ->json();

                    foreach ($body['results'] ?? [] as $database) {
                        $databases[] = [
                            'id' => $database['id'],
                            'title' => collect($database['title'] ?? [])->pluck('plain_text')->implode(''),
                        ];
                    }

                    $cursor = ($body['has_more'] ?? false) ? ($body['next_cursor'] ?? null) : null;
                } while ($cursor);
            } catch (Throwable $e) {
                Log::warning("Notion database discovery failed: {$e->getMessage()}");
            }

            return $databases;
        });
    }

    /**
     * Which configured sources are reachable right now, keyed by source.
     *
     * @return array<string, bool>
     */
    public function sourceAvailability(): array
    {
        $available = [];

        foreach (config('notion.databases', []) as $source => $config) {
            $available[$source] = $this->resolveDatabaseId($config) !== null;
        }

        return $available;
    }

    public function forgetCaches(): void
    {
        Cache::forget(self::DISCOVERY_CACHE_KEY);
        Cache::forget('notion.venture_options');
    }

    /**
     * Prefer a live title match over the configured id, since ids change when
     * a database is duplicated and a stale one 404s silently.
     */
    private function resolveDatabaseId(array $config): ?string
    {
        $needle = Str::lower($config['name_contains'] ?? '');

        if ($needle !== '') {
            foreach ($this->discoverDatabases() as $database) {
                if (Str::contains(Str::lower($database['title']), $needle)) {
                    return $database['id'];
                }
            }
        }

        // Nothing matched by name. Only trust the configured id if the
        // integration can see nothing at all (e.g. the search call failed) --
        // otherwise a successful search proves the database is not shared.
        return $this->discoverDatabases() === [] ? ($config['id'] ?? null) : null;
    }

    private function upsertPage(string $source, array $page): void
    {
        $properties = $page['properties'] ?? [];
        $names = config('notion.properties', []);

        ContentItem::updateOrCreate(
            ['notion_page_id' => $page['id']],
            [
                'source' => $source,
                'notion_url' => $page['url'] ?? null,
                'title' => $this->extractTitle($properties),
                'venture' => $this->value($properties, $names['venture'] ?? []),
                'status' => $this->value($properties, $names['status'] ?? []),
                'published_date' => $this->value($properties, $names['published_date'] ?? []),
                'shoot_date' => $this->value($properties, $names['shoot_date'] ?? []),
                'editor' => $this->value($properties, $names['editor'] ?? []),
                'tier' => $this->value($properties, $names['tier'] ?? []),
                'post_type' => $this->value($properties, $names['post_type'] ?? []),
                'ssd' => $this->value($properties, $names['ssd'] ?? []),
                'assigned_to' => $this->value($properties, $names['assigned_to'] ?? []),
                'effort_hours' => $this->value($properties, $names['effort_hours'] ?? []),
                'insta_csv_link' => $this->value($properties, $names['insta_csv_link'] ?? []),
                'yt_csv_link' => $this->value($properties, $names['yt_csv_link'] ?? []),
                'script' => $this->value($properties, $names['script'] ?? []),
                'show_notes' => $this->value($properties, $names['show_notes'] ?? []),
                'notion_created_at' => $page['created_time'] ?? null,
                'synced_at' => now(),
            ]
        );
    }

    /**
     * The title property is found by type, not name, so it works on any
     * database regardless of what the column is called.
     */
    private function extractTitle(array $properties): ?string
    {
        foreach ($properties as $property) {
            if (($property['type'] ?? null) === 'title') {
                $text = collect($property['title'] ?? [])->pluck('plain_text')->implode('');

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    /**
     * Read a property as a plain string, branching on the type Notion
     * reports rather than a type we assumed at config time.
     *
     * @param  array<int, string>  $candidates
     */
    private function value(array $properties, array $candidates): ?string
    {
        $property = $this->findProperty($properties, $candidates);

        if (! $property) {
            return null;
        }

        $value = match ($property['type'] ?? null) {
            'title' => collect($property['title'] ?? [])->pluck('plain_text')->implode(''),
            'rich_text' => collect($property['rich_text'] ?? [])->pluck('plain_text')->implode(''),
            'select' => $property['select']['name'] ?? '',
            'status' => $property['status']['name'] ?? '',
            'multi_select' => collect($property['multi_select'] ?? [])->pluck('name')->implode(', '),
            'people' => collect($property['people'] ?? [])->pluck('name')->filter()->implode(', '),
            'date' => $property['date']['start'] ?? '',
            'url' => $property['url'] ?? '',
            'email' => $property['email'] ?? '',
            'phone_number' => $property['phone_number'] ?? '',
            'number' => isset($property['number']) ? (string) $property['number'] : '',
            'checkbox' => ! empty($property['checkbox']) ? 'Yes' : 'No',
            'formula' => (string) ($property['formula']['string'] ?? $property['formula']['number'] ?? ''),
            default => '',
        };

        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : $value;
    }

    /**
     * Exact name match first, then trimmed/case-insensitive — the planners
     * really do have properties named "Editor " and "".
     *
     * @param  array<int, string>  $candidates
     */
    private function findProperty(array $properties, array $candidates): ?array
    {
        foreach ($candidates as $name) {
            if (array_key_exists($name, $properties)) {
                return $properties[$name];
            }
        }

        $normalized = [];

        foreach ($properties as $key => $property) {
            $normalized[Str::lower(trim($key))] = $property;
        }

        foreach ($candidates as $name) {
            $key = Str::lower(trim($name));

            if (array_key_exists($key, $normalized)) {
                return $normalized[$key];
            }
        }

        return null;
    }

    private function apiKey(): ?string
    {
        return config('notion.api_key');
    }

    private function client()
    {
        return Http::withToken($this->apiKey())
            ->withHeaders(['Notion-Version' => self::API_VERSION])
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 300, throw: false);
    }
}
