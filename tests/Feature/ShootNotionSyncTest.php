<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\NotionSetting;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootEvent;
use App\Models\User;
use App\Services\Notion\NotionShootImporter;
use App\Services\ShootMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One shoot in both places: made in Chakra goes to Notion, and the import never doubles it. */
class ShootNotionSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SHOOTS_DB = '61632af9-aff5-8244-a7a9-0169f924c9d9';

    private Client $client;

    /** The fake Notion page, built from what the portal sent. */
    private array $page = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['name' => 'Squad Architects']);
        NotionSetting::current()->update(['api_key' => 'secret_test']);

        Http::fake(['api.notion.com/v1/pages*' => function (HttpRequest $r) {
            $props = $r['properties'] ?? [];
            $this->page = array_replace_recursive($this->page ?: [
                'object' => 'page', 'id' => 'new-page-1', 'url' => 'https://www.notion.so/new-page-1', 'created_time' => now()->toIso8601String(),
                'parent' => ['database_id' => self::SHOOTS_DB], 'properties' => [],
            ], ['properties' => collect($props)->map(function ($v) {
                $type = array_key_first($v);
                $body = $v[$type];
                if (in_array($type, ['title', 'rich_text'], true)) {
                    $body = array_map(fn ($p) => ['plain_text' => $p['text']['content']], $body);
                }

                return ['type' => $type, $type => $body];
            })->all()]);

            return Http::response($this->page);
        }, '*' => Http::response([], 404)]);
    }

    /** Run what the app does after the response (defer()). */
    private function afterResponse(): void
    {
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
    }

    public function test_a_shoot_booked_in_chakra_is_created_in_notion_and_linked(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Aron Sham']);
        NotionShoot::factory()->create(['client_id' => $this->client->id, 'client' => 'Squad Architects', 'shoot_date' => now()->subMonth()]);

        $shoot = Shoot::create(['title' => 'Site walkthrough', 'client_id' => $this->client->id, 'starts_at' => now()->addDays(2)->setTime(10, 30), 'location' => 'Trichy', 'status' => Shoot::STATUS_CONFIRMED]);
        $this->afterResponse();
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $admin->id]);
        $this->afterResponse();

        $shoot->refresh();
        $this->assertNotNull($shoot->notion_shoot_id);
        $this->assertSame('new-page-1', $shoot->notionShoot->notion_page_id);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/pages')
            && $r['parent']['database_id'] === self::SHOOTS_DB
            && $r['properties']['Shoot Name']['title'][0]['text']['content'] === 'Site walkthrough'
            && $r['properties']['Client']['select']['name'] === 'Squad Architects'
            && str_starts_with($r['properties']['Date']['date']['start'], now()->addDays(2)->toDateString().'T10:30')
            && $r['properties']['Status']['select']['name'] === 'Planned'
            && $r['properties']['Client Conformation ']['select']['name'] === 'Yes');
        // Adding crew writes the Team.
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && ($r['properties']['Team']['multi_select'][0]['name'] ?? null) === 'Aron');

        // The next import refreshes it, it does not add a second one.
        app(NotionShootImporter::class)->importAll();
        $this->assertSame(1, Shoot::where('client_id', $this->client->id)->whereDate('starts_at', now()->addDays(2))->count());
    }

    public function test_a_change_in_chakra_is_written_to_the_notion_page(): void
    {
        $ns = NotionShoot::factory()->create(['notion_page_id' => 'page-9', 'client_id' => $this->client->id, 'client' => 'Squad Architects', 'shoot_date' => now()->addDay()]);
        $shoot = Shoot::create(['title' => 'Old name', 'client_id' => $this->client->id, 'starts_at' => now()->addDay()->startOfDay(), 'status' => Shoot::STATUS_PLANNED, 'notion_shoot_id' => $ns->id]);
        $this->afterResponse();
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');

        $shoot->update(['title' => 'New name', 'location' => 'Chennai']);
        $this->afterResponse();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/v1/pages/page-9')
            && $r['properties']['Shoot Name']['title'][0]['text']['content'] === 'New name'
            && $r['properties']['Location']['rich_text'][0]['text']['content'] === 'Chennai'
            && ! isset($r['properties']['Status']));
    }

    public function test_the_import_links_a_shoot_already_booked_in_chakra_instead_of_copying_it(): void
    {
        NotionSetting::current()->update(['api_key' => null]); // Notion not reachable when it was booked
        $booked = Shoot::create(['title' => 'Booked here', 'client_id' => $this->client->id, 'starts_at' => now()->addDay()->setTime(9, 0), 'status' => Shoot::STATUS_CONFIRMED]);
        $this->afterResponse();
        $this->assertNull($booked->fresh()->notion_shoot_id);

        NotionSetting::current()->update(['api_key' => 'secret_test']);
        $ns = NotionShoot::factory()->create(['client_id' => $this->client->id, 'shoot_date' => now()->addDay(), 'title' => 'Made in Notion', 'status' => 'Planned']);
        app(NotionShootImporter::class)->importAll();

        $this->assertSame(1, Shoot::count());
        $booked->refresh();
        $this->assertSame($ns->id, $booked->notion_shoot_id);
        $this->assertSame('09:00', $booked->starts_at->format('H:i'), 'The call time set in Chakra is kept.');
    }

    public function test_existing_duplicates_are_merged_into_one_with_everything_kept(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $crew = User::factory()->create();
        $portal = Shoot::create(['title' => 'Mahavir Shoot', 'client_id' => $this->client->id, 'starts_at' => now()->addDay()->setTime(11, 0), 'status' => Shoot::STATUS_CONFIRMED, 'notes' => 'Bring the drone']);
        ShootCrew::create(['shoot_id' => $portal->id, 'user_id' => $crew->id]);
        ShootEvent::record($portal, $crew, ShootEvent::DEPARTED);
        $ns = NotionShoot::factory()->create(['client_id' => $this->client->id, 'shoot_date' => now()->addDay()]);
        $fromNotion = Shoot::query()->forceCreate(['title' => 'Mahavir Shoot', 'client_id' => $this->client->id, 'starts_at' => now()->addDay()->startOfDay(), 'status' => Shoot::STATUS_PLANNED, 'notion_shoot_id' => $ns->id]);

        $this->assertCount(1, ShootMerger::pairs());
        $this->artisan('shoots:fix-duplicates', ['--dry' => true])->assertSuccessful();
        $this->assertSame(2, Shoot::count());

        $this->artisan('shoots:fix-duplicates')->assertSuccessful();

        $this->assertSame(1, Shoot::count());
        $kept = Shoot::first();
        $this->assertSame($ns->id, $kept->notion_shoot_id);
        $this->assertSame('11:00', $kept->starts_at->format('H:i'));
        $this->assertSame('Bring the drone', $kept->notes);
        $this->assertSame(Shoot::STATUS_CONFIRMED, $kept->status);
        $this->assertSame([$crew->id], $kept->crew()->pluck('user_id')->all());
        $this->assertSame(1, $kept->events()->where('type', ShootEvent::DEPARTED)->count());
    }

    public function test_two_different_notion_shoots_on_one_day_are_left_alone(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        foreach (['SVA Dance Shoot', 'SVA Photo Shoot'] as $title) {
            $ns = NotionShoot::factory()->create(['client_id' => $this->client->id, 'shoot_date' => now()->addDay(), 'title' => $title]);
            app(NotionShootImporter::class)->import($ns);
        }

        $this->assertCount(0, ShootMerger::pairs());
        $this->assertCount(1, ShootMerger::notionSide());
        $this->artisan('shoots:fix-duplicates')->assertSuccessful();
        $this->assertSame(2, Shoot::count());
    }
}
