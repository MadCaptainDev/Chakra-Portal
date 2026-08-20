<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Services\Notion\ContentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which Notion database(s) a source actually reads from.
 *
 * Regression coverage for a real production bug: the workspace contains
 * twelve databases whose titles contain "content planner" (one real, eleven
 * empty duplicates) and two each for "reel planner" and "post planner".
 * Resolution was by title substring and returned whichever database
 * Notion's search happened to list first, which is not a guaranteed order
 * -- so one sync wrote 465 post rows from one database and the next wrote
 * 60 from another, leaving content_items holding a mix from two sources.
 *
 * Worse, the two Post Planner databases hold genuinely DIFFERENT content
 * (verified: zero overlapping titles), so reading either one alone silently
 * lost half the studio's posts.
 */
class NotionDatabaseResolutionTest extends TestCase
{
    use RefreshDatabase;

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

    private function page(string $id, string $title): array
    {
        return [
            'id' => $id,
            'url' => 'https://notion.so/'.$id,
            'created_time' => '2026-01-01T00:00:00.000Z',
            'properties' => [
                'Name' => ['type' => 'title', 'title' => [['plain_text' => $title]]],
            ],
        ];
    }

    public function test_a_source_reads_from_every_configured_database(): void
    {
        config(['notion.databases.post' => [
            'label' => 'Post',
            'name_contains' => 'post planner',
            'ids' => ['db-new', 'db-old'],
        ]]);

        Http::fake(array_merge($this->fakeSearch([
            'db-new' => 'Post Planner - Instagram',
            'db-old' => 'Post Planner - Instagram (1)',
        ]), [
            'api.notion.com/v1/databases/db-new/query' => Http::response([
                'results' => [$this->page('p-new', 'August post')],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/db-old/query' => Http::response([
                'results' => [$this->page('p-old', 'May post')],
                'has_more' => false,
            ]),
        ]));

        $synced = app(ContentSyncService::class)->syncSource('post');

        // Both databases, not whichever search listed first.
        $this->assertSame(2, $synced);
        $this->assertSame('August post', ContentItem::where('notion_page_id', 'p-new')->value('title'));
        $this->assertSame('May post', ContentItem::where('notion_page_id', 'p-old')->value('title'));
    }

    public function test_configured_ids_win_over_a_title_match(): void
    {
        // The decoy's title matches name_contains and is listed FIRST, which
        // is exactly how the old resolver picked the wrong database.
        config(['notion.databases.post' => [
            'label' => 'Post',
            'name_contains' => 'post planner',
            'ids' => ['db-real'],
        ]]);

        Http::fake(array_merge($this->fakeSearch([
            'db-decoy' => 'Post Planner - Instagram (1)',
            'db-real' => 'Post Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/db-real/query' => Http::response([
                'results' => [$this->page('p-real', 'The real one')],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/db-decoy/query' => Http::response([
                'results' => [$this->page('p-decoy', 'The decoy')],
                'has_more' => false,
            ]),
        ]));

        app(ContentSyncService::class)->syncSource('post');

        $this->assertNotNull(ContentItem::where('notion_page_id', 'p-real')->first());
        $this->assertNull(ContentItem::where('notion_page_id', 'p-decoy')->first());
    }

    public function test_dashes_in_a_configured_id_still_match_the_api_form(): void
    {
        config(['notion.databases.story' => [
            'label' => 'Story',
            'name_contains' => 'story tracker',
            'ids' => ['37d32af9-aff5-8018-a74b-c397079c1df3'],
        ]]);

        Http::fake(array_merge($this->fakeSearch([
            // Same id, undashed -- both forms are legal in Notion.
            '37d32af9aff58018a74bc397079c1df3' => 'Chakra - Story Tracker',
        ]), [
            'api.notion.com/v1/databases/*/query' => Http::response([
                'results' => [$this->page('s-1', 'A story')],
                'has_more' => false,
            ]),
        ]));

        $this->assertSame(1, app(ContentSyncService::class)->syncSource('story'));
    }

    public function test_an_unreachable_configured_id_falls_back_to_a_title_match(): void
    {
        // The self-healing case the title search exists for: the database
        // was duplicated or recreated, so the pinned id is gone.
        config(['notion.databases.reel' => [
            'label' => 'Reel',
            'name_contains' => 'reel planner',
            'ids' => ['db-that-no-longer-exists'],
        ]]);

        Http::fake(array_merge($this->fakeSearch([
            'db-recreated' => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/db-recreated/query' => Http::response([
                'results' => [$this->page('r-1', 'Recovered reel')],
                'has_more' => false,
            ]),
        ]));

        app(ContentSyncService::class)->syncSource('reel');

        $this->assertSame('Recovered reel', ContentItem::where('notion_page_id', 'r-1')->value('title'));
    }

    public function test_the_title_fallback_prefers_the_original_over_a_numbered_copy(): void
    {
        config(['notion.databases.reel' => [
            'label' => 'Reel',
            'name_contains' => 'reel planner',
            'ids' => ['gone'],
        ]]);

        // The copy is listed first; the resolver must still choose the
        // original rather than trusting search order.
        Http::fake(array_merge($this->fakeSearch([
            'db-copy' => 'Reel Planner - Instagram (1)',
            'db-original' => 'Reel Planner - Instagram',
        ]), [
            'api.notion.com/v1/databases/db-original/query' => Http::response([
                'results' => [$this->page('r-orig', 'From the original')],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/db-copy/query' => Http::response([
                'results' => [$this->page('r-copy', 'From the copy')],
                'has_more' => false,
            ]),
        ]));

        app(ContentSyncService::class)->syncSource('reel');

        $this->assertNotNull(ContentItem::where('notion_page_id', 'r-orig')->first());
        $this->assertNull(ContentItem::where('notion_page_id', 'r-copy')->first());
    }

    public function test_one_unreachable_database_does_not_cost_its_siblings_rows(): void
    {
        config(['notion.databases.post' => [
            'label' => 'Post',
            'name_contains' => 'post planner',
            'ids' => ['db-ok', 'db-broken'],
        ]]);

        Http::fake(array_merge($this->fakeSearch([
            'db-ok' => 'Post Planner - Instagram',
            'db-broken' => 'Post Planner - Instagram (1)',
        ]), [
            'api.notion.com/v1/databases/db-ok/query' => Http::response([
                'results' => [$this->page('p-ok', 'Survived')],
                'has_more' => false,
            ]),
            'api.notion.com/v1/databases/db-broken/query' => Http::response(['message' => 'Not found'], 404),
        ]));

        $synced = app(ContentSyncService::class)->syncSource('post');

        $this->assertSame(1, $synced);
        $this->assertSame('Survived', ContentItem::where('notion_page_id', 'p-ok')->value('title'));
    }
}
