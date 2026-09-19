<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\User;
use App\Services\Notion\NotionScriptImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotionScriptImportTest extends TestCase
{
    use RefreshDatabase;

    private function reelWithScript(string $script, ?string $venture = null): ContentItem
    {
        return ContentItem::create([
            'notion_page_id' => 'page-'.uniqid(),
            'source' => 'reel',
            'title' => 'Thor Gym - Workout Benefits',
            'venture' => $venture,
            'script' => $script,
            'synced_at' => now(),
        ]);
    }

    public function test_a_notion_script_becomes_a_draft_with_its_text_in_the_body(): void
    {
        $item = $this->reelWithScript("Squats panna knee pain irukathu\nHip thrust panrathu");

        $result = NotionScriptImporter::importMissing();

        $this->assertSame(1, $result['created']);

        $script = Script::firstOrFail();
        $this->assertSame($item->id, $script->content_item_id);
        $this->assertSame('Thor Gym - Workout Benefits', $script->title);
        $this->assertSame(Script::STATUS_DRAFT, $script->status);

        $body = $script->sections->firstWhere('heading', 'Body')->body;
        $this->assertStringContainsString('Squats panna knee pain irukathu', $body);
        $this->assertStringContainsString('Hip thrust panrathu', $body);
    }

    public function test_every_line_becomes_its_own_paragraph(): void
    {
        // The whole point: these scripts are mostly short numbered lines, and
        // stored as one blob they render as an unreadable wall.
        $this->reelWithScript("1) Hook line\n2) Second beat\n\n3) Third beat");

        NotionScriptImporter::importMissing();

        $body = Script::firstOrFail()->sections->firstWhere('heading', 'Body')->body;

        $this->assertSame(3, substr_count($body, '<p>'), 'blank lines should not become empty paragraphs');
        $this->assertStringContainsString('<p>1) Hook line</p>', $body);
        $this->assertStringContainsString('<p>3) Third beat</p>', $body);
    }

    public function test_text_that_looks_like_markup_is_kept_as_text(): void
    {
        $this->reelWithScript('Use <b>bold</b> & a caption');

        NotionScriptImporter::importMissing();

        $body = Script::firstOrFail()->sections->firstWhere('heading', 'Body')->body;

        // Escaped, so it survives the rich-text sanitiser as words rather
        // than being read as markup and unwrapped.
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $body);
        $this->assertStringContainsString('&amp;', $body);
    }

    public function test_the_three_standard_sections_are_always_created(): void
    {
        $this->reelWithScript('Just the body text');

        NotionScriptImporter::importMissing();

        $sections = Script::firstOrFail()->sections;

        $this->assertSame(['Hook', 'Body', 'CTA'], $sections->pluck('heading')->all());
        $this->assertSame([0, 1, 2], $sections->pluck('position')->all());
        $this->assertNull($sections->firstWhere('heading', 'Hook')->body);
    }

    public function test_the_client_is_resolved_from_the_venture(): void
    {
        $client = Client::create(['name' => 'Thor Gym']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'thorgym']);
        $account->ventures()->create(['venture' => 'THOR']);

        $this->reelWithScript('Body text', venture: 'THOR');

        $result = NotionScriptImporter::importMissing();

        $this->assertSame(0, $result['unmatched_client']);
        $this->assertSame($client->id, Script::firstOrFail()->client_id);
    }

    public function test_an_unmapped_venture_still_imports_but_is_counted(): void
    {
        $this->reelWithScript('Body text', venture: 'NOBODY KNOWS');

        $result = NotionScriptImporter::importMissing();

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['unmatched_client']);
        $this->assertNull(Script::firstOrFail()->client_id);
    }

    public function test_items_without_script_text_are_left_alone(): void
    {
        $this->reelWithScript('');
        ContentItem::create([
            'notion_page_id' => 'no-script',
            'source' => 'reel',
            'title' => 'Nothing written yet',
            'synced_at' => now(),
        ]);

        $this->assertSame(0, NotionScriptImporter::pendingCount());
        $this->assertSame(0, NotionScriptImporter::importMissing()['created']);
    }

    public function test_running_twice_does_not_duplicate_or_overwrite(): void
    {
        $item = $this->reelWithScript('Original Notion text');

        NotionScriptImporter::importMissing();

        // Somebody rewrites it in the portal.
        $script = Script::firstOrFail();
        $section = $script->sections->firstWhere('heading', 'Body');
        $section->body = '<p>Rewritten by the writer</p>';
        $section->save();

        // Notion changes underneath.
        $item->update(['script' => 'A different Notion draft']);

        $second = NotionScriptImporter::importMissing();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, Script::count());
        $this->assertStringContainsString(
            'Rewritten by the writer',
            Script::firstOrFail()->sections->firstWhere('heading', 'Body')->fresh()->body,
            'an existing script must never be overwritten from Notion'
        );
    }

    public function test_writing_a_script_from_a_reel_opens_on_the_notion_text(): void
    {
        $item = $this->reelWithScript("Line one\nLine two");
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($user)->post(route('scripts.store'), [
            'title' => 'Written by hand',
            'content_item_id' => $item->id,
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
        ])->assertRedirect();

        $body = Script::firstOrFail()->sections->firstWhere('heading', 'Body')->body;

        $this->assertStringContainsString('Line one', $body);
        $this->assertStringContainsString('Line two', $body);
    }

    public function test_a_script_written_from_nothing_still_opens_on_empty_sections(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($user)->post(route('scripts.store'), [
            'title' => 'From scratch',
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
        ])->assertRedirect();

        $sections = Script::firstOrFail()->sections;

        $this->assertSame(['Hook', 'Body', 'CTA'], $sections->pluck('heading')->all());
        $this->assertTrue($sections->every(fn ($s) => $s->body === null));
    }

    public function test_the_limit_option_caps_a_cautious_first_run(): void
    {
        $this->reelWithScript('One');
        $this->reelWithScript('Two');
        $this->reelWithScript('Three');

        $this->assertSame(3, NotionScriptImporter::pendingCount());
        $this->assertSame(2, NotionScriptImporter::importMissing(limit: 2)['created']);
        $this->assertSame(1, NotionScriptImporter::pendingCount());
    }
}
