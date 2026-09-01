<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Bulk-imports scripts written in Google Keep, matched to this app's
 * Notion-synced content items by title.
 *
 * Google Keep has no API a personal account can grant this app access to --
 * the only "Google Keep API" Google publishes is locked to paid Workspace
 * admin accounts for enterprise legal-hold, not reading a user's own notes.
 * Google Takeout (https://takeout.google.com) is the sanctioned way out: it
 * exports every Keep note as one JSON file per note inside a zip, which is
 * exactly the shape this class reads. No OAuth, no stored password, no
 * unofficial reverse-engineered client -- the export is a file the user
 * already owns.
 *
 * Matching is by exact title (case/whitespace-insensitive): the workflow
 * this was built for is "the Keep note's title is the same as the Notion
 * item's title", not fuzzy search. A note whose title doesn't match
 * anything is reported, not guessed at.
 */
class GoogleKeepImport
{
    /**
     * @return array{imported: list<array{title: string, script_id: int}>, skipped_existing: list<string>, unmatched: list<string>, ambiguous: list<string>}
     */
    public function importFromZip(string $zipPath, User $user): array
    {
        $notes = $this->readNotes($zipPath);

        $imported = [];
        $skippedExisting = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($notes as $note) {
            $matches = $this->findContentItems($note['title']);

            if ($matches->isEmpty()) {
                $unmatched[] = $note['title'];

                continue;
            }

            if ($matches->count() > 1) {
                $ambiguous[] = $note['title'];
            }

            // The most recently synced when more than one content item
            // shares this title -- reported above as ambiguous either way,
            // so a wrong pick here is visible and fixable rather than
            // silent.
            $contentItem = $matches->first();

            if (Script::where('content_item_id', $contentItem->id)->exists()) {
                $skippedExisting[] = $note['title'];

                continue;
            }

            $script = DB::transaction(function () use ($note, $contentItem, $user) {
                $script = Script::create([
                    'client_id' => $this->resolveClient($contentItem->venture)?->id,
                    'content_item_id' => $contentItem->id,
                    'title' => $note['title'],
                    'status' => Script::STATUS_DRAFT,
                    'priority' => Script::PRIORITY_NORMAL,
                    'created_by_id' => $user->id,
                ]);

                // One section, not the usual Hook/Body/CTA scaffold a
                // blank script starts with: a Keep note is already-written
                // prose, not a fresh page to structure. Html::sanitise()
                // runs automatically via ScriptSection's own setBodyAttribute
                // mutator -- this class never bypasses it.
                $section = $script->sections()->make(['heading' => 'Script']);
                $section->position = 0;
                $section->body = $this->bodyHtml($note);
                $section->save();

                return $script;
            });

            $imported[] = ['title' => $note['title'], 'script_id' => $script->id];
        }

        return [
            'imported' => $imported,
            'skipped_existing' => $skippedExisting,
            'unmatched' => $unmatched,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Every real note in the export, title/text extracted, trashed notes
     * dropped.
     *
     * Takeout's Keep export puts one JSON (and a matching HTML) file per
     * note under a "Keep" folder, alongside a Labels.json manifest that is
     * not a note at all -- entries that don't parse as a note (missing the
     * fields a note always has) are skipped rather than treated as an
     * error, since which stray files Takeout includes is not this class's
     * contract to police.
     *
     * @return list<array{title: string, text: string}>
     */
    private function readNotes(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('That file could not be read as a zip archive.');
        }

        $notes = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || ! preg_match('/keep\/.*\.json$/i', $name)) {
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents === false) {
                continue;
            }

            $decoded = json_decode($contents, true);

            if (! is_array($decoded) || ! array_key_exists('title', $decoded)) {
                // Labels.json and anything else Takeout includes that isn't
                // shaped like a note.
                continue;
            }

            if (! empty($decoded['isTrashed'])) {
                continue;
            }

            $title = trim((string) ($decoded['title'] ?? ''));

            if ($title === '') {
                // An untitled note has nothing to match a content item's
                // title against -- there is no reasonable guess to make.
                continue;
            }

            $notes[] = ['title' => $title, 'text' => $this->extractText($decoded)];
        }

        $zip->close();

        return $notes;
    }

    /**
     * Keep stores a plain note's body under textContent and a checklist's
     * under listContent (an array of {text, isChecked}) -- never both. A
     * checklist is rendered back as plain lines with a checkbox marker,
     * since a script is prose either way once it's in this app.
     */
    private function extractText(array $note): string
    {
        if (isset($note['textContent']) && is_string($note['textContent'])) {
            return $note['textContent'];
        }

        if (isset($note['listContent']) && is_array($note['listContent'])) {
            return collect($note['listContent'])
                ->map(function ($item) {
                    $text = trim((string) ($item['text'] ?? ''));
                    $checked = ! empty($item['isChecked']);

                    return $text === '' ? null : ($checked ? '[x] ' : '[ ] ').$text;
                })
                ->filter()
                ->implode("\n");
        }

        return '';
    }

    /**
     * Plain-text note body -> the minimal safe HTML ScriptSection expects,
     * one paragraph, line breaks preserved. Html::sanitise() (via
     * ScriptSection::setBodyAttribute()) is the actual defense; this only
     * has to produce well-formed input for it.
     */
    private function bodyHtml(array $note): string
    {
        $text = trim($note['text']);

        if ($text === '') {
            return '';
        }

        return '<p>'.nl2br(e($text)).'</p>';
    }

    /**
     * Content items whose title matches, case/whitespace-insensitive --
     * Keep note titles are typed by hand and can drift by trailing spaces
     * or case without being a different video.
     *
     * @return \Illuminate\Support\Collection<int, ContentItem>
     */
    private function findContentItems(string $title): \Illuminate\Support\Collection
    {
        $normalized = mb_strtolower(trim($title));

        return ContentItem::query()
            ->whereRaw('LOWER(TRIM(title)) = ?', [$normalized])
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Best-effort: the same venture -> client resolution the timesheet
     * already trusts (TimesheetVenture::normalize()), not a second mapping
     * invented here. Null is a normal outcome -- content items with an
     * unmapped venture exist already, and a script without a client is a
     * supported state (scripts.client_id is nullable).
     */
    private function resolveClient(?string $venture): ?Client
    {
        $canonical = TimesheetVenture::normalize($venture);

        if ($canonical === null) {
            return null;
        }

        return Client::all()->first(
            fn (Client $client) => TimesheetVenture::canonicalForClient($client) === $canonical
        );
    }
}
