<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Support\ContentForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Runs out on": the last video in hand, by its date, then the target's pace. */
class ContentRunwayTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 09:00');
        $this->client = Client::factory()->create(['name' => 'Suryas Groups of Companies']);
        $account = ContentAccount::create(['client_id' => $this->client->id, 'name' => 'Surya’s Restaurant', 'target_reel' => 15]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'Surya’s Restaurant']);
    }

    private function reel(string $status, ?string $date, string $source = 'reel'): void
    {
        ContentItem::factory()->create(['source' => $source, 'venture' => 'Surya’s Restaurant', 'status' => $status,
            'title' => 'Suryas - '.uniqid(), 'notion_page_id' => 'p-'.uniqid('', true), 'published_date' => $date]);
    }

    public function test_dated_videos_run_out_on_the_last_date_not_an_even_rate_from_today(): void
    {
        $this->reel('To Be Edited', '2026-11-13');
        $this->reel('To Be Edited', '2026-11-14');
        $this->reel('To Be Edited', '2026-11-14', 'youtube'); // the YouTube copy is not another video
        $this->reel('To Be Shooted', '2026-11-20');           // not shot: not runway

        $f = ContentForecast::forClient($this->client);

        $this->assertSame(2, $f['remaining']);
        $this->assertSame('2026-11-14', $f['depletion_date']->toDateString());
    }

    public function test_undated_videos_follow_the_last_dated_one_at_the_targets_pace(): void
    {
        $this->reel('Video Ready', '2026-11-14');
        $this->reel('To Be Edited', null);
        $this->reel('To Be Edited', '2026-11-01'); // its date passed: still to go out, so undated

        // 15 a month is one every ~2 days: two more after the 14th.
        $this->assertSame('2026-11-18', ContentForecast::forClient($this->client)['depletion_date']->toDateString());
    }
}
