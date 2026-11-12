<?php

namespace App\Services\Planner;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\User;
use App\Services\Notion\ContentSyncService;
use Throwable;

/**
 * Editing a planner item from Chakra Planner the way Notion lets you: its
 * title, status, client, editor, tier, drive, script and so on -- and
 * creating a new one.
 *
 * The same rule as PublishDateMove, for the same reason: Notion is the
 * master copy, so every change is written THERE first and the portal row is
 * rebuilt from the page Notion hands back. If Notion will not take it,
 * nothing changes and the person is told why.
 *
 * The planners are not alike -- Post uses a status property where Post (1)
 * uses a select, the Reel planner's Editor is a multi-select, one status
 * property is literally named "" -- so nothing here assumes a type. The page
 * is read first and each value is written in the shape that page's own
 * property has.
 */
class PlannerPropertyWrite
{
    public function __construct(private ContentSyncService $notion) {}

    /**
     * @param  array<string, mixed>  $changes  planner field => new value (see PlannerSchema::FIELDS)
     * @param  array<string, mixed>  $expected  planner field => the value the app was showing; a mismatch is a conflict
     */
    public function update(ContentItem $item, array $changes, array $expected, User $actor): ContentItem
    {
        $changes = array_intersect_key($changes, PlannerSchema::FIELDS);
        if ($changes === []) {
            return $item;
        }

        $this->guard();
        if ($item->notion_missing_since) {
            throw new NotionWriteFailed('This item is no longer in Notion, so it cannot be changed.');
        }
        if (($changes['status'] ?? null) === 'Video Ready' && $item->status !== 'Video Ready'
            && ($why = \App\Support\ReviewGate::blocksPlannerReady($item))) {
            throw new NotionWriteFailed($why);
        }

        try {
            $page = $this->notion->fetchPage($item->notion_page_id);
        } catch (Throwable $e) {
            throw new NotionWriteFailed(PublishDateMove::reason($e));
        }

        $properties = [];
        $before = [];

        foreach ($changes as $field => $value) {
            $found = $this->notion->namedProperty($page['properties'] ?? [], PlannerSchema::FIELDS[$field]);
            if ($found === null) {
                throw new NotionWriteFailed('This planner in Notion has no "'.$this->label($field).'" property.');
            }

            $current = $this->comparable($field, $this->notion->plain($found['property']));
            if (array_key_exists($field, $expected) && $current !== $this->comparable($field, $expected[$field])) {
                $fresh = $this->notion->applyPage($page) ?? $item;
                throw new PlannerConflict($fresh, $this->label($field).' was changed in Notion to '.($current ?? 'nothing').'.');
            }
            if ($current === $this->comparable($field, $value)) {
                continue;
            }

            $before[$field] = $current;
            $properties[$found['name']] = $this->encode($field, $found['property'], $value);
        }

        if ($properties === []) {
            return $this->notion->applyPage($page) ?? $item;
        }

        try {
            $page = $this->notion->updatePage($item->notion_page_id, $properties);
        } catch (Throwable $e) {
            throw new NotionWriteFailed(PublishDateMove::reason($e));
        }

        $fresh = $this->notion->applyPage($page) ?? $item;
        foreach ($before as $field => $was) {
            ContentItemChange::record($fresh, $actor, $field, $was, $this->comparable($field, $changes[$field]), ContentItemChange::ORIGIN_PLANNER);
        }

        return $fresh;
    }

    /**
     * A new item in one of the planners.
     *
     * @param  array<string, mixed>  $values  planner field => value; title is required
     */
    public function create(string $source, array $values, User $actor): ContentItem
    {
        $this->guard();

        $databaseId = config("notion.databases.{$source}.ids.0");
        if (! in_array($source, PlannerSchema::sources(), true) || ! $databaseId) {
            throw new NotionWriteFailed('There is no such planner.');
        }

        try {
            $schema = $this->notion->databaseProperties($databaseId);
        } catch (Throwable $e) {
            throw new NotionWriteFailed(PublishDateMove::reason($e));
        }

        $properties = [];
        foreach (array_intersect_key($values, PlannerSchema::FIELDS) as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $found = $this->notion->namedProperty($schema, PlannerSchema::FIELDS[$field]);
            if ($found === null) {
                continue; // This planner has no such property: nothing to set.
            }
            $properties[$found['name']] = $this->encode($field, $found['property'], $value);
        }

        try {
            $page = $this->notion->createPage($databaseId, $properties);
        } catch (Throwable $e) {
            throw new NotionWriteFailed(PublishDateMove::reason($e));
        }

        $item = $this->notion->applyPage($page, $source)
            ?? throw new NotionWriteFailed('Notion made the page but the planner could not read it back. Refresh from Notion.');

        ContentItemChange::record($item, $actor, 'created', null, $item->title, ContentItemChange::ORIGIN_PLANNER);

        return $item;
    }

    private function guard(): void
    {
        if (! $this->notion->isConfigured()) {
            throw new NotionWriteFailed('Notion is not connected (Setup → Notion), so the planner cannot be changed.');
        }
    }

    /**
     * The value in the shape this property's Notion type takes.
     *
     * @param  array<string, mixed>  $property  the property as the page (or the database schema) has it
     * @return array<string, mixed>
     */
    private function encode(string $field, array $property, mixed $value): array
    {
        $type = $property['type'] ?? null;
        $text = is_array($value) ? implode(', ', $value) : trim((string) ($value ?? ''));

        return match ($type) {
            'title' => ['title' => $text === ''
                ? throw new NotionWriteFailed('A video needs a title.')
                : [['type' => 'text', 'text' => ['content' => mb_substr($text, 0, 2000)]]]],
            // Notion caps one rich-text piece at 2000 characters; a script is longer.
            'rich_text' => ['rich_text' => array_map(
                fn (string $piece) => ['type' => 'text', 'text' => ['content' => $piece]],
                $text === '' ? [] : mb_str_split($text, 2000),
            )],
            'select' => ['select' => $text === '' ? null : ['name' => $text]],
            'status' => ['status' => $text === ''
                ? throw new NotionWriteFailed($this->label($field).' cannot be empty in this planner.')
                : ['name' => $text]],
            'multi_select' => ['multi_select' => array_map(
                fn (string $name) => ['name' => $name],
                $this->names($value),
            )],
            'date' => ['date' => $text === '' ? null : PublishDateMove::dateValue($property['date'] ?? null, substr($text, 0, 10))],
            'number' => ['number' => $text === '' ? null : (is_numeric($text) ? $text + 0 : throw new NotionWriteFailed($this->label($field).' has to be a number.'))],
            'url' => ['url' => $text === '' ? null : $text],
            default => throw new NotionWriteFailed($this->label($field).' is a "'.($type ?? 'unknown').'" property in Notion, which the planner cannot change. Edit it in Notion.'),
        };
    }

    /** @return list<string> */
    private function names(mixed $value): array
    {
        $names = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $names), 'strlen')));
    }

    /** Two values that mean the same compare the same: "" and null, a date with or without a time, name lists in any spacing. */
    private function comparable(string $field, mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', $this->names($value));
        }
        $value = $value === null ? null : trim((string) $value);
        if ($value === null || $value === '') {
            return null;
        }
        if ($field === 'published_date') {
            return substr($value, 0, 10);
        }
        if ($field === 'editor') {
            return implode(', ', $this->names($value));
        }

        return $value;
    }

    private function label(string $field): string
    {
        return ['client' => 'Client', 'ssd' => 'Drive', 'published_date' => 'Publish date'][$field]
            ?? ucfirst(str_replace('_', ' ', $field));
    }
}
