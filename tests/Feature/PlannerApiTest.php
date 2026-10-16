<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\NotionSetting;
use App\Models\PlannerSetting;
use App\Models\Todo;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Notion\ContentSyncService;
use App\Services\Planner\PlannerRealtime;
use App\Services\Planner\PlannerSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Chakra Planner API (routes/planner-api.php), its Notion write-back,
 * Notion webhooks, Ably realtime and the app's self-updates.
 */
class PlannerApiTest extends TestCase
{
    use RefreshDatabase;

    private const REEL_DB = '2ba32af9-aff5-8063-af78-e65c7319f8b9';

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setTime(10, 0));
        PlannerSchema::forget();
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai', 'email' => 'sanjai@chakra.test', 'password' => bcrypt('secret-pass')]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('uploads/planner'));
        parent::tearDown();
    }

    private function grant(User $user, string ...$abilities): void
    {
        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'planner', 'ability' => $ability]);
        }
    }

    private function token(User $user): string
    {
        return $this->postJson(route('api.planner.login'), ['email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'Test Mac'])
            ->assertCreated()->json('token');
    }

    private function api(string $token): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    private function reel(array $attributes = []): ContentItem
    {
        return ContentItem::factory()->create(array_merge([
            'source' => ContentItem::SOURCE_REEL,
            'notion_page_id' => 'page-1',
            'title' => 'SVA - Frock',
            'venture' => 'SVA Silks',
            'status' => 'To Be Edited',
            'published_date' => today()->addDays(3),
        ], $attributes));
    }

    /** A Notion page as the API returns it. */
    private function page(string $id, ?string $date, string $status = 'To Be Edited', string $db = self::REEL_DB): array
    {
        return [
            'object' => 'page', 'id' => $id, 'url' => "https://www.notion.so/{$id}",
            'created_time' => '2026-09-01T10:00:00.000Z', 'archived' => false, 'in_trash' => false,
            'parent' => ['type' => 'database_id', 'database_id' => $db],
            'properties' => [
                'Name' => ['type' => 'title', 'title' => [['plain_text' => 'SVA - Frock']]],
                'Status' => ['type' => 'select', 'select' => ['name' => $status]],
                'Published Date' => ['type' => 'date', 'date' => $date ? ['start' => $date, 'end' => null, 'time_zone' => null] : null],
            ],
        ];
    }

    /** What the fake Notion page's Published Date currently reads. */
    private ?string $notionDate = null;

    /**
     * Notion: the page reads as $current until PATCHed, then as the patched
     * date. Faked once per test -- Http::fake() keeps answering with the
     * first stub for a URL, so later scenarios change $notionDate instead.
     */
    private function fakeNotion(string $current, ?callable $onPatch = null): void
    {
        $this->notionDate = $current;
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        Http::fake([
            'api.notion.com/v1/databases/*' => Http::response(['properties' => ['Status' => ['type' => 'select', 'select' => ['options' => [['name' => 'To Be Edited', 'color' => 'orange']]]]]]),
            'api.notion.com/v1/pages/*' => function (HttpRequest $request) use ($onPatch) {
                if ($request->method() === 'PATCH') {
                    if ($onPatch) {
                        return $onPatch($request);
                    }
                    $this->notionDate = $request['properties']['Published Date']['date']['start'] ?? null;
                }

                return Http::response($this->page('page-1', $this->notionDate));
            },
            'main.realtime.ably.net/*' => Http::response(['channel' => 'planner', 'messageId' => 'x'], 201),
            '*' => Http::response([], 404),
        ]);
    }

    // ——— Signing in ———

    public function test_any_teammate_signs_in_but_the_planners_need_the_permission(): void
    {
        $widget = $this->postJson(route('api.planner.login'), ['email' => 'sanjai@chakra.test', 'password' => 'secret-pass', 'device_name' => 'Mac'])
            ->assertCreated()->assertJsonPath('abilities.view', false)->assertJsonPath('abilities.editing', true)->json('token');
        $this->api($widget)->getJson(route('api.planner.editing.board'))->assertOk();
        $this->api($widget)->getJson(route('api.planner.items.index'))->assertForbidden()->assertJsonPath('code', 'no_access');

        $this->grant($this->editor, 'view');
        $this->postJson(route('api.planner.login'), ['email' => 'sanjai@chakra.test', 'password' => 'wrong', 'device_name' => 'Mac'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

        $login = $this->postJson(route('api.planner.login'), ['email' => 'sanjai@chakra.test', 'password' => 'secret-pass', 'device_name' => 'Sanjai’s MacBook', 'platform' => 'darwin-aarch64', 'app_version' => '0.1.0'])
            ->assertCreated()
            ->assertJsonPath('user.name', 'Sanjai')
            ->assertJsonPath('abilities.edit', false)
            ->assertJsonPath('realtime.enabled', false);
        $token = $login->json('token');
        $this->assertStringStartsWith('chakraplanner_', $token);

        $this->flushHeaders()->getJson(route('api.planner.me'))->assertUnauthorized();
        $this->api($token)->getJson(route('api.planner.me'))->assertOk()->assertJsonPath('session.device_name', 'Sanjai’s MacBook');

        // Taking the permission away locks the planners on every device at once; the widget stays.
        UserPermission::query()->where('user_id', $this->editor->id)->delete();
        $this->api($token)->getJson(route('api.planner.items.index'))->assertForbidden()->assertJsonPath('code', 'no_access');
        $this->api($token)->getJson(route('api.planner.me'))->assertOk();
    }

    public function test_clients_cannot_sign_in_and_signing_out_kills_the_token(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => 'client@x.test', 'password' => bcrypt('secret-pass')]);
        $this->postJson(route('api.planner.login'), ['email' => 'client@x.test', 'password' => 'secret-pass', 'device_name' => 'PC'])->assertForbidden();

        $this->grant($this->editor, 'view');
        $token = $this->token($this->editor);
        $this->api($token)->postJson(route('api.planner.logout'))->assertNoContent();
        $this->api($token)->getJson(route('api.planner.me'))->assertUnauthorized();
    }

    public function test_sign_in_is_throttled_per_email(): void
    {
        $this->grant($this->editor, 'view');
        foreach (range(1, 5) as $i) {
            $this->postJson(route('api.planner.login'), ['email' => 'sanjai@chakra.test', 'password' => 'nope', 'device_name' => 'PC'])->assertStatus(422);
        }
        $this->postJson(route('api.planner.login'), ['email' => 'sanjai@chakra.test', 'password' => 'secret-pass', 'device_name' => 'PC'])
            ->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    // ——— Reading ———

    public function test_items_are_listed_filtered_and_fetched_as_deltas(): void
    {
        $this->grant($this->editor, 'view');
        $token = $this->token($this->editor);

        $reel = $this->reel();
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_POST, 'title' => 'TPC - Post', 'venture' => 'TPC', 'published_date' => today()->addDays(10)]);
        ContentItem::factory()->create(['source' => ContentItem::SOURCE_REEL, 'title' => 'Gone from Notion', 'notion_missing_since' => now()]);
        Todo::create(['user_id' => $this->editor->id, 'title' => 'SVA - Frock', 'venture' => 'SVA Silks', 'content_item_id' => $reel->id, 'starts_on' => today(), 'due_on' => today()]);

        $this->api($token)->getJson(route('api.planner.items.index'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.title', 'SVA - Frock')
            ->assertJsonPath('data.0.client', 'SVA Silks')
            ->assertJsonPath('data.0.editing.0.user.name', 'Sanjai')
            ->assertJsonPath('data.0.editing.0.status', 'waiting')
            ->assertJsonMissing(['title' => 'Gone from Notion']);

        $this->api($token)->getJson(route('api.planner.items.index', ['source' => 'post']))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'TPC - Post');
        $this->api($token)->getJson(route('api.planner.items.index', ['to' => today()->addDays(5)->toDateString()]))->assertJsonPath('meta.total', 1);
        $this->api($token)->getJson(route('api.planner.items.index', ['q' => 'frock']))->assertJsonPath('meta.total', 1);

        // Delta: only what changed since, including what went missing from Notion.
        $this->travel(1)->minutes();
        $since =$this->api($token)->getJson(route('api.planner.items.index'))->json('meta.server_time');
        $this->travel(5)->minutes();
        $reel->forceFill(['status' => 'Edit in Progress'])->save();
        ContentItem::query()->where('title', 'TPC - Post')->first()->forceFill(['notion_missing_since' => now()])->save();

        $delta = $this->api($token)->getJson(route('api.planner.items.index', ['updated_since' => $since]))->assertOk();
        $this->assertEqualsCanonicalizing(['SVA - Frock', 'TPC - Post'], array_column($delta->json('data'), 'title'));
        $this->assertTrue(collect($delta->json('data'))->firstWhere('title', 'TPC - Post')['missing_from_notion']);
    }

    public function test_meta_lists_sources_clients_and_people(): void
    {
        $this->grant($this->editor, 'view');
        $this->reel();

        $this->api($this->token($this->editor))->getJson(route('api.planner.meta'))
            ->assertOk()
            ->assertJsonPath('sources.0.key', 'reel')
            ->assertJsonPath('sources.0.count', 1)
            ->assertJsonPath('clients.0.name', 'SVA Silks')
            ->assertJsonFragment(['name' => 'Sanjai'])
            ->assertJsonPath('statuses.reel.0.name', 'Idea');
    }

    // ——— Moving a publish date ———

    public function test_moving_a_date_writes_notion_first_then_the_portal_and_records_who(): void
    {
        $this->grant($this->editor, 'view', 'edit');
        $reel = $this->reel();
        $from = $reel->published_date->toDateString();
        $to = today()->addDays(5)->toDateString();
        $this->fakeNotion($from);

        $this->api($this->token($this->editor))
            ->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => $to, 'from' => $from])
            ->assertOk()
            ->assertJsonPath('item.published_date', $to)
            ->assertJsonPath('item.status_color', 'orange')
            ->assertJsonPath('item.history.0.field', 'published_date')
            ->assertJsonPath('item.history.0.from', $from)
            ->assertJsonPath('item.history.0.to', $to)
            ->assertJsonPath('item.history.0.by.name', 'Sanjai')
            ->assertJsonPath('item.last_change.origin', 'planner');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && $r['properties']['Published Date']['date']['start'] === $to);
        $this->assertSame($to, $reel->fresh()->published_date->toDateString());
    }

    public function test_a_time_of_day_in_notion_is_kept_when_the_day_moves(): void
    {
        $this->grant($this->editor, 'view', 'edit');
        $reel = $this->reel(['published_date' => '2026-12-14']);
        $this->fakeNotion('2026-12-14T18:00:00.000+05:30');

        $this->api($this->token($this->editor))
            ->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => '2026-12-16', 'from' => '2026-12-14']);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && $r['properties']['Published Date']['date']['start'] === '2026-12-16T18:00:00.000+05:30');
    }

    public function test_a_stale_date_is_a_conflict_not_an_overwrite(): void
    {
        $this->grant($this->editor, 'view', 'edit');
        $reel = $this->reel();
        $token = $this->token($this->editor);
        $current = $reel->published_date->toDateString();

        // The app was showing an older date than the portal has.
        $this->fakeNotion($current);
        $this->api($token)->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => today()->addDays(9)->toDateString(), 'from' => today()->addDay()->toDateString()])
            ->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('item.published_date', $current);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');

        // Somebody moved it in Notion since the last sync: the portal catches up, nothing is written.
        $inNotion = today()->addDays(7)->toDateString();
        $this->notionDate = $inNotion;
        $this->api($token)->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => today()->addDays(9)->toDateString(), 'from' => $current])
            ->assertStatus(409)->assertJsonPath('item.published_date', $inNotion);
        $this->assertSame($inNotion, $reel->fresh()->published_date->toDateString());
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');
    }

    public function test_if_notion_refuses_nothing_changes(): void
    {
        $this->grant($this->editor, 'view', 'edit');
        $reel = $this->reel();
        $from = $reel->published_date->toDateString();
        $this->fakeNotion($from, fn () => Http::response(['message' => 'Published Date is not a property that exists.'], 400));

        $this->api($this->token($this->editor))
            ->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => today()->addDays(5)->toDateString(), 'from' => $from])
            ->assertStatus(502)
            ->assertJsonPath('code', 'notion_failed')
            ->assertJsonPath('message', 'Notion refused the change: Published Date is not a property that exists.');

        $this->assertSame($from, $reel->fresh()->published_date->toDateString());
        $this->assertSame(0, ContentItemChange::count());
    }

    public function test_view_only_cannot_move_dates(): void
    {
        $this->grant($this->editor, 'view');
        $reel = $this->reel();

        $this->api($this->token($this->editor))
            ->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => today()->toDateString()])
            ->assertForbidden()->assertJsonPath('code', 'no_edit');
    }

    // ——— Live updates ———

    public function test_realtime_token_is_a_signed_ably_token_request_for_this_person_only(): void
    {
        $this->grant($this->editor, 'view');
        $token = $this->token($this->editor);
        $this->api($token)->postJson(route('api.planner.realtime.token'))->assertNotFound()->assertJsonPath('code', 'realtime_off');

        PlannerSetting::current()->forceFill(['ably_key' => 'appid.keyid:sekret'])->save();
        $tr = $this->api($token)->postJson(route('api.planner.realtime.token'))->assertOk()->json();

        $this->assertSame('appid.keyid', $tr['keyName']);
        $this->assertSame('user:'.$this->editor->id, $tr['clientId']);
        $this->assertSame('{"planner":["presence","subscribe"]}', $tr['capability']);
        $signed = implode("\n", [$tr['keyName'], $tr['ttl'], $tr['capability'], $tr['clientId'], $tr['timestamp'], $tr['nonce']])."\n";
        $this->assertSame(base64_encode(hash_hmac('sha256', $signed, 'sekret', true)), $tr['mac']);
        $this->api($token)->getJson(route('api.planner.me'))->assertJsonPath('realtime.enabled', true);
    }

    public function test_a_moved_date_is_published_to_everyone_after_the_response(): void
    {
        $this->grant($this->editor, 'view', 'edit');
        // The item exists before live updates are on, so its creation is not an event here.
        $reel = $this->reel();
        PlannerSetting::current()->forceFill(['ably_key' => 'appid.keyid:sekret'])->save();
        $from = $reel->published_date->toDateString();
        $this->fakeNotion($from);

        $this->api($this->token($this->editor))
            ->patchJson(route('api.planner.items.update', $reel->id), ['published_date' => today()->addDays(4)->toDateString(), 'from' => $from])->assertOk();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), 'main.realtime.ably.net/channels/planner/messages'));
        $this->assertCount(1, $sent, 'one event per item per request, however many saves');
        $message = $sent->first();
        $this->assertSame('item.updated', $message['name']);
        $data = json_decode($message['data'], true);
        $this->assertSame(today()->addDays(4)->toDateString(), $data['item']['published_date']);
        $this->assertSame('Sanjai', $data['by']['name']);
        $this->assertSame([PlannerRealtime::CHANNEL], [explode('/', parse_url($message->url(), PHP_URL_PATH))[2]]);
    }

    public function test_a_my_editing_card_moving_shows_on_the_planner_item(): void
    {
        $reel = $this->reel();
        $todo = Todo::create(['user_id' => $this->editor->id, 'title' => 'SVA - Frock', 'venture' => 'SVA Silks', 'content_item_id' => $reel->id, 'starts_on' => today(), 'due_on' => today()]);
        $before = $reel->fresh()->updated_at;

        $this->travel(2)->minutes();
        $todo->moveTo(Todo::STATUS_STARTED, $this->editor);

        $this->assertTrue($reel->fresh()->updated_at->gt($before), 'so ?updated_since= picks the editing status up');
    }

    // ——— Refresh ———

    public function test_refresh_runs_a_sync_unless_one_just_ran(): void
    {
        $this->grant($this->editor, 'view');
        $token = $this->token($this->editor);
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        $this->reel(['synced_at' => now()->subMinutes(10)]);

        $this->mock(ContentSyncService::class)->shouldReceive('syncAll')->once()->andReturn(['reel' => 1]);
        $this->api($token)->postJson(route('api.planner.refresh'))->assertOk()->assertJsonPath('refreshed', true);

        ContentItem::query()->update(['synced_at' => now()]);
        $this->api($token)->postJson(route('api.planner.refresh'))->assertOk()->assertJsonPath('refreshed', false);
    }

    // ——— Notion webhooks ———

    private function webhook(array $body, ?string $secret = 'secret_hook')
    {
        $raw = json_encode($body);
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret) {
            $headers['HTTP_X_NOTION_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', route('webhooks.notion'), [], [], [], $headers, $raw);
    }

    public function test_notion_webhooks_verify_once_then_pull_changed_pages(): void
    {
        $this->webhook(['verification_token' => 'secret_hook'], null)->assertOk();
        $this->assertSame('secret_hook', PlannerSetting::current()->notion_webhook_secret);

        // Somebody else posting a token later cannot replace it.
        $this->webhook(['verification_token' => 'attacker'], null)->assertOk();
        $this->assertSame('secret_hook', PlannerSetting::current()->notion_webhook_secret);

        $reel = $this->reel();
        $event = ['type' => 'page.properties_updated', 'entity' => ['id' => 'page-1', 'type' => 'page']];
        $this->webhook($event, 'attacker')->assertUnauthorized();

        $moved = today()->addDays(8)->toDateString();
        $this->fakeNotion($moved);
        $this->webhook($event)->assertOk()->assertJsonPath('item', $reel->id);

        $this->assertSame($moved, $reel->fresh()->published_date->toDateString());
        $change = ContentItemChange::sole();
        $this->assertSame(['published_date', 'notion', null], [$change->field, $change->origin, $change->user_id]);
        $this->assertNotNull(PlannerSetting::current()->notion_webhook_last_event_at);

        $this->webhook(['type' => 'page.deleted', 'entity' => ['id' => 'page-1', 'type' => 'page']])->assertOk();
        $this->assertNotNull($reel->fresh()->notion_missing_since);
    }

    public function test_a_page_from_another_database_is_ignored(): void
    {
        PlannerSetting::current()->forceFill(['notion_webhook_secret' => 'secret_hook'])->save();
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        Http::fake(['api.notion.com/v1/pages/*' => Http::response($this->page('other', '2026-12-01', db: 'some-other-db'))]);

        $this->webhook(['type' => 'page.created', 'entity' => ['id' => 'other', 'type' => 'page']])->assertOk();
        $this->assertSame(0, ContentItem::count());
    }

    // ——— App updates ———

    public function test_releases_are_uploaded_by_manage_and_offered_to_older_apps(): void
    {
        $this->grant($this->editor, 'view');
        $viewer = $this->token($this->editor);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'boss@x.test', 'password' => bcrypt('secret-pass')]);
        $adminToken = $this->token($admin);

        $upload = fn (string $token) => $this->api($token)->post(route('api.planner.app.releases.store'), [
            'version' => '0.2.0',
            'platform' => 'darwin-aarch64',
            'file' => UploadedFile::fake()->create('Chakra Planner.app.tar.gz', 50),
            'installer' => UploadedFile::fake()->create('Chakra Planner_0.2.0_aarch64.dmg', 80),
            'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ=',
            'notes' => 'Calendar drag',
        ]);
        $upload($viewer)->assertForbidden();
        $release = $upload($adminToken)->assertCreated()->json('release');
        $this->assertStringEndsWith('Chakra-Planner.app.tar.gz', $release['url']);

        $this->getJson(route('api.planner.app.update', ['darwin', 'aarch64', '0.1.0']))
            ->assertOk()->assertJsonPath('version', '0.2.0')->assertJsonPath('signature', 'dW50cnVzdGVkIGNvbW1lbnQ=')->assertJsonPath('notes', 'Calendar drag');
        $this->getJson(route('api.planner.app.update', ['darwin', 'aarch64', '0.2.0']))->assertNoContent();
        $this->getJson(route('api.planner.app.update', ['windows', 'x86_64', '0.1.0']))->assertNoContent();
        $this->get(route('api.planner.app.download', 'darwin-aarch64'))->assertRedirect()->assertRedirectContains('.dmg');
        $this->getJson(route('api.planner.app.latest'))->assertJsonPath('platforms.darwin-aarch64.version', '0.2.0');
    }

    public function test_the_desktop_app_origin_passes_cors(): void
    {
        $this->call('OPTIONS', '/api/planner/items', [], [], [], [
            'HTTP_ORIGIN' => 'tauri://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin');
    }

    // ——— The portal page ———

    public function test_the_portal_page_offers_the_download_and_admins_set_it_up(): void
    {
        $this->grant($this->editor, 'view');
        $this->withoutVite();
        $this->actingAs($this->editor)->get(route('planner.setup'))
            ->assertOk()->assertSee('Download')->assertSee('Open Anyway')->assertDontSee('Live updates (Ably)');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Http::fake(['main.realtime.ably.net/*' => Http::response([], 201)]);
        $this->actingAs($admin)->get(route('planner.setup'))->assertSee('Live updates (Ably)')->assertSee(route('webhooks.notion'));
        $this->actingAs($admin)->put(route('planner.settings'), ['ably_key' => 'appid.keyid:sekret'])
            ->assertSessionHas('status', 'Ably key saved — live updates are on.');
        $this->assertTrue(PlannerSetting::current()->realtimeEnabled());
        $this->actingAs($this->editor)->put(route('planner.settings'), ['ably_key' => 'x.y:z'])->assertForbidden();
    }
    // ——— The web version ———

    public function test_the_web_planner_reads_and_moves_dates_with_the_portal_login(): void
    {
        $this->grant($this->editor, 'view');
        $reel = $this->reel();
        $from = $reel->published_date->toDateString();
        $to = today()->addDays(5)->toDateString();
        $this->withoutVite();

        $this->actingAs($this->editor)->get(route('planner.index'))->assertOk()->assertSee('plannerWeb', false);
        $this->getJson(route('planner.data.items', ['from' => today()->subDays(3)->toDateString(), 'to' => today()->addDays(40)->toDateString()]))
            ->assertOk()->assertJsonPath('data.0.id', $reel->id);
        $this->getJson(route('planner.data.meta'))->assertOk()->assertJsonPath('sources.0.count', 1);

        // View only: the date stays put.
        $this->patchJson(route('planner.data.move', $reel->id), ['published_date' => $to, 'from' => $from])->assertForbidden();

        $this->grant($this->editor, 'edit');
        $this->fakeNotion($from);
        $this->actingAs($this->editor->fresh())->patchJson(route('planner.data.move', $reel->id), ['published_date' => $to, 'from' => $from])
            ->assertOk()->assertJsonPath('item.published_date', $to);
        $this->assertSame($to, $reel->fresh()->published_date->toDateString());

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->get(route('planner.index'))->assertForbidden();
    }
    // ——— The top-bar editing widget ———

    public function test_the_widget_adds_and_moves_your_videos_and_anyone_posts_a_ready_one(): void
    {
        $token = $this->token($this->editor);
        $id = $this->api($token)->postJson(route('api.planner.editing.store'), ['title' => 'SVA - Cotton Frock'])->assertOk()->json('card.id');
        $this->api($token)->postJson(route('api.planner.editing.act', $id), ['action' => 'start'])->assertOk();
        $this->api($token)->postJson(route('api.planner.editing.act', $id), ['action' => 'hold', 'note' => 'Waiting for music'])->assertOk();
        $this->api($token)->postJson(route('api.planner.editing.act', $id), ['action' => 'start'])->assertOk();
        $this->api($token)->postJson(route('api.planner.editing.act', $id), ['action' => 'done'])->assertOk();
        $this->api($token)->getJson(route('api.planner.editing.board'))->assertOk()->assertJsonPath('cards.0.title', 'SVA - Cotton Frock');

        // Someone else: cannot edit it, cannot deliver it while in review...
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'email' => 'gokul@chakra.test', 'password' => 'secret-pass']);
        $theirs = $this->token($other);
        $this->api($theirs)->postJson(route('api.planner.editing.act', $id), ['action' => 'start'])->assertNotFound();
        $this->api($theirs)->postJson(route('api.planner.editing.act', $id), ['action' => 'deliver'])->assertStatus(422);

        // ...but once approved, it is on their widget and they can post it.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->postJson(route('my.editing.act', $id), ['action' => 'ready', 'publish_at' => now()->addHour()->format('Y-m-d H:i')])->assertOk();
        $this->api($theirs)->getJson(route('api.planner.editing.board'))->assertJsonPath('team_ready.0.id', $id);
        $this->api($theirs)->postJson(route('api.planner.editing.act', $id), ['action' => 'post', 'kind' => 'reel'])->assertOk();
        $this->assertNotNull(\App\Models\Todo::find($id)->posted_at);
        $this->assertSame($other->id, \App\Models\Todo::find($id)->posted_by_id);
    }
}
