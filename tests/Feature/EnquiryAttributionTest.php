<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Which page earned the lead.
 *
 * The site carries no analytics and cannot take a third-party script, so this
 * one field is the whole answer to "does the case-study screen bring anyone
 * in". The rule that matters most is the last test here: attribution is our
 * bookkeeping, and it must never be the reason a real enquiry fails to send.
 */
class EnquiryAttributionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validEnquiry(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Meera Raj',
            'email' => 'meera@example.test',
            'message' => 'We need a monthly slate of reels for a new product line.',
        ], $overrides);
    }

    public function test_an_enquiry_records_the_page_that_sent_it(): void
    {
        Notification::fake();

        $this->post(route('enquiry.store'), $this->validEnquiry(['source' => 'case-study']));

        $this->assertSame('case-study', Enquiry::firstOrFail()->source);
        $this->assertSame('Case study', Enquiry::firstOrFail()->sourceLabel());
    }

    public function test_the_case_study_and_portfolio_send_people_back_tagged(): void
    {
        $item = PortfolioItem::create([
            'title' => 'The Bridal Set That Sold Itself',
            'summary' => 'One 32-second Reel.',
            'is_visible' => true,
            'views' => 3_200_000,
        ]);

        // The detail screen's closing call to action.
        $this->get(route('portfolio.detail', $item))
            ->assertOk()
            ->assertSee(route('home', ['from' => 'case-study']).'#contact', false);

        // And the grid's, which must not claim to be a case study.
        $this->get(route('portfolio'))
            ->assertOk()
            ->assertSee(route('home', ['from' => 'portfolio']).'#contact', false);
    }

    public function test_the_landing_form_carries_the_source_it_was_sent(): void
    {
        $this->get(route('home', ['from' => 'case-study']))
            ->assertOk()
            ->assertSee('name="source" value="case-study"', false);

        // Arriving with nothing means the landing page did the work itself.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('name="source" value="landing"', false);
    }

    public function test_a_made_up_source_is_dropped_rather_than_believed(): void
    {
        $this->get(route('home', ['from' => 'https://evil.test']))
            ->assertOk()
            ->assertSee('name="source" value="landing"', false);
    }

    public function test_what_prompted_them_is_kept_when_they_offer_it(): void
    {
        Notification::fake();

        $this->post(route('enquiry.store'), $this->validEnquiry([
            'source' => 'case-study',
            'prompted_by' => 'The SVA Jewels bridal film a friend sent me.',
        ]));

        $this->assertSame(
            'The SVA Jewels bridal film a friend sent me.',
            Enquiry::firstOrFail()->prompted_by
        );
    }

    /**
     * The one that protects the funnel: a hand-edited or mangled source is
     * bookkeeping we lose, never a lead we lose.
     */
    public function test_a_junk_source_never_blocks_a_real_enquiry(): void
    {
        Notification::fake();

        $this->post(route('enquiry.store'), $this->validEnquiry(['source' => 'nonsense']))
            ->assertRedirect(route('home').'#contact')
            ->assertSessionHasNoErrors();

        $enquiry = Enquiry::firstOrFail();

        $this->assertNull($enquiry->source);
        $this->assertSame('Not recorded', $enquiry->sourceLabel());
        $this->assertFalse($enquiry->hasSource());
    }

    public function test_the_inbox_shows_the_studio_where_leads_come_from(): void
    {
        Notification::fake();

        $this->post(route('enquiry.store'), $this->validEnquiry(['source' => 'case-study']));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('enquiries.index'))
            ->assertOk()
            ->assertSee('Came from')
            ->assertSee('Case study');

        $this->actingAs($admin)->get(route('enquiries.show', Enquiry::firstOrFail()))
            ->assertOk()
            ->assertSee('Case study');
    }
}
