<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionIgnoredName;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Notion\NotionShootImporter;
use App\Support\NotionConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Notion ↔ Clients screen's instant actions, and the matching behind
 * its suggestions.
 */
class NotionConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function shoot(string $client, ?int $clientId = null): NotionShoot
    {
        static $n = 0;
        $n++;

        return NotionShoot::forceCreate([
            'notion_page_id' => 'page-'.$n,
            'title' => 'Shoot '.$n,
            'client' => $client,
            'client_id' => $clientId,
        ]);
    }

    public function test_employees_cannot_use_any_of_it(): void
    {
        $this->actingAs(User::factory()->employee()->create())
            ->postJson(route('content-accounts.connect-venture'), ['venture' => 'PR'])
            ->assertForbidden();
    }

    public function test_a_venture_connects_and_the_answer_is_the_fresh_state(): void
    {
        $account = ContentAccount::create(['client_id' => Client::create(['name' => 'Riya Makeover'])->id, 'name' => 'Riya']);
        ContentItem::factory()->count(2)->create(['venture' => 'Riya Reels', 'status' => 'Published']);

        $response = $this->actingAs($this->admin())
            ->postJson(route('content-accounts.connect-venture'), ['venture' => 'Riya Reels', 'account_id' => $account->id])
            ->assertOk();

        $this->assertSame($account->id, ContentAccountVenture::where('venture', 'Riya Reels')->value('content_account_id'));
        $this->assertSame(2, $response->json('state.stats.connected_items'));
        $this->assertStringContainsString('Riya Reels', $response->json('message'));

        // Undo is the same call with no account.
        $this->postJson(route('content-accounts.connect-venture'), ['venture' => 'Riya Reels', 'account_id' => null])->assertOk();
        $this->assertSame(0, ContentAccountVenture::count());
    }

    public function test_a_new_client_account_and_connection_in_one_step(): void
    {
        ContentItem::factory()->count(5)->create(['venture' => 'PR', 'status' => 'Published']);

        $this->actingAs($this->admin())
            ->postJson(route('content-accounts.create-and-connect'), [
                'venture' => 'PR', 'client_name' => 'PR Jewellers', 'account_name' => 'PR Jewellers',
            ])
            ->assertOk()
            ->assertJsonPath('state.stats.ventures_waiting', 0);

        $client = Client::where('name', 'PR Jewellers')->sole();
        $this->assertSame('PR', $client->notion_venture);
        $this->assertSame($client->id, ContentAccountVenture::where('venture', 'PR')->sole()->contentAccount->client_id);
    }

    public function test_a_new_account_under_an_existing_client(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);

        $this->actingAs($this->admin())
            ->postJson(route('content-accounts.create-and-connect'), [
                'venture' => 'SVA Kids', 'client_id' => $client->id, 'account_name' => 'SVA Kids',
            ])
            ->assertOk();

        $this->assertSame(1, Client::count());
        $this->assertSame('SVA Kids', ContentAccountVenture::where('venture', 'SVA Kids')->sole()->contentAccount->name);
    }

    public function test_a_name_can_be_ignored_and_restored_without_losing_anything(): void
    {
        ContentItem::factory()->create(['venture' => 'Others', 'status' => 'Published']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('content-accounts.ignore'), ['kind' => 'venture', 'name' => 'Others', 'ignored' => true])
            ->assertOk()
            ->assertJsonPath('state.stats.ventures_waiting', 0);

        $this->assertTrue(collect(NotionConnections::state()['ventures'])->firstWhere('name', 'Others')['ignored']);

        $this->postJson(route('content-accounts.ignore'), ['kind' => 'venture', 'name' => 'Others', 'ignored' => false])
            ->assertOk()
            ->assertJsonPath('state.stats.ventures_waiting', 1);
        $this->assertSame(0, NotionIgnoredName::count());
        $this->assertSame(1, ContentItem::count());
    }

    public function test_an_accounts_name_and_targets_are_edited_in_place(): void
    {
        $account = ContentAccount::create(['client_id' => Client::create(['name' => 'Zira'])->id, 'name' => 'Zira', 'target_reel' => 10]);

        $this->actingAs($this->admin())
            ->patchJson(route('content-accounts.quick-update', $account), ['target_reel' => 15])
            ->assertOk();
        $this->patchJson(route('content-accounts.quick-update', $account), ['target_post' => null, 'name' => 'Zira Bridal'])->assertOk();

        $account->refresh();
        $this->assertSame(15, $account->target_reel);
        $this->assertSame('Zira Bridal', $account->name);

        $this->patchJson(route('content-accounts.quick-update', $account), ['target_reel' => -3])->assertUnprocessable();
    }

    public function test_connecting_a_shoot_name_files_every_shoot_and_the_imported_ones(): void
    {
        $suryas = Client::create(['name' => "Surya's Restaurant"]);
        $other = Client::create(['name' => 'Someone Else']);
        $a = $this->shoot('Suryas', $suryas->id);
        $b = $this->shoot('Suryas');
        $c = $this->shoot('Suryas', $other->id);
        $imported = Shoot::create(['title' => 'Imported', 'notion_shoot_id' => $b->id, 'starts_at' => now(), 'status' => Shoot::STATUS_PLANNED]);

        // A split name is flagged as such before it is fixed.
        $state = NotionConnections::state();
        $this->assertTrue(collect($state['shootNames'])->firstWhere('name', 'Suryas')['split']);

        $this->actingAs($this->admin())
            ->postJson(route('content-accounts.connect-shoot-client'), ['name' => 'Suryas', 'client_id' => $suryas->id])
            ->assertOk();

        foreach ([$a, $b, $c] as $shoot) {
            $this->assertSame($suryas->id, $shoot->refresh()->client_id);
        }
        $this->assertSame($suryas->id, $imported->refresh()->client_id);
        $this->assertFalse(collect(NotionConnections::state()['shootNames'])->firstWhere('name', 'Suryas')['split']);
    }

    public function test_a_shoot_synced_later_follows_the_answer_already_given_for_its_name(): void
    {
        // "SVA" names no client exactly, so only a person can answer it --
        // and once they have, the next sync must not ask again.
        $silks = Client::create(['name' => 'SVA Silks and Readymades']);
        $this->shoot('SVA', $silks->id);
        $this->shoot('SVA', $silks->id);
        $later = $this->shoot('SVA');

        app(NotionShootImporter::class)->autoMapClients();

        $this->assertSame($silks->id, $later->refresh()->client_id);
    }

    public function test_suggestions_match_whole_words_and_never_part_of_one(): void
    {
        $riya = Client::create(['name' => 'Riya Makeover Artistry']);
        $thillai = Client::create(['name' => 'Thillai Pets Clinic']);
        $silks = Client::create(['name' => 'SVA Silks and Readymades']);
        $gold = Client::create(['name' => 'SVA Gold and Diamonds']);
        $riyaAccount = ContentAccount::create(['client_id' => $riya->id, 'name' => 'Riya']);
        ContentAccount::create(['client_id' => $thillai->id, 'name' => 'Thillai Pets Clinic']);

        ContentItem::factory()->create(['venture' => 'thinkwithpriya', 'status' => 'Published']);
        ContentItem::factory()->create(['venture' => 'riya', 'status' => 'Published']);
        ContentItem::factory()->create(['venture' => 'ThillaiPets', 'status' => 'Published']);
        ContentItem::factory()->create(['venture' => 'SVA Golds and Diamonds', 'status' => 'Published']);

        $ventures = collect(NotionConnections::state()['ventures'])->keyBy('name');

        // "priya" contains "riya", but is not the word "riya".
        $this->assertSame([], $ventures['thinkwithpriya']['suggestions']);

        // Same name, any case: strong, straight onto the account.
        $this->assertSame('strong', $ventures['riya']['suggestions'][0]['strength']);
        $this->assertSame($riyaAccount->id, $ventures['riya']['suggestions'][0]['account_id']);

        // Written as one word.
        $this->assertSame($thillai->id, $ventures['ThillaiPets']['suggestions'][0]['client_id']);

        // "Golds" is "Gold": Gold and Diamonds first, Silks (only "SVA") after.
        $gd = $ventures['SVA Golds and Diamonds']['suggestions'];
        $this->assertSame($gold->id, $gd[0]['client_id']);
        $this->assertContains($silks->id, array_column($gd, 'client_id'));
        $this->assertNotSame('strong', $gd[1]['strength'] ?? 'weak');
    }
}
