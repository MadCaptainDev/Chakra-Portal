<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * The Google Keep bulk importer: a Takeout export's notes, matched to
 * content items by title, become one Script (+ one section) each. There is
 * no live Keep connection to test against -- Keep has no API a personal
 * account can grant this app, which is the whole reason this reads a
 * Takeout zip instead (see GoogleKeepImport's own doc block).
 */
class GoogleKeepImportTest extends TestCase
{
    use RefreshDatabase;

    private function writer(array $abilities = ['view', 'create']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['scripts' => $abilities]);

        return $user->refresh();
    }

    private function contentItem(string $title, array $overrides = []): ContentItem
    {
        return ContentItem::factory()->create($overrides + [
            'title' => $title,
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Published',
        ]);
    }

    /**
     * A real Takeout-shaped zip: one JSON file per note under a Keep/
     * folder, exactly what readNotes() scans for.
     *
     * @param  list<array<string, mixed>>  $notes  each a raw Keep note payload
     */
    private function takeoutZip(array $notes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'keep-takeout-').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($notes as $index => $note) {
            $zip->addFromString("Takeout/Keep/note-{$index}.json", json_encode($note));
        }

        // A non-note file Takeout genuinely includes -- readNotes() must
        // skip it rather than choke on it or misread it as a note.
        $zip->addFromString('Takeout/Keep/Labels.json', json_encode([['name' => 'Scripts']]));

        $zip->close();

        return new UploadedFile($path, 'takeout.zip', 'application/zip', null, true);
    }

    // -- Permissions ----------------------------------------------------------

    public function test_the_form_and_the_import_both_need_the_create_ability(): void
    {
        $user = $this->writer(['view']);

        $this->actingAs($user)->get(route('scripts.import-keep.create'))->assertForbidden();
        $this->actingAs($user)->post(route('scripts.import-keep.store'), [
            'keep_export' => $this->takeoutZip([]),
        ])->assertForbidden();
    }

    // -- The happy path ---------------------------------------------------------

    public function test_a_matching_note_becomes_a_script_with_one_section(): void
    {
        $user = $this->writer();
        $this->contentItem('Independence Day Reel');

        $zip = $this->takeoutZip([
            ['title' => 'Independence Day Reel', 'textContent' => "Hook: flag waving.\nBody: the story.", 'isTrashed' => false],
        ]);

        $response = $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $response->assertRedirect(route('scripts.import-keep.create'));
        $response->assertSessionHas('status', '1 script imported.');

        $script = Script::where('title', 'Independence Day Reel')->firstOrFail();
        $this->assertSame($user->id, $script->created_by_id);
        $this->assertSame(Script::STATUS_DRAFT, $script->status);
        $this->assertNotNull($script->content_item_id);

        $section = $script->sections->sole();
        $this->assertSame('Script', $section->heading);
        $this->assertStringContainsString('Hook: flag waving.', $section->body);
        $this->assertStringContainsString('<br', $section->body);
    }

    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $user = $this->writer();
        $this->contentItem('  Independence Day Reel  ');

        $zip = $this->takeoutZip([
            ['title' => 'independence day reel', 'textContent' => 'x'],
        ]);

        $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $this->assertSame(1, Script::count());
    }

    public function test_a_checklist_note_is_flattened_with_checkbox_markers(): void
    {
        $user = $this->writer();
        $this->contentItem('Product Launch Post');

        $zip = $this->takeoutZip([
            ['title' => 'Product Launch Post', 'listContent' => [
                ['text' => 'Shoot the unboxing', 'isChecked' => true],
                ['text' => 'Write the caption', 'isChecked' => false],
            ]],
        ]);

        $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $body = Script::where('title', 'Product Launch Post')->firstOrFail()->sections->sole()->body;
        $this->assertStringContainsString('[x] Shoot the unboxing', $body);
        $this->assertStringContainsString('[ ] Write the caption', $body);
    }

    // -- What gets skipped, and why -----------------------------------------------

    public function test_a_trashed_note_is_not_imported(): void
    {
        $user = $this->writer();
        $this->contentItem('Deleted Idea');

        $zip = $this->takeoutZip([
            ['title' => 'Deleted Idea', 'textContent' => 'x', 'isTrashed' => true],
        ]);

        $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $this->assertSame(0, Script::count());
    }

    public function test_an_untitled_note_is_not_imported(): void
    {
        $user = $this->writer();

        $zip = $this->takeoutZip([
            ['title' => '', 'textContent' => 'x'],
        ]);

        $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $this->assertSame(0, Script::count());
    }

    public function test_a_note_with_no_matching_content_item_is_reported_unmatched(): void
    {
        $user = $this->writer();

        $zip = $this->takeoutZip([
            ['title' => 'Nothing Like This Exists', 'textContent' => 'x'],
        ]);

        $response = $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $this->assertSame(0, Script::count());
        $response->assertSessionHas('importResult', fn (array $r) => $r['unmatched'] === ['Nothing Like This Exists']);
    }

    /**
     * A content item that already has a linked script is left alone -- a
     * second run of the same export (after adding new Keep notes) must not
     * create a duplicate for one already imported.
     */
    public function test_a_content_item_that_already_has_a_script_is_skipped_not_duplicated(): void
    {
        $user = $this->writer();
        $item = $this->contentItem('Already Written');
        Script::create(['title' => 'Already Written', 'content_item_id' => $item->id, 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);

        $zip = $this->takeoutZip([
            ['title' => 'Already Written', 'textContent' => 'a second copy'],
        ]);

        $response = $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $this->assertSame(1, Script::count());
        $response->assertSessionHas('importResult', fn (array $r) => $r['skipped_existing'] === ['Already Written']);
    }

    public function test_a_title_matching_more_than_one_content_item_is_reported_ambiguous_but_still_imports(): void
    {
        $user = $this->writer();
        $this->contentItem('Reused Title', ['synced_at' => now()->subDay()]);
        $newer = $this->contentItem('Reused Title', ['synced_at' => now()]);

        $zip = $this->takeoutZip([
            ['title' => 'Reused Title', 'textContent' => 'x'],
        ]);

        $response = $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $response->assertSessionHas('importResult', fn (array $r) => $r['ambiguous'] === ['Reused Title']);
        $this->assertSame($newer->id, Script::sole()->content_item_id);
    }

    public function test_a_non_zip_upload_is_rejected(): void
    {
        $user = $this->writer();

        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $file])
            ->assertSessionHasErrors('keep_export');

        $this->assertSame(0, Script::count());
    }

    // -- Best-effort client resolution -------------------------------------------

    public function test_the_client_is_resolved_from_the_content_items_venture_when_possible(): void
    {
        $user = $this->writer();
        $client = Client::factory()->create(['name' => 'SVA Silks', 'notion_venture' => 'SVA Silks']);
        $this->contentItem('Venture Match', ['venture' => 'SVA Silks']);

        $zip = $this->takeoutZip([
            ['title' => 'Venture Match', 'textContent' => 'x'],
        ]);

        $this->actingAs($user)->post(route('scripts.import-keep.store'), ['keep_export' => $zip]);

        $this->assertSame($client->id, Script::sole()->client_id);
    }
}
