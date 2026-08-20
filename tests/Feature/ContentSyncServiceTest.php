<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Services\Notion\ContentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REEL_ID = 'reel-db-id';

    private const POST_ID = 'post-db-id';

    protected function setUp(): void
    {
        parent::setUp();

        NotionSetting::current()->update(['api_key' => 'secret_test']);
    }

    /**
     * The workspace-search response the service uses to resolve database ids
     * by title. Only the databases listed here count as "shared".
     *
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

    private function reelPage(): array
    {
        return [
            'id' => 'reel-page-1',
            'url' => 'https://notion.so/reel-page-1',
            'created_time' => '2026-01-01T00:00:00.000Z',
            'properties' => [
                'Videos' => ['type' => 'title', 'title' => [['plain_text' => 'Sample Reel']]],
                'Venture' => ['type' => 'select', 'select' => ['name' => 'THOR']],
                // The planners really do have a status property named "".
                '' => ['type' => 'select', 'select' => ['name' => 'Video Ready']],
                'Published Date' => ['type' => 'date', 'date' => ['start' => '2026-02-02']],
                'Shoot Date' => ['type' => 'date', 'date' => ['start' => '2026-01-20']],
                // Trailing space, and multi_select here but select in the Post planner.
                'Editor ' => ['type' => 'multi_select', 'multi_select' => [['name' => 'Gokul'], ['name' => 'Sanjai']]],
                'Tier' => ['type' => 'select', 'select' => ['name' => 'Medium Effort & Work']],
                'Post Type' => ['type' => 'select', 'select' => ['name' => 'Reel']],
                'SSD' => ['type' => 'select', 'select' => ['name' => 'SSD 1']],
                'Effort Hours' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => '4h']]],
                'Insta CSV Link' => ['type' => 'url', 'url' => 'https://example.com/insta.csv'],
                'Script' => ['type' => 'rich_text', 'rich_text' => [['plain_text' => 'Open on a wide shot.']]],
            ],
        ];
    }

    public function test_resolves_databases_by_title_and_maps_every_field(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::REEL_ID => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/'.self::REEL_ID.'/query' => Http::response([
                'results' => [$this->reelPage()],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $counts = app(ContentSyncService::class)->syncAll();

        $this->assertSame(1, $counts['reel']);

        $item = ContentItem::where('notion_page_id', 'reel-page-1')->firstOrFail();
        $this->assertSame('reel', $item->source);
        $this->assertSame('Sample Reel', $item->title);
        $this->assertSame('THOR', $item->venture);
        $this->assertSame('Video Ready', $item->status);
        $this->assertSame('Gokul, Sanjai', $item->editor, 'multi_select editors should be comma-joined');
        $this->assertSame('Medium Effort & Work', $item->tier);
        $this->assertSame('Reel', $item->post_type);
        $this->assertSame('SSD 1', $item->ssd);
        $this->assertSame('4h', $item->effort_hours);
        $this->assertSame('https://example.com/insta.csv', $item->insta_csv_link);
        $this->assertSame('Open on a wide shot.', $item->script);
        $this->assertSame('2026-02-02', $item->published_date->format('Y-m-d'));
        $this->assertSame('2026-01-20', $item->shoot_date->format('Y-m-d'));
    }

    public function test_reads_a_select_editor_property_as_well_as_multi_select(): void
    {
        $page = $this->reelPage();
        $page['id'] = 'post-page-1';
        // Post planner: same trailing-space name, different type.
        $page['properties']['Editor '] = ['type' => 'select', 'select' => ['name' => 'Sanjai']];

        Http::fake(array_merge($this->fakeSearch([
            self::POST_ID => 'Post Planner - Instagram (1)',
        ]), [
            'api.notion.com/v1/databases/'.self::POST_ID.'/query' => Http::response([
                'results' => [$page],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        app(ContentSyncService::class)->syncSource('post');

        $item = ContentItem::where('notion_page_id', 'post-page-1')->firstOrFail();
        $this->assertSame('post', $item->source);
        $this->assertSame('Sanjai', $item->editor);
    }

    public function test_unshared_database_is_skipped_rather_than_silently_zero(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::REEL_ID => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/'.self::REEL_ID.'/query' => Http::response([
                'results' => [$this->reelPage()],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $service = app(ContentSyncService::class);
        $counts = $service->syncAll();

        $this->assertSame(['youtube' => 0, 'reel' => 1, 'post' => 0, 'story' => 0, 'shoot' => 0], $counts);
        $this->assertSame(
            ['youtube' => false, 'reel' => true, 'post' => false, 'story' => false, 'shoot' => false],
            $service->sourceAvailability()
        );
    }

    public function test_follows_pagination(): void
    {
        $first = $this->reelPage();
        $second = $this->reelPage();
        $second['id'] = 'reel-page-2';

        Http::fake(array_merge($this->fakeSearch([
            self::REEL_ID => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/'.self::REEL_ID.'/query' => Http::sequence()
                ->push(['results' => [$first], 'has_more' => true, 'next_cursor' => 'cursor-2'])
                ->push(['results' => [$second], 'has_more' => false]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $this->assertSame(2, app(ContentSyncService::class)->syncSource('reel'));
        $this->assertDatabaseCount('content_items', 2);
    }

    public function test_re_running_sync_does_not_duplicate_rows(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::REEL_ID => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/'.self::REEL_ID.'/query' => Http::response([
                'results' => [$this->reelPage()],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/*' => Http::response(['results' => [], 'has_more' => false]),
        ]));

        $service = app(ContentSyncService::class);
        $service->syncSource('reel');
        $service->syncSource('reel');

        $this->assertDatabaseCount('content_items', 1);
    }

    public function test_sync_failure_returns_zero_and_does_not_throw(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::REEL_ID => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]));

        $this->assertSame(0, app(ContentSyncService::class)->syncSource('reel'));
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_sync_without_api_key_is_a_no_op(): void
    {
        NotionSetting::current()->forceFill(['api_key' => null])->save();
        Http::fake();

        $counts = app(ContentSyncService::class)->syncAll();

        $this->assertSame(['youtube' => 0, 'reel' => 0, 'post' => 0, 'story' => 0, 'shoot' => 0], $counts);
        Http::assertNothingSent();
    }

    public function test_venture_options_merges_and_dedupes_across_databases(): void
    {
        Http::fake(array_merge($this->fakeSearch([
            self::REEL_ID => 'Reel Planner - Instagram',
            self::POST_ID => 'Post Planner - Instagram (1)',
        ]), [
            'api.notion.com/v1/databases/'.self::REEL_ID => Http::response([
                'properties' => ['Venture' => ['type' => 'select', 'select' => ['options' => [['name' => 'THOR'], ['name' => 'SVA Silks']]]]],
            ]),
            'api.notion.com/v1/databases/'.self::POST_ID => Http::response([
                'properties' => ['Venture' => ['type' => 'select', 'select' => ['options' => [['name' => 'THOR'], ['name' => 'Amar Dental']]]]],
            ]),
        ]));

        $options = app(ContentSyncService::class)->ventureOptions();

        $this->assertSame(['Amar Dental', 'SVA Silks', 'THOR'], $options);
    }
}
