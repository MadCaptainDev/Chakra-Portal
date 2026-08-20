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
 * The screen that decides whose content is whose.
 */
class ContentAccountTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function client(string $name = 'SVA Silks and Readymades'): Client
    {
        return Client::firstOrCreate(['name' => $name]);
    }

    public function test_a_guest_and_an_employee_reach_none_of_it(): void
    {
        $this->get(route('content-accounts.edit'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('content-accounts.edit'))
            ->assertForbidden();
    }

    public function test_an_account_can_be_added(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin())
            ->post(route('content-accounts.store'), [
                'client_id' => $client->id,
                'name' => 'SVA Womenswear',
                'monthly_target' => 12,
            ])
            ->assertRedirect(route('content-accounts.edit'));

        $account = ContentAccount::sole();
        $this->assertSame('SVA Womenswear', $account->name);
        $this->assertSame(12, $account->monthly_target);
        $this->assertSame($client->id, $account->client_id);
    }

    public function test_saving_assigns_a_venture_and_updates_the_target(): void
    {
        $account = ContentAccount::create(['client_id' => $this->client()->id, 'name' => 'SVA Silks']);

        $this->actingAs($this->admin())
            ->put(route('content-accounts.update'), [
                'names' => [$account->id => 'SVA Silks Main'],
                'targets' => [$account->id => 20],
                'map' => [['venture' => 'SVA Silks', 'account_id' => $account->id]],
            ])
            ->assertRedirect(route('content-accounts.edit'));

        $account->refresh();
        $this->assertSame('SVA Silks Main', $account->name);
        $this->assertSame(20, $account->monthly_target);
        $this->assertSame(['SVA Silks'], $account->ventures()->pluck('venture')->all());
    }

    /**
     * The trap this form is shaped around: PHP rewrites dots and spaces in
     * request KEYS to underscores. Carrying the venture as a value keeps
     * "Annamalai.mov" and "Surya’s Restaurant" intact -- as keys they would
     * arrive as "Annamalai_mov" and match no stored row.
     */
    public function test_ventures_containing_dots_spaces_and_apostrophes_survive_the_round_trip(): void
    {
        $account = ContentAccount::create(['client_id' => $this->client()->id, 'name' => 'Misc']);

        $awkward = ['Annamalai.mov', 'Surya’s Restaurant', 'Brown soul', 'SVA Tier 2'];

        $this->actingAs($this->admin())
            ->put(route('content-accounts.update'), [
                'map' => collect($awkward)
                    ->map(fn (string $v) => ['venture' => $v, 'account_id' => $account->id])
                    ->all(),
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing($awkward, $account->ventures()->pluck('venture')->all());
    }

    public function test_unassigning_returns_a_venture_to_unmapped(): void
    {
        $account = ContentAccount::create(['client_id' => $this->client()->id, 'name' => 'SVA Silks']);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);

        ContentItem::factory()->create(['venture' => 'SVA Silks', 'status' => 'Published']);

        $this->actingAs($this->admin())
            ->put(route('content-accounts.update'), [
                'map' => [['venture' => 'SVA Silks', 'account_id' => null]],
            ]);

        $this->assertSame(0, $account->ventures()->count());
        $this->assertSame('SVA Silks', ContentAccount::unmappedVentures()->first()->venture);
    }

    public function test_moving_a_venture_between_accounts_never_counts_it_twice(): void
    {
        $clientId = $this->client()->id;
        $a = ContentAccount::create(['client_id' => $clientId, 'name' => 'Account A']);
        $b = ContentAccount::create(['client_id' => $clientId, 'name' => 'Account B']);
        ContentAccountVenture::create(['content_account_id' => $a->id, 'venture' => 'SVA Silks']);

        $this->actingAs($this->admin())
            ->put(route('content-accounts.update'), [
                'map' => [['venture' => 'SVA Silks', 'account_id' => $b->id]],
            ]);

        // One row table-wide, now on B -- the unique constraint on venture
        // is what guarantees a video can only ever land in one account.
        $this->assertSame(1, ContentAccountVenture::where('venture', 'SVA Silks')->count());
        $this->assertSame(0, $a->ventures()->count());
        $this->assertSame(1, $b->ventures()->count());
    }

    public function test_deleting_an_account_releases_its_ventures_without_deleting_content(): void
    {
        $account = ContentAccount::create(['client_id' => $this->client()->id, 'name' => 'SVA Silks']);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'SVA Silks']);
        ContentItem::factory()->create(['venture' => 'SVA Silks', 'status' => 'Published']);

        $this->actingAs($this->admin())
            ->delete(route('content-accounts.destroy', $account))
            ->assertRedirect(route('content-accounts.edit'));

        $this->assertSame(0, ContentAccount::count());
        $this->assertSame(0, ContentAccountVenture::count());
        // The content itself is untouched; it is simply unattributed again.
        $this->assertSame(1, ContentItem::count());
        $this->assertSame('SVA Silks', ContentAccount::unmappedVentures()->first()->venture);
    }

    public function test_an_unknown_account_id_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put(route('content-accounts.update'), [
                'map' => [['venture' => 'SVA Silks', 'account_id' => 999999]],
            ])
            ->assertSessionHasErrors('map.0.account_id');

        $this->assertSame(0, ContentAccountVenture::count());
    }

    public function test_the_screen_lists_unmapped_ventures_with_their_item_counts(): void
    {
        ContentItem::factory()->count(3)->create(['venture' => 'PR', 'status' => 'Published']);

        $this->actingAs($this->admin())
            ->get(route('content-accounts.edit'))
            ->assertOk()
            ->assertSee('PR')
            ->assertSee('Unmapped ventures');
    }
}
