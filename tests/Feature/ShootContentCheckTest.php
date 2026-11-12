<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Support\ShootContentCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** "Completed shoots not in the Reel Planner yet" counts only shoots really missing there. */
class ShootContentCheckTest extends TestCase
{
    use RefreshDatabase;

    private int $page = 0;

    private function client(string $name, string $venture): Client
    {
        $client = Client::factory()->create(['name' => $name]);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => $name]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => $venture]);

        return $client;
    }

    private function shoot(Client $client, string $day, string $title = 'Shoot'): Shoot
    {
        return Shoot::create(['title' => $title, 'client_id' => $client->id, 'starts_at' => $day.' 10:00', 'status' => Shoot::STATUS_COMPLETED]);
    }

    private function reel(string $venture, string $status, string $made, ?int $notionShootId = null): ContentItem
    {
        $item = ContentItem::create(['source' => ContentItem::SOURCE_REEL, 'notion_page_id' => 'p'.(++$this->page), 'title' => 'Reel '.$this->page,
            'venture' => $venture, 'status' => $status, 'notion_shoot_id' => $notionShootId]);
        $item->forceFill(['created_at' => $made])->save();

        return $item;
    }

    public function test_reels_with_the_shoot_field_left_empty_still_count_as_in_the_planner(): void
    {
        $this->travelTo('2026-11-13 09:00');
        $thillai = $this->client('Thillai Pets Clinic', 'Thillai Pets Clinic');
        $revanth = $this->client('Dr.Revanth, Ortho', 'Dr.Revanth'); // the venture only matches through the account
        $karpagam = $this->client('BrandMusk', 'BrandMusk');
        $mahavir = $this->client('Mahavir Timbers', 'Mahavir');

        $covered = $this->shoot($thillai, '2026-11-06');
        $this->reel('Thillai Pets Clinic', 'To Be Edited', '2026-11-10 12:00');
        $byAccount = $this->shoot($revanth, '2026-11-01');
        $this->reel('Dr.Revanth', 'Published', '2026-11-10 12:00');
        $nothing = $this->shoot($karpagam, '2026-11-05');
        // Still only an idea, or made long after: not this shoot's footage.
        $notShot = $this->shoot($mahavir, '2026-11-02');
        $this->reel('Mahavir', 'To Be Shot', '2026-11-03 12:00');
        $this->reel('Mahavir', 'To Be Edited', '2026-11-25 12:00');
        $this->shoot($karpagam, '2026-11-07', 'Test');

        $this->assertEqualsCanonicalizing([$nothing->id, $notShot->id], ShootContentCheck::missing()->pluck('id')->all());
        $this->assertNotContains($covered->id, ShootContentCheck::missing()->pluck('id'));
        $this->assertNotContains($byAccount->id, ShootContentCheck::missing()->pluck('id'));
    }

    public function test_the_twin_of_a_shoot_booked_twice_is_not_missing(): void
    {
        $sva = $this->client('SVA Silks', 'SVA');
        $notion = NotionShoot::create(['notion_page_id' => (string) Str::uuid(), 'title' => 'SVA Tier 2 Shoot', 'shoot_date' => '2026-11-01', 'client_id' => $sva->id]);
        $linked = $this->shoot($sva, '2026-11-01', 'SVA Tier 2 Shoot');
        $linked->forceFill(['notion_shoot_id' => $notion->id])->save();
        $this->reel('SVA', 'Published', '2026-10-01 12:00', $notion->id);
        $twin = $this->shoot($sva, '2026-11-01', 'SVA Tier -II');

        $this->assertNotContains($twin->id, ShootContentCheck::missing()->pluck('id'));
        $this->assertNotContains($linked->id, ShootContentCheck::missing()->pluck('id'));
    }
}
