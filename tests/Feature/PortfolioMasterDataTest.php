<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The master lists, and the portfolio's links into them.
 *
 * The rules that matter: a piece never loses its label when a list changes,
 * and a term of the wrong type can never end up in a field.
 */
class PortfolioMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function term(string $type, string $name, bool $active = true): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'type' => $type,
            'name' => $name,
            'slug' => TaxonomyTerm::uniqueSlug($type, $name),
            'sort_order' => 0,
            'is_active' => $active,
        ]);
    }

    public function test_an_admin_manages_a_list_from_one_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('taxonomy.store'), [
            'type' => TaxonomyTerm::TYPE_PLATFORM,
            'name' => 'Instagram Reels',
            'is_active' => '1',
        ])->assertRedirect(route('taxonomy.index', ['type' => TaxonomyTerm::TYPE_PLATFORM]));

        $term = TaxonomyTerm::where('type', TaxonomyTerm::TYPE_PLATFORM)->firstOrFail();

        $this->assertSame('instagram-reels', $term->slug);
        $this->assertTrue($term->is_active);

        $this->actingAs($admin)->get(route('taxonomy.index', ['type' => TaxonomyTerm::TYPE_PLATFORM]))
            ->assertOk()
            ->assertSee('Instagram Reels');
    }

    public function test_the_same_name_cannot_be_added_to_one_list_twice(): void
    {
        $admin = $this->admin();
        $this->term(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels');

        // Different casing is the exact drift the master list exists to stop.
        $this->actingAs($admin)->post(route('taxonomy.store'), [
            'type' => TaxonomyTerm::TYPE_PLATFORM,
            'name' => 'instagram reels',
        ])->assertSessionHasErrors('name');

        // The same word in a different list is fine.
        $this->actingAs($admin)->post(route('taxonomy.store'), [
            'type' => TaxonomyTerm::TYPE_TAG,
            'name' => 'Instagram Reels',
        ])->assertSessionHasNoErrors();

        // Scoped to the two lists under test: task types are seeded by a
        // migration, so a bare count() is counting somebody else's rows.
        $this->assertSame(2, TaxonomyTerm::whereIn('type', [
            TaxonomyTerm::TYPE_PLATFORM,
            TaxonomyTerm::TYPE_TAG,
        ])->count());
    }

    public function test_retiring_a_term_hides_it_from_pickers_but_not_from_the_work_using_it(): void
    {
        $retired = $this->term(TaxonomyTerm::TYPE_PLATFORM, 'Vine', active: false);
        $live = $this->term(TaxonomyTerm::TYPE_PLATFORM, 'YouTube');

        $item = PortfolioItem::create([
            'title' => 'An old film',
            'is_visible' => true,
            'platform_id' => $retired->id,
        ]);

        // The public page still reads correctly.
        $this->assertSame('Vine', $item->platformLabel());

        // A new piece is offered only the live term...
        $this->assertEquals([$live->id], TaxonomyTerm::options(TaxonomyTerm::TYPE_PLATFORM)->pluck('id')->all());

        // ...while editing the old one keeps its own, so saving cannot drop it.
        $this->assertEqualsCanonicalizing(
            [$retired->id, $live->id],
            TaxonomyTerm::options(TaxonomyTerm::TYPE_PLATFORM, $retired->id)->pluck('id')->all()
        );
    }

    public function test_deleting_a_term_keeps_the_work_and_clears_the_reference(): void
    {
        $term = $this->term(TaxonomyTerm::TYPE_PLATFORM, 'Vine');

        $item = PortfolioItem::create([
            'title' => 'An old film',
            'is_visible' => true,
            'platform_id' => $term->id,
        ]);

        $this->actingAs($this->admin())->delete(route('taxonomy.destroy', $term))->assertRedirect();

        $this->assertSame(1, PortfolioItem::count());
        $this->assertNull($item->fresh()->platform_id);
    }

    public function test_a_term_from_the_wrong_list_is_refused(): void
    {
        $tag = $this->term(TaxonomyTerm::TYPE_TAG, 'Bridal');

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Mismatched',
            'is_visible' => '1',
            'platform_id' => $tag->id,
        ])->assertSessionHasErrors('platform_id');

        $this->assertSame(0, PortfolioItem::count());
    }

    public function test_only_real_tags_can_be_attached_as_tags(): void
    {
        $tag = $this->term(TaxonomyTerm::TYPE_TAG, 'Bridal');
        $platform = $this->term(TaxonomyTerm::TYPE_PLATFORM, 'YouTube');

        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Tagged',
            'is_visible' => '1',
            // The platform is posted into tags[] as a hand-edited form would.
            'tags' => [$tag->id, $platform->id],
        ])->assertRedirect(route('portfolio.index'));

        $this->assertEquals(['Bridal'], PortfolioItem::firstOrFail()->tags->pluck('name')->all());
    }

    public function test_a_linked_client_wins_over_a_typed_name(): void
    {
        $client = Client::create(['name' => 'SVA Jewels']);

        $linked = PortfolioItem::create([
            'title' => 'Linked', 'is_visible' => true,
            'client_id' => $client->id, 'client_name' => 'Stale typed name',
        ]);

        $typed = PortfolioItem::create([
            'title' => 'Typed', 'is_visible' => true, 'client_name' => 'Private',
        ]);

        $neither = PortfolioItem::create(['title' => 'Neither', 'is_visible' => true]);

        $this->assertSame('SVA Jewels', $linked->clientLabel());
        $this->assertSame('Private', $typed->clientLabel());
        $this->assertNull($neither->clientLabel());
    }

    public function test_deleting_a_client_keeps_its_work(): void
    {
        $client = Client::create(['name' => 'SVA Jewels']);
        $item = PortfolioItem::create(['title' => 'Their film', 'is_visible' => true, 'client_id' => $client->id]);

        $client->delete();

        $this->assertSame(1, PortfolioItem::count());
        $this->assertNull($item->fresh()->client_id);
    }

    public function test_the_case_study_shows_the_terms_and_tags(): void
    {
        $client = Client::create(['name' => 'SVA Jewels']);

        $item = PortfolioItem::create([
            'title' => 'The Bridal Set',
            'is_visible' => true,
            'summary' => 'One Reel.',
            'client_id' => $client->id,
            'platform_id' => $this->term(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels')->id,
            'format_id' => $this->term(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical')->id,
            'objective_id' => $this->term(TaxonomyTerm::TYPE_OBJECTIVE, 'Awareness + sales')->id,
        ]);

        $item->tags()->sync([$this->term(TaxonomyTerm::TYPE_TAG, 'Bridal')->id]);

        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            ->assertSee('SVA Jewels')
            ->assertSee('Instagram Reels')
            ->assertSee('9:16 vertical')
            ->assertSee('Awareness + sales')
            ->assertSee('Bridal');
    }

    public function test_the_admin_list_can_be_filtered(): void
    {
        $client = Client::create(['name' => 'SVA Jewels']);

        PortfolioItem::create(['title' => 'Bridal film', 'is_visible' => true, 'client_id' => $client->id]);
        PortfolioItem::create(['title' => 'Draft piece', 'is_visible' => false]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('portfolio.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('Draft piece')
            ->assertDontSee('Bridal film');

        // Search reaches the linked client's name, not just the typed one.
        $this->actingAs($admin)->get(route('portfolio.index', ['q' => 'SVA']))
            ->assertOk()
            ->assertSee('Bridal film')
            ->assertDontSee('Draft piece');
    }

    public function test_employees_cannot_reach_the_master_data(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('taxonomy.index'))->assertForbidden();
    }

    public function test_guests_cannot_reach_the_master_data(): void
    {
        $this->get(route('taxonomy.index'))->assertRedirect(route('login'));
    }
}
