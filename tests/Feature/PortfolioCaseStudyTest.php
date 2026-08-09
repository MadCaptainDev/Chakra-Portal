<?php

namespace Tests\Feature;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public case-study screen: one portfolio piece, its numbers, and what it
 * did for the client.
 *
 * The rule that matters is that a piece the public cannot see on the grid has
 * no page either -- a draft must not be reachable by guessing its id.
 */
class PortfolioCaseStudyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function measuredPiece(array $attributes = []): PortfolioItem
    {
        return PortfolioItem::create($attributes + [
            'title' => 'The Bridal Set That Sold Itself',
            'client_name' => 'SVA Jewels',
            'summary' => 'One 32-second Reel produced for SVA Jewels.',
            'is_visible' => true,
            'views' => 3_200_000,
            'reach' => 1_800_000,
            'likes' => 42_000,
            'enquiries' => 620,
            'engagement_rate' => 8.4,
            'completion_rate' => 61,
            'sales_amount' => 1_220_000,
            'sales_before_amount' => 850_000,
            'benchmark_views' => 180_000,
        ]);
    }

    public function test_the_case_study_shows_the_numbers_shortened_for_reading(): void
    {
        $item = $this->measuredPiece();

        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            ->assertSee('The Bridal Set That Sold Itself')
            ->assertSee('SVA Jewels')
            ->assertSee('3.2M')          // views
            ->assertSee('42K')           // likes
            ->assertSee('8.4%')          // engagement rate
            ->assertSee("\u{20B9}12.2L") // attributed sales, in lakh
            ->assertSee('17.8')          // views against the average piece
            ->assertSee('+43.5%');       // growth over the month before
    }

    public function test_a_piece_with_nothing_on_record_still_has_a_page_without_empty_sections(): void
    {
        $item = PortfolioItem::create([
            'title' => 'Annual report film',
            'summary' => 'A quiet corporate piece.',
            'is_visible' => true,
        ]);

        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            ->assertSee('Annual report film')
            ->assertDontSee('How this film performed')
            ->assertDontSee('From views to sales')
            ->assertDontSee('How this film compared');
    }

    public function test_a_draft_has_no_public_page(): void
    {
        $item = $this->measuredPiece(['is_visible' => false]);

        $this->get(route('portfolio.detail', $item))->assertNotFound();
    }

    public function test_a_piece_filed_under_a_hidden_category_has_no_public_page(): void
    {
        $hidden = PortfolioCategory::create([
            'name' => 'Internal', 'slug' => 'internal', 'sort_order' => 0, 'is_visible' => false,
        ]);

        $item = $this->measuredPiece(['portfolio_category_id' => $hidden->id]);

        $this->get(route('portfolio.detail', $item))->assertNotFound();
    }

    public function test_the_grid_links_to_a_case_study_only_when_there_is_one(): void
    {
        $measured = $this->measuredPiece();
        $bare = PortfolioItem::create(['title' => 'Just a film', 'is_visible' => true]);

        $this->get(route('portfolio'))
            ->assertOk()
            ->assertSee(route('portfolio.detail', $measured), false)
            ->assertDontSee(route('portfolio.detail', $bare), false);
    }

    public function test_an_empty_portfolio_is_not_advertised_anywhere(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('portfolio'), false);

        $this->get(route('portfolio'))->assertRedirect(route('home'));
    }

    public function test_the_work_tab_returns_once_something_is_published(): void
    {
        PortfolioItem::create(['title' => 'Brand film', 'is_visible' => true]);

        $this->get('/')->assertOk()->assertSee(route('portfolio'), false);
        $this->get(route('portfolio'))->assertOk();
    }

    public function test_sales_stay_off_the_page_when_the_piece_is_set_to_hide_them(): void
    {
        $item = $this->measuredPiece([
            'show_business_impact' => false,
            'orders' => 78,
            'roi' => 6.4,
            'benchmark_sales_amount' => 210_000,
        ]);

        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            // Reach and engagement are the client's own public numbers.
            ->assertSee('3.2M')
            ->assertSee('8.4%')
            // The money side is not.
            ->assertDontSee('From views to sales')
            ->assertDontSee('The month either side')
            ->assertDontSee("\u{20B9}12.2L")
            ->assertDontSee('+43.5%')
            ->assertDontSee('Return on spend');
    }

    public function test_the_admin_form_offers_the_case_study_fields(): void
    {
        $item = $this->measuredPiece();

        $this->actingAs($this->admin())->get(route('portfolio.edit', $item))
            ->assertOk()
            ->assertSee('Case study (optional)')
            ->assertSee('name="views"', false)
            ->assertSee('name="creative_hook"', false)
            ->assertSee('name="before_after[0][label]"', false)
            ->assertSee('name="show_business_impact"', false);
    }

    public function test_an_admin_records_the_case_study_alongside_the_piece(): void
    {
        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Bridal campaign',
            'is_visible' => '1',
            'show_business_impact' => '1',
            'summary' => 'What we made and what it did.',
            'platform' => 'Instagram Reels',
            'format' => '9:16 vertical',
            'duration_seconds' => '32',
            'views' => '3200000',
            'enquiries' => '620',
            'sales_amount' => '1220000',
            'creative_hook' => 'The clasp closing in macro.',
            'before_after' => [
                ['label' => 'Monthly reach', 'before' => '240K', 'after' => '2.1M'],
                // Half-filled, so it should never reach the public screen.
                ['label' => 'Orders', 'before' => '41', 'after' => ''],
            ],
        ])->assertRedirect(route('portfolio.index'));

        $item = PortfolioItem::firstOrFail();

        $this->assertSame(3_200_000, $item->views);
        $this->assertSame(620, $item->enquiries);
        $this->assertSame('The clasp closing in macro.', $item->creative_hook);
        $this->assertTrue($item->show_business_impact);
        $this->assertTrue($item->hasCaseStudy());
        $this->assertSame(
            [['label' => 'Monthly reach', 'before' => '240K', 'after' => '2.1M']],
            $item->beforeAfterRows()
        );
    }

    public function test_an_untouched_form_does_not_publish_a_case_study_of_zeroes(): void
    {
        $this->actingAs($this->admin())->post(route('portfolio.store'), [
            'title' => 'Just a film',
            'is_visible' => '1',
            'views' => '',
            'enquiries' => '',
            'sales_amount' => '',
            'before_after' => [['label' => '', 'before' => '', 'after' => '']],
        ])->assertRedirect(route('portfolio.index'));

        $item = PortfolioItem::firstOrFail();

        $this->assertNull($item->views);
        $this->assertNull($item->sales_amount);
        $this->assertNull($item->before_after);
        $this->assertFalse($item->hasCaseStudy());
    }
}
