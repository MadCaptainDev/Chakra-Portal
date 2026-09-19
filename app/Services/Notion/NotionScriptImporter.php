<?php

namespace App\Services\Notion;

use App\Models\ContentItem;
use App\Models\Script;
use Illuminate\Support\Facades\DB;

/**
 * Turning Notion's free-text "Script" property into a script somebody can
 * actually work on.
 *
 * Two different things share the word. `content_items.script` is a flat
 * string the content sync copies out of Notion; App\Models\Script is the
 * portal's reviewable document, with sections, a writer, a status and
 * comments. Until this class the two never met -- 206 real scripts sat in
 * the first while the Scripts module was empty, and a writer opening
 * "+ Write script" on a reel got three blank boxes beside a script that was
 * already written.
 *
 * Both routes in (a person clicking through, and the bulk import) build the
 * sections here so they cannot drift.
 */
class NotionScriptImporter
{
    /** What a new script opens on -- see ScriptController::store(). */
    public const DEFAULT_HEADINGS = ['Hook', 'Body', 'CTA'];

    /** The section Notion's text lands in. */
    private const SEED_HEADING = 'Body';

    /**
     * The sections a new script should start with, with Notion's text
     * already in the body when there is any.
     *
     * Everything still arrives as ordinary sections: the writer renames,
     * reorders and deletes them exactly as they would on a script typed
     * from nothing. This only saves the retyping.
     *
     * @return list<array{heading: string, body: string|null}>
     */
    public static function sectionsFor(?ContentItem $item): array
    {
        $seed = self::asParagraphs((string) $item?->script);

        return array_map(fn (string $heading) => [
            'heading' => $heading,
            'body' => $heading === self::SEED_HEADING && $seed !== '' ? $seed : null,
        ], self::DEFAULT_HEADINGS);
    }

    /**
     * Notion's plain text as the paragraphs the script editor expects.
     *
     * `body` is a rich-text column: ScriptSection's mutator runs everything
     * through App\Support\Html's allowlist, which keeps <p> and <br> and
     * nothing else useful here. Storing the raw text instead would survive
     * that untouched and then render as one unbroken wall -- every line
     * break in a script that is mostly short numbered lines would be lost
     * exactly where it matters.
     *
     * Escaped line by line before the tags go on, so a "<" somebody typed
     * stays a "<" instead of being read as markup and swallowed.
     */
    private static function asParagraphs(string $text): string
    {
        return collect(preg_split('/\R/', trim($text)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->map(fn (string $line) => '<p>'.e($line).'</p>')
            ->implode('');
    }

    /** Write those sections against a script that has none yet. */
    public static function seedSections(Script $script, ?ContentItem $item): void
    {
        foreach (self::sectionsFor($item) as $position => $section) {
            // position is not fillable -- the reorder action owns it.
            $row = $script->sections()->make($section);
            $row->position = $position;
            $row->save();
        }
    }

    /**
     * Create a portal Script for every Notion item carrying script text that
     * does not have one yet.
     *
     * Skips rather than updates anything that already exists. A Script is a
     * human work product the moment it is created -- somebody may have
     * rewritten the body, split the sections, or left comments on it -- and
     * silently overwriting that with Notion's older text would destroy work
     * no backup would obviously restore. Notion is the source of the first
     * draft here, not the running truth.
     *
     * @return array{created: int, unmatched_client: int}
     */
    public static function importMissing(?int $limit = null): array
    {
        $query = ContentItem::query()
            ->whereNotNull('script')
            ->where('script', '!=', '')
            ->whereDoesntHave('scriptRecord')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return ['created' => 0, 'unmatched_client' => 0];
        }

        $clientByVenture = self::clientIdsByVenture();

        $created = 0;
        $unmatched = 0;

        foreach ($items as $item) {
            $clientId = $item->venture ? ($clientByVenture[$item->venture] ?? null) : null;

            if ($clientId === null) {
                // Imported anyway: a script with no client is still a script,
                // and the venture map (Setup -> Content Accounts) is only
                // about a fifth complete. Counted so the operator sees it.
                $unmatched++;
            }

            DB::transaction(function () use ($item, $clientId, &$created) {
                $script = Script::create([
                    'content_item_id' => $item->id,
                    'client_id' => $clientId,
                    'title' => $item->title ?: 'Untitled script',
                    'status' => Script::STATUS_DRAFT,
                    'priority' => Script::PRIORITY_NORMAL,
                ]);

                self::seedSections($script, $item);
                $created++;
            });
        }

        return ['created' => $created, 'unmatched_client' => $unmatched];
    }

    /** How many are waiting, without importing them. */
    public static function pendingCount(): int
    {
        return ContentItem::query()
            ->whereNotNull('script')
            ->where('script', '!=', '')
            ->whereDoesntHave('scriptRecord')
            ->count();
    }

    /**
     * venture string -> client id, read once.
     *
     * The same mapping ScriptController::create() does with a query per
     * item; one map here instead of 206 lookups.
     *
     * @return array<string, int>
     */
    private static function clientIdsByVenture(): array
    {
        return DB::table('content_account_ventures')
            ->join('content_accounts', 'content_accounts.id', '=', 'content_account_ventures.content_account_id')
            ->whereNotNull('content_accounts.client_id')
            ->pluck('content_accounts.client_id', 'content_account_ventures.venture')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
