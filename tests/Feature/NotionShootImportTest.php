<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Notion\NotionShootImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importing Notion's shoots into the portal's own Shoots module.
 *
 * One-way, because the Notion token is read-only. Notion owns what a shoot
 * IS; the portal owns everything it adds around one (crew, kit, scripts),
 * and a re-import must never touch that.
 */
class NotionShootImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function notionShoot(array $attributes = []): NotionShoot
    {
        return NotionShoot::factory()->create($attributes + [
            'title' => 'SVA Product Shoot',
            'status' => 'Planned',
            'client' => 'SVA',
            'shoot_date' => '2026-09-10',
            'location' => 'Studio A',
        ]);
    }

    public function test_a_dated_shoot_becomes_a_portal_shoot(): void
    {
        $client = Client::create(['name' => 'SVA Silks and Readymades']);
        $notionShoot = $this->notionShoot(['client_id' => $client->id]);

        (new NotionShootImporter)->importAll();

        $shoot = Shoot::sole();
        $this->assertSame('SVA Product Shoot', $shoot->title);
        $this->assertSame($client->id, $shoot->client_id);
        $this->assertSame('2026-09-10', $shoot->starts_at->toDateString());
        $this->assertSame('Studio A', $shoot->location);
        $this->assertSame($notionShoot->id, $shoot->notion_shoot_id);
        $this->assertTrue($shoot->isFromNotion());
    }

    public function test_a_shoot_with_no_date_is_skipped_rather_than_given_a_made_up_one(): void
    {
        $this->notionShoot(['shoot_date' => null]);

        $result = (new NotionShootImporter)->importAll();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, Shoot::count());
    }

    public function test_re_importing_refreshes_rather_than_duplicating(): void
    {
        $notionShoot = $this->notionShoot();
        $importer = new NotionShootImporter;

        $importer->importAll();
        $notionShoot->forceFill(['title' => 'Renamed in Notion', 'status' => 'Completed'])->save();
        $result = $importer->importAll();

        $this->assertSame(1, Shoot::count());
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['imported']);

        $shoot = Shoot::sole();
        $this->assertSame('Renamed in Notion', $shoot->title);
        $this->assertSame(Shoot::STATUS_COMPLETED, $shoot->status);
    }

    public function test_a_re_import_never_touches_portal_side_notes(): void
    {
        $this->notionShoot();
        $importer = new NotionShootImporter;
        $importer->importAll();

        Shoot::sole()->forceFill(['notes' => 'Bring the second tripod.'])->save();
        $importer->importAll();

        // Notion has no idea this note exists and must not be able to erase it.
        $this->assertSame('Bring the second tripod.', Shoot::sole()->notes);
    }

    /**
     * Notion carries production detail the portal's four statuses do not,
     * so Editing and Review both mean "the shoot happened".
     */
    public function test_notion_statuses_map_onto_portal_statuses(): void
    {
        $expected = [
            'Planned' => Shoot::STATUS_PLANNED,
            'Shooting' => Shoot::STATUS_CONFIRMED,
            'Editing' => Shoot::STATUS_COMPLETED,
            'Review' => Shoot::STATUS_COMPLETED,
            'Completed' => Shoot::STATUS_COMPLETED,
            'Cancelled' => Shoot::STATUS_CANCELLED,
        ];

        foreach ($expected as $notionStatus => $portalStatus) {
            $shoot = $this->notionShoot(['status' => $notionStatus]);
            $this->assertSame($portalStatus, $shoot->portalStatus(), $notionStatus);
        }
    }

    public function test_an_unrecognised_notion_status_falls_back_to_planned(): void
    {
        $this->assertSame(
            Shoot::STATUS_PLANNED,
            $this->notionShoot(['status' => 'Something New In Notion'])->portalStatus()
        );
    }

    // -- Client auto-mapping -------------------------------------------------

    public function test_auto_mapping_matches_an_exact_client_name_only(): void
    {
        Client::create(['name' => 'Thor Gym']);
        $exact = $this->notionShoot(['client' => 'Thor Gym', 'client_id' => null]);
        // "SVA" is not any client's exact name; fuzzy matching would send it
        // to SVA Silks, which is precisely the guess this must not make.
        $fuzzy = $this->notionShoot(['client' => 'SVA', 'client_id' => null]);
        Client::create(['name' => 'SVA Silks and Readymades']);

        $mapped = (new NotionShootImporter)->autoMapClients();

        $this->assertSame(1, $mapped);
        $this->assertNotNull($exact->fresh()->client_id);
        $this->assertNull($fuzzy->fresh()->client_id);
    }

    public function test_auto_mapping_also_uses_an_already_mapped_venture_name(): void
    {
        $client = Client::create(['name' => 'Digital Harvest (Janet Hospitals)']);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Janet']);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'Janet']);

        $shoot = $this->notionShoot(['client' => 'Janet', 'client_id' => null]);

        (new NotionShootImporter)->autoMapClients();

        $this->assertSame($client->id, $shoot->fresh()->client_id);
    }

    public function test_auto_mapping_never_overwrites_a_client_set_by_hand(): void
    {
        $chosen = Client::create(['name' => 'Chosen By A Person']);
        Client::create(['name' => 'Thor Gym']);
        $shoot = $this->notionShoot(['client' => 'Thor Gym', 'client_id' => $chosen->id]);

        (new NotionShootImporter)->autoMapClients();

        $this->assertSame($chosen->id, $shoot->fresh()->client_id);
    }

    // -- Crew matching ---------------------------------------------------------

    public function test_import_adds_crew_for_names_that_match_a_portal_user(): void
    {
        $aron = User::factory()->create(['name' => 'Aron Sham']);
        $nitis = User::factory()->create(['name' => 'Nitis']);
        $this->notionShoot(['team' => 'Aron, Nitis, Somebody Unrecognised']);

        (new NotionShootImporter)->importAll();

        $crewIds = Shoot::sole()->crew->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$aron->id, $nitis->id], collect($crewIds)->sort()->values()->all());
    }

    public function test_import_never_adds_the_same_crew_member_twice_on_a_re_sync(): void
    {
        User::factory()->create(['name' => 'Nitis']);
        $notionShoot = $this->notionShoot(['team' => 'Nitis']);
        $importer = new NotionShootImporter;

        $importer->importAll();
        $importer->importAll();

        $this->assertSame(1, Shoot::sole()->crew()->count());
    }

    public function test_import_never_removes_crew_a_person_added_by_hand(): void
    {
        $manual = User::factory()->create(['name' => 'Manually Added']);
        $notionShoot = $this->notionShoot(['team' => null]);
        $importer = new NotionShootImporter;
        $importer->importAll();

        Shoot::sole()->crew()->create(['user_id' => $manual->id]);
        $notionShoot->forceFill(['team' => 'Nobody Matching'])->save();
        $importer->importAll();

        $this->assertTrue(Shoot::sole()->crew()->where('user_id', $manual->id)->exists());
    }

    public function test_an_ambiguous_first_name_is_left_unmatched(): void
    {
        User::factory()->create(['name' => 'Aron Sham']);
        User::factory()->create(['name' => 'Aron Kumar']);
        $this->notionShoot(['team' => 'Aron']);

        (new NotionShootImporter)->importAll();

        $this->assertSame(0, Shoot::sole()->crew()->count());
    }

    // -- The screen ----------------------------------------------------------

    public function test_syncing_from_the_shoots_screen_creates_portal_shoots(): void
    {
        $this->notionShoot();

        $this->actingAs($this->admin())
            ->post(route('shoots.sync-notion'))
            ->assertRedirect(route('shoots.index'));

        $this->assertSame(1, Shoot::count());
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('shoots.index'))->assertRedirect(route('login'));
        $this->post(route('shoots.sync-notion'))->assertRedirect(route('login'));
    }

    public function test_a_portal_created_shoot_is_not_from_notion(): void
    {
        $shoot = Shoot::create([
            'title' => 'Planned here, not in Notion',
            'starts_at' => now()->addWeek(),
            'status' => Shoot::STATUS_PLANNED,
        ]);

        $this->assertFalse($shoot->isFromNotion());
        $this->assertNull($shoot->notion_shoot_id);
    }
}
