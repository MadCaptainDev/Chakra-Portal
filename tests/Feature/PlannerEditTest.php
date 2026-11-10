<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\NotionSetting;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Planner\PlannerSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Editing and creating planner items from Chakra Planner, the way Notion
 * allows: every change goes to Notion first, in the shape that planner's own
 * properties have.
 */
class PlannerEditTest extends TestCase
{
    use RefreshDatabase;

    private const REEL_DB = '2ba32af9-aff5-8063-af78-e65c7319f8b9';

    private User $user;

    /** The fake Notion page's properties, changed by PATCH and read back by GET. */
    private array $props;

    protected function setUp(): void
    {
        parent::setUp();
        PlannerSchema::forget();
        $this->withoutVite();
        $this->user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Keerthi']);
        foreach (['view', 'edit'] as $ability) {
            UserPermission::create(['user_id' => $this->user->id, 'module' => 'planner', 'ability' => $ability]);
        }

        $this->props = [
            'Videos' => ['type' => 'title', 'title' => [['plain_text' => 'SVA - Frock']]],
            'Status' => ['type' => 'select', 'select' => ['name' => 'To Be Edited']],
            'Venture' => ['type' => 'select', 'select' => ['name' => 'SVA Silks']],
            // The real planner's property really is named "Editor " with a space.
            'Editor ' => ['type' => 'multi_select', 'multi_select' => [['name' => 'Sanjai']]],
            'Tier' => ['type' => 'select', 'select' => null],
            'SSD' => ['type' => 'select', 'select' => null],
            'Script' => ['type' => 'rich_text', 'rich_text' => []],
            'Published Date' => ['type' => 'date', 'date' => ['start' => '2026-12-14', 'end' => null, 'time_zone' => null]],
            'Shoot' => ['type' => 'relation', 'relation' => []],
        ];

        NotionSetting::current()->update(['api_key' => 'secret_test']);
        Http::fake([
            'api.notion.com/v1/databases/*' => Http::response(['properties' => [
                'Videos' => ['type' => 'title', 'title' => (object) []],
                'Status' => ['type' => 'select', 'select' => ['options' => [
                    ['name' => 'Idea', 'color' => 'brown'], ['name' => 'To Be Edited', 'color' => 'orange'], ['name' => 'Edit in Progress', 'color' => 'yellow'],
                ]]],
                'Venture' => ['type' => 'select', 'select' => ['options' => [['name' => 'SVA Silks', 'color' => 'pink'], ['name' => 'Janet', 'color' => 'blue']]]],
                'Editor ' => ['type' => 'multi_select', 'multi_select' => ['options' => [['name' => 'Sanjai', 'color' => 'blue'], ['name' => 'Gokul', 'color' => 'yellow']]]],
                'Tier' => ['type' => 'select', 'select' => ['options' => [['name' => 'Low Effort & Work', 'color' => 'gray']]]],
                'Script' => ['type' => 'rich_text', 'rich_text' => (object) []],
                'Published Date' => ['type' => 'date', 'date' => (object) []],
                'Place' => ['type' => 'place', 'place' => (object) []],
            ]]),
            'api.notion.com/v1/pages' => function (HttpRequest $request) {
                $this->apply($request['properties']);

                return Http::response($this->page('page-new'));
            },
            'api.notion.com/v1/pages/*' => function (HttpRequest $request) {
                if ($request->method() === 'PATCH') {
                    $this->apply($request['properties']);
                }

                return Http::response($this->page('page-1'));
            },
            '*' => Http::response([], 404),
        ]);
    }

    /** Fold a write into the fake page, the way Notion echoes it back. */
    private function apply(array $written): void
    {
        foreach ($written as $name => $value) {
            $type = array_key_first($value);
            $body = $value[$type];
            if (in_array($type, ['title', 'rich_text'], true)) {
                $body = array_map(fn ($piece) => ['plain_text' => $piece['text']['content']], $body);
            }
            $this->props[$name] = ['type' => $type, $type => $body];
        }
    }

    private function page(string $id): array
    {
        return [
            'object' => 'page', 'id' => $id, 'url' => "https://www.notion.so/{$id}",
            'created_time' => '2026-09-01T10:00:00.000Z', 'archived' => false, 'in_trash' => false,
            'parent' => ['type' => 'database_id', 'database_id' => self::REEL_DB],
            'properties' => $this->props,
        ];
    }

    private function reel(): ContentItem
    {
        return ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'notion_page_id' => 'page-1', 'title' => 'SVA - Frock',
            'venture' => 'SVA Silks', 'status' => 'To Be Edited', 'editor' => 'Sanjai', 'published_date' => '2026-12-14',
        ]);
    }

    public function test_status_editor_client_and_title_are_written_to_notion_in_each_propertys_own_shape(): void
    {
        $reel = $this->reel();

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), [
            'status' => 'Edit in Progress',
            'editor' => ['Sanjai', 'Gokul'],
            'client' => 'Janet',
            'title' => 'Janet - Diwali frock',
            'expected' => ['status' => 'To Be Edited'],
        ])->assertOk()
            ->assertJsonPath('item.status', 'Edit in Progress')
            ->assertJsonPath('item.status_color', 'yellow')
            ->assertJsonPath('item.editor', 'Sanjai, Gokul')
            ->assertJsonPath('item.client', 'Janet')
            ->assertJsonPath('item.title', 'Janet - Diwali frock');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && $r['properties']['Status'] === ['select' => ['name' => 'Edit in Progress']]
            && $r['properties']['Editor '] === ['multi_select' => [['name' => 'Sanjai'], ['name' => 'Gokul']]]
            && $r['properties']['Venture'] === ['select' => ['name' => 'Janet']]
            && $r['properties']['Videos']['title'][0]['text']['content'] === 'Janet - Diwali frock');

        $reel->refresh();
        $this->assertSame('Edit in Progress', $reel->status);
        $this->assertSame('Janet', $reel->venture);

        $changes = ContentItemChange::query()->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['status', 'editor', 'client', 'title'], $changes->pluck('field')->all());
        $status = $changes->firstWhere('field', 'status');
        $this->assertSame(['To Be Edited', 'Edit in Progress', $this->user->id], [$status->from_value, $status->to_value, $status->user_id]);
    }

    public function test_a_long_script_is_split_into_pieces_notion_accepts_and_a_field_can_be_cleared(): void
    {
        $reel = $this->reel();
        $script = str_repeat('Sage green, ', 400); // 4800 characters

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), ['script' => $script, 'client' => null])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && count($r['properties']['Script']['rich_text']) === 3
            && $r['properties']['Venture'] === ['select' => null]);
        $this->assertSame(trim($script), $reel->fresh()->script);
        $this->assertNull($reel->fresh()->venture);
    }

    public function test_a_status_changed_in_notion_meanwhile_is_a_conflict_and_nothing_is_written(): void
    {
        $reel = $this->reel();
        $this->props['Status'] = ['type' => 'select', 'select' => ['name' => 'Idea']];

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), [
            'status' => 'Edit in Progress', 'expected' => ['status' => 'To Be Edited'],
        ])->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('item.status', 'Idea');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');
        $this->assertSame('Idea', $reel->fresh()->status);
    }

    public function test_setting_a_value_it_already_has_writes_nothing(): void
    {
        $reel = $this->reel();

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), ['status' => 'To Be Edited'])->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');
        $this->assertSame(0, ContentItemChange::count());
    }

    public function test_a_date_sent_with_another_field_still_keeps_its_conflict_check(): void
    {
        $reel = $this->reel();

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), [
            'published_date' => '2026-12-20', 'from' => '2026-12-01', 'status' => 'Idea',
        ])->assertStatus(409);

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), [
            'published_date' => '2026-12-20', 'from' => '2026-12-14', 'status' => 'Idea',
        ])->assertOk()->assertJsonPath('item.published_date', '2026-12-20')->assertJsonPath('item.status', 'Idea');
    }

    public function test_a_property_the_planner_cannot_write_says_so(): void
    {
        $reel = $this->reel();
        $this->props['Tier'] = ['type' => 'formula', 'formula' => ['string' => 'x']];

        $this->actingAs($this->user)->patchJson(route('planner.data.move', $reel->id), ['tier' => 'Low Effort & Work'])
            ->assertStatus(502)->assertJsonPath('code', 'notion_failed');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');
    }

    public function test_view_only_cannot_edit_or_create(): void
    {
        $reel = $this->reel();
        UserPermission::query()->where('ability', 'edit')->delete();

        $this->actingAs($this->user->fresh())->patchJson(route('planner.data.move', $reel->id), ['status' => 'Idea'])->assertForbidden();
        $this->postJson(route('planner.data.store'), ['source' => 'reel', 'title' => 'New'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_new_item_is_created_in_notion_first_and_appears_in_the_planner(): void
    {
        $this->actingAs($this->user)->postJson(route('planner.data.store'), [
            'source' => 'reel', 'title' => 'Zira - Pongal offer', 'published_date' => '2027-01-10', 'status' => 'Idea', 'client' => 'Janet',
        ])->assertCreated()
            ->assertJsonPath('item.title', 'Zira - Pongal offer')
            ->assertJsonPath('item.source', 'reel')
            ->assertJsonPath('item.published_date', '2027-01-10')
            ->assertJsonPath('item.status', 'Idea');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/pages')
            && $r['parent']['database_id'] === self::REEL_DB
            && $r['properties']['Videos']['title'][0]['text']['content'] === 'Zira - Pongal offer'
            && $r['properties']['Published Date']['date']['start'] === '2027-01-10');

        $this->assertDatabaseHas('content_items', ['notion_page_id' => 'page-new', 'title' => 'Zira - Pongal offer']);
        $this->postJson(route('planner.data.store'), ['source' => 'shoot', 'title' => 'x'])->assertStatus(422);
        $this->postJson(route('planner.data.store'), ['source' => 'reel'])->assertStatus(422);
    }

    public function test_meta_describes_each_planners_editable_fields(): void
    {
        $this->actingAs($this->user)->getJson(route('planner.data.meta'))->assertOk()
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('fields.reel.status.type', 'select')
            ->assertJsonPath('fields.reel.editor.type', 'multi_select')
            ->assertJsonPath('fields.reel.editor.options.1.name', 'Gokul')
            ->assertJsonPath('fields.reel.client.options.0.color', 'pink')
            ->assertJsonPath('fields.reel.script.type', 'rich_text')
            ->assertJsonMissingPath('fields.reel.ssd');
    }

    public function test_refresh_can_answer_at_once_and_sync_afterwards(): void
    {
        $this->actingAs($this->user)->postJson(route('planner.data.refresh'), ['background' => true])
            ->assertStatus(202)->assertJsonPath('message', 'Refreshing from Notion…');
    }
}
