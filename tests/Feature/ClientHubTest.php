<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client page opens on everything about the client: reels left, this
 * month by account, videos in the works -- from the Notion ventures
 * connected to their accounts.
 */
class ClientHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overview_shows_the_clients_content_from_their_connected_ventures(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = Client::create(['name' => 'Dr.Revanth, Ortho']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Revanth Instagram', 'target_reel' => 8]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'Dr.Revanth']);
        ContentItem::factory()->create(['source' => 'reel', 'venture' => 'Dr.Revanth', 'status' => 'To Be Edited', 'title' => 'Knee pain myths']);
        ContentItem::factory()->create(['source' => 'reel', 'venture' => 'Dr.Revanth', 'status' => 'Published', 'published_date' => today(), 'title' => 'Posted one']);

        $this->actingAs($admin)->get(route('clients.show', $client))
            ->assertOk()
            ->assertSeeInOrder(['Reels left to post', 'Reels out this month', '1', '/ 8', 'In the works', 'Unpaid'])
            ->assertSee('Knee pain myths')
            ->assertSeeInOrder(['Reels this month, by account', 'Revanth Instagram', '1 / 8'])
            ->assertSee('Money &amp; hours', false)
            ->assertSee(route('shoots.create', ['client_id' => $client->id]), false);
    }
}
