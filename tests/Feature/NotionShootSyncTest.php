<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Models\NotionShoot;
use App\Services\Notion\ContentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Syncing Notion's "Shoots" production-scheduling database into
 * notion_shoots -- a separate table and a separate test file from
 * ContentSyncServiceTest on purpose, so that suite stays an untouched
 * regression baseline for the 4 pre-existing content sources.
 *
 * Fixture shapes below are not guessed: they match the real, live "Shoots"
 * database schema and sample rows, confirmed against a real connected
 * integration while this feature was planned.
 */
class NotionShootSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SHOOT_ID = 'shoots-db-id';

    protected function setUp(): void
    {
        parent::setUp();

        NotionSetting::current()->update(['api_key' => 'secret_test']);
    }

    /**
     * @param  array<string, string>  $databases  id => title
     */
    private function fakeSearch(array $databases): array
    {
        return [
            'api.notion.com/v1/search' => Http::response([
                'results' => collect($databases)->map(fn ($title, $id) => [
                    'id' => $id,
                    'title' => [['plain_text' => $title]],
                ])->values()->all(),
                'has_more' => false,
            ]),
        ];
    }

    private function shootPage(): array
    {
        return [
            'id' => 'shoot-page-1',
            'url' => 'https://notion.so/shoot-page-1',
            'created_time' => '2026-05-01T00:00:00.000Z',
            'properties' => [
                'Shoot Name' => ['type' => 'title', 'title' => [['plain_text' => 'SVA Golds Product Shoot']]],
                'Status' => ['type' => 'select', 'select' => ['name' => 'Shooting']],
                'Client' => ['type' => 'select', 'select' => ['name' => 'SVA']],
                'Team' => ['type' => 'multi_select', 'multi_select' => [['name' => 'Aron'], ['name' => 'Gokul']]],
                'Host / Model' => ['type' => 'multi_select', 'multi_select' => [['name' => 'Harsha']]],
                'Location' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => 'Studio A']]],
                'Date' => ['type' => 'date', 'date' => ['start' => '2026-06-15']],
                'Duration' => ['type' => 'number', 'number' => 3.5],
                'No Of Videos' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => '5-6']]],
                'Gear Needed' => ['type' => 'multi_select', 'multi_select' => [['name' => 'Tripod/Stabilizer'], ['name' => 'Camera Body']]],
                'Weather Forecast' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => 'Clear, 28°C']]],
                'Photo' => ['type' => 'files', 'files' => [
                    ['type' => 'file', 'file' => ['url' => 'https://s3.example.com/internal-photo.jpg']],
                ]],
                'Reels' => ['type' => 'relation', 'relation' => [['id' => 'reel-page-a'], ['id' => 'reel-page-b']]],
                'Shot List' => ['type' => 'relation', 'relation' => []],
            ],
        ];
    }

    public function test_shoots_database_is_resolved_and_every_field_maps(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::SHOOT_ID => 'Shoots',
        ]), [
            'api.notion.com/v1/databases/'.self::SHOOT_ID.'/query' => Http::response([
                'results' => [$this->shootPage()],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $synced = app(ContentSyncService::class)->syncSource('shoot');

        $this->assertSame(1, $synced);

        $shoot = NotionShoot::where('notion_page_id', 'shoot-page-1')->firstOrFail();

        $this->assertSame('SVA Golds Product Shoot', $shoot->title);
        $this->assertSame('Shooting', $shoot->status);
        $this->assertSame('SVA', $shoot->client);
        $this->assertSame('Aron, Gokul', $shoot->team);
        $this->assertSame('Harsha', $shoot->host_model);
        $this->assertSame('Studio A', $shoot->location);
        $this->assertSame('2026-06-15', $shoot->shoot_date->toDateString());
        $this->assertSame('3.50', $shoot->duration);
        $this->assertSame('5-6', $shoot->video_count);
        $this->assertSame('Tripod/Stabilizer, Camera Body', $shoot->gear_needed);
        $this->assertSame('Clear, 28°C', $shoot->weather_forecast);
        $this->assertSame('https://s3.example.com/internal-photo.jpg', $shoot->photo_url);

        // A shoots-only sync must never write to content_items -- the two
        // pipelines are fully separate.
        $this->assertSame(0, ContentItem::count());
    }

    public function test_an_external_photo_url_maps_the_same_as_an_internal_one(): void
    {
        $page = $this->shootPage();
        $page['id'] = 'shoot-page-2';
        $page['properties']['Photo'] = ['type' => 'files', 'files' => [
            ['type' => 'external', 'external' => ['url' => 'https://cdn.example.com/external-photo.jpg']],
        ]];

        Http::fake(array_merge($this->fakeSearch([
            self::SHOOT_ID => 'Shoots',
        ]), [
            'api.notion.com/v1/databases/'.self::SHOOT_ID.'/query' => Http::response([
                'results' => [$page],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        app(ContentSyncService::class)->syncSource('shoot');

        $shoot = NotionShoot::where('notion_page_id', 'shoot-page-2')->firstOrFail();
        $this->assertSame('https://cdn.example.com/external-photo.jpg', $shoot->photo_url);
    }

    public function test_relation_properties_contribute_nothing_and_do_not_error(): void
    {
        // shootPage() already carries two relation properties (Reels,
        // Shot List) -- their mere presence, non-empty in one case, must
        // not crash or leak into any stored column.
        Http::fake(array_merge($this->fakeSearch([
            self::SHOOT_ID => 'Shoots',
        ]), [
            'api.notion.com/v1/databases/'.self::SHOOT_ID.'/query' => Http::response([
                'results' => [$this->shootPage()],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $synced = app(ContentSyncService::class)->syncSource('shoot');

        $this->assertSame(1, $synced);
    }

    public function test_an_unrecognized_property_type_maps_to_null_rather_than_erroring(): void
    {
        $page = $this->shootPage();
        $page['properties']['Place'] = ['type' => 'place', 'place' => ['name' => 'Somewhere']];

        Http::fake(array_merge($this->fakeSearch([
            self::SHOOT_ID => 'Shoots',
        ]), [
            'api.notion.com/v1/databases/'.self::SHOOT_ID.'/query' => Http::response([
                'results' => [$page],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $synced = app(ContentSyncService::class)->syncSource('shoot');

        $this->assertSame(1, $synced);
    }

    public function test_resyncing_updates_the_row_rather_than_duplicating_it(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::SHOOT_ID => 'Shoots',
        ]), [
            'api.notion.com/v1/databases/'.self::SHOOT_ID.'/query' => Http::response([
                'results' => [$this->shootPage()],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $service = app(ContentSyncService::class);
        $service->syncSource('shoot');
        $first = NotionShoot::sole()->synced_at;

        $this->travel(1)->minutes();
        $service->syncSource('shoot');

        $this->assertSame(1, NotionShoot::count());
        $this->assertTrue(NotionShoot::sole()->synced_at->gt($first));
    }

    public function test_an_unshared_shoots_database_is_skipped_rather_than_silently_zero(): void
    {
        // Something IS shared with the integration -- just not Shoots. An
        // entirely empty search result means something different (the
        // integration can see nothing at all, e.g. a failed call), which
        // resolveDatabaseId() deliberately treats as "trust the configured
        // id" rather than "definitely unshared" -- see its own comment.
        Http::fake($this->fakeSearch(['other-db-id' => 'Some Unrelated Database']));

        $service = app(ContentSyncService::class);

        $this->assertSame(0, $service->syncSource('shoot'));
        $this->assertFalse($service->sourceAvailability()['shoot']);
        $this->assertSame(0, NotionShoot::count());
    }

    public function test_venture_options_still_works_when_shoots_has_no_venture_property(): void
    {
        // The Shoots database has no "Venture" select at all -- confirms
        // ventureOptions() (which now also queries it, since it iterates
        // every configured source) degrades gracefully rather than erroring
        // on a database shaped nothing like the content ones.
        Http::fake(array_merge($this->fakeSearch([
            self::SHOOT_ID => 'Shoots',
        ]), [
            'api.notion.com/v1/databases/'.self::SHOOT_ID => Http::response([
                'properties' => [
                    'Shoot Name' => ['type' => 'title'],
                    'Client' => ['type' => 'select', 'select' => ['options' => [['name' => 'SVA']]]],
                ],
            ]),
        ]));

        $options = app(ContentSyncService::class)->ventureOptions();

        $this->assertSame([], $options);
    }
}
