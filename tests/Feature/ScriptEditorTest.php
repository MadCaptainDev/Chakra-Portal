<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Script;
use App\Models\ScriptSection;
use App\Models\User;
use App\Support\Html;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScriptEditorTest extends TestCase
{
    use RefreshDatabase;

    private function writer(array $abilities = ['view', 'create', 'edit', 'delete']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['scripts' => $abilities]);

        return $user->refresh();
    }

    private function script(array $overrides = []): Script
    {
        return Script::create($overrides + [
            'title' => 'Tea montage reel',
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
        ]);
    }

    private function section(Script $script, array $overrides = []): ScriptSection
    {
        $section = $script->sections()->make($overrides + ['heading' => 'Hook', 'body' => '<p>Original</p>']);
        $section->position = $overrides['position'] ?? 0;
        $section->save();

        return $section;
    }

    // ——— Creating ———

    public function test_a_new_script_opens_on_hook_body_and_cta(): void
    {
        $writer = $this->writer();
        $client = Client::factory()->create();

        $this->actingAs($writer)->post(route('scripts.store'), [
            'title' => 'Tea montage reel',
            'client_id' => $client->id,
            'status' => Script::STATUS_WRITING,
            'priority' => Script::PRIORITY_NORMAL,
        ])->assertRedirect();

        $script = Script::firstOrFail();

        $this->assertSame($writer->id, $script->created_by_id);
        $this->assertSame(['Hook', 'Body', 'CTA'], $script->sections->pluck('heading')->all());
        // Blank sections seeded in written order, not all at position 0.
        $this->assertSame([0, 1, 2], $script->sections->pluck('position')->all());
    }

    public function test_a_taxonomy_term_of_the_wrong_type_is_refused(): void
    {
        $tag = \App\Models\TaxonomyTerm::create([
            'type' => \App\Models\TaxonomyTerm::TYPE_TAG,
            'name' => 'Festive',
            'slug' => 'festive',
        ]);

        $this->actingAs($this->writer())->post(route('scripts.store'), [
            'title' => 'Wrong list',
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
            'script_type_id' => $tag->id,
        ])->assertSessionHasErrors('script_type_id');

        $this->assertDatabaseCount('scripts', 0);
    }

    // ——— Autosave and conflicts ———

    public function test_saving_a_section_bumps_its_version(): void
    {
        $writer = $this->writer();
        $script = $this->script();
        $section = $this->section($script);

        $response = $this->actingAs($writer)->patchJson(
            route('scripts.sections.update', [$script, $section]),
            ['version' => 1, 'heading' => 'Hook', 'body' => '<p>Rewritten</p>']
        );

        $response->assertOk()->assertJsonPath('version', 2);

        $section->refresh();
        $this->assertSame('<p>Rewritten</p>', $section->body);
        // The script records who touched it, for the list column.
        $this->assertSame($writer->id, $script->refresh()->last_edited_by_id);
    }

    public function test_a_stale_version_is_refused_and_the_newer_text_survives(): void
    {
        $script = $this->script();
        $section = $this->section($script);

        // Someone else saves first.
        $this->actingAs($this->writer())->patchJson(
            route('scripts.sections.update', [$script, $section]),
            ['version' => 1, 'heading' => 'Hook', 'body' => '<p>Theirs</p>']
        )->assertOk();

        // We were still holding version 1.
        $this->actingAs($this->writer())->patchJson(
            route('scripts.sections.update', [$script, $section]),
            ['version' => 1, 'heading' => 'Hook', 'body' => '<p>Mine</p>']
        )->assertStatus(409)->assertJsonPath('conflict', true);

        // The status code alone would not prove the absence of an overwrite.
        $this->assertSame('<p>Theirs</p>', $section->refresh()->body);
    }

    public function test_two_writers_on_different_sections_do_not_conflict(): void
    {
        $script = $this->script();
        $hook = $this->section($script, ['heading' => 'Hook', 'position' => 0]);
        $cta = $this->section($script, ['heading' => 'CTA', 'position' => 1]);

        $this->actingAs($this->writer())->patchJson(
            route('scripts.sections.update', [$script, $hook]),
            ['version' => 1, 'heading' => 'Hook', 'body' => '<p>A</p>']
        )->assertOk();

        // The other section's version is untouched by the first save, which is
        // the whole reason versions are per section rather than per script.
        $this->actingAs($this->writer())->patchJson(
            route('scripts.sections.update', [$script, $cta]),
            ['version' => 1, 'heading' => 'CTA', 'body' => '<p>B</p>']
        )->assertOk();
    }

    public function test_a_section_belonging_to_another_script_is_not_found(): void
    {
        $mine = $this->script();
        $theirs = $this->script(['title' => 'Someone else']);
        $section = $this->section($theirs);

        // Scoped bindings: a 404, not a 403, and never someone else's row.
        $this->actingAs($this->writer())->patchJson(
            route('scripts.sections.update', [$mine, $section]),
            ['version' => 1, 'heading' => 'Hook', 'body' => '<p>x</p>']
        )->assertNotFound();
    }

    public function test_a_body_is_sanitised_on_the_way_in(): void
    {
        $script = $this->script();
        $section = $this->section($script);

        $this->actingAs($this->writer())->patchJson(
            route('scripts.sections.update', [$script, $section]),
            [
                'version' => 1,
                'heading' => 'Hook',
                'body' => '<script>alert(1)</script><p onclick="steal()">Ungaloda business</p>',
            ]
        )->assertOk();

        // Posted straight at the endpoint, bypassing any browser-side scrub.
        $this->assertSame('<p>Ungaloda business</p>', $section->refresh()->body);
    }

    public function test_reordering_persists_and_ignores_foreign_sections(): void
    {
        $script = $this->script();
        $other = $this->script(['title' => 'Other']);

        $a = $this->section($script, ['heading' => 'A', 'position' => 0]);
        $b = $this->section($script, ['heading' => 'B', 'position' => 1]);
        $foreign = $this->section($other, ['heading' => 'Foreign']);

        $this->actingAs($this->writer())->postJson(
            route('scripts.sections.reorder', $script),
            ['order' => [$b->id, $foreign->id, $a->id]]
        )->assertOk();

        $this->assertSame(['B', 'A'], $script->refresh()->sections->pluck('heading')->all());
        // The other script's section kept its own place.
        $this->assertSame(0, $foreign->refresh()->position);
    }

    public function test_deleting_a_script_takes_its_sections(): void
    {
        $script = $this->script();
        $this->section($script);

        $this->actingAs($this->writer())->delete(route('scripts.destroy', $script))->assertRedirect();

        $this->assertDatabaseCount('scripts', 0);
        $this->assertDatabaseCount('script_sections', 0);
    }

    // ——— The sanitiser itself ———

    public function test_the_sanitiser_keeps_the_words_and_drops_the_rest(): void
    {
        $this->assertSame('<p>after</p>', Html::sanitise('<script>alert(1)</script><p>after</p>'));
        $this->assertSame('<p>click</p>', Html::sanitise('<p onclick="steal()">click</p>'));
        $this->assertSame('styled', Html::sanitise('<span style="color:red">styled</span>'));
        $this->assertSame('<strong>b</strong> and <em>i</em>', Html::sanitise('<b>b</b> and <i>i</i>'));
        $this->assertNull(Html::sanitise('   '));

        // A javascript: link loses the link but keeps the text.
        $this->assertSame('bad', Html::sanitise('<a href="javascript:alert(1)">bad</a>'));

        $safe = Html::sanitise('<a href="https://example.com">good</a>');
        $this->assertStringContainsString('href="https://example.com"', $safe);
        $this->assertStringContainsString('noopener', $safe);
    }

    public function test_the_sanitiser_preserves_tamil(): void
    {
        // These scripts are written in Tamil. DOMDocument mangles non-ASCII
        // without an explicit encoding, so this is a real regression risk.
        $this->assertSame('<p>தமிழ் உரை</p>', Html::sanitise('<p>தமிழ் உரை</p>'));
    }

    public function test_a_term_in_use_by_a_script_reports_its_usage(): void
    {
        $term = \App\Models\TaxonomyTerm::create([
            'type' => \App\Models\TaxonomyTerm::TYPE_SCRIPT_TYPE,
            'name' => 'Explainer',
            'slug' => 'explainer',
        ]);

        $this->script(['script_type_id' => $term->id]);

        // Without this the delete warning says "0 uses" and nulls live scripts.
        $this->assertSame(1, $term->usageCount());
    }
}
