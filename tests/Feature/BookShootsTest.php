<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Book shoots (the Forecast page): clients about to run out of reels come
 * first with a Book shoot button; covered and untargeted ones fold away.
 */
class BookShootsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_out_of_reels_is_first_with_a_prefilled_book_shoot_link(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $out = Client::create(['name' => 'SVA Silks and Readymades']);
        ContentAccount::create(['client_id' => $out->id, 'name' => 'Instagram', 'target_reel' => 12]);
        ContentItem::factory()->create(['source' => 'reel', 'venture' => 'SVA Silks and Readymades', 'status' => 'Published']);
        // Has a target but no Notion venture connected: can't be counted, not "0 left".
        $unlinked = Client::create(['name' => 'Dr.Revanth, Ortho']);
        ContentAccount::create(['client_id' => $unlinked->id, 'name' => 'Instagram', 'target_reel' => 8]);
        Client::create(['name' => 'Janet Bakes']);
        ContentAccount::create(['client_id' => Client::where('name', 'Janet Bakes')->value('id'), 'name' => 'Instagram']);

        $this->actingAs($admin)->get(route('forecast.index'))
            ->assertOk()
            ->assertSeeInOrder(['Book now', 'SVA Silks and Readymades', '0 reels left', 'Book shoot', 'Book soon', "can't be counted", 'Dr.Revanth, Ortho', 'Connect in Notion setup', 'Janet Bakes'])
            ->assertSee(route('shoots.create', ['client_id' => $out->id]), false);

        $this->get(route('shoots.create', ['client_id' => $out->id]))
            ->assertOk()
            ->assertSee('value="'.$out->id.'" selected', false);
    }
    public function test_videos_come_from_the_ventures_connected_to_the_clients_accounts(): void
    {
        $client = Client::create(['name' => 'Dr.Revanth, Ortho']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Instagram', 'target_reel' => 8]);
        \App\Models\ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'Dr.Revanth']);
        ContentItem::factory()->count(3)->create(['source' => 'reel', 'venture' => 'Dr.Revanth', 'status' => 'To Be Edited']);
        ContentItem::factory()->create(['source' => 'reel', 'venture' => 'Someone else', 'status' => 'To Be Edited']);

        $row = \App\Support\ContentForecast::forClient($client);

        $this->assertTrue($row['linked']);
        $this->assertSame(3, $row['remaining']);
    }
}
