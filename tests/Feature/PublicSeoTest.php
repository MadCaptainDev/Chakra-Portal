<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    /** Every ld+json object on a page, decoded. */
    private function schema(TestResponse $response): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $matches);

        return array_map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }

    private function piece(array $attributes = []): PortfolioItem
    {
        $category = PortfolioCategory::create(['name' => 'Healthcare Marketing', 'slug' => 'healthcare-marketing', 'is_visible' => true]);
        $client = Client::create(['name' => 'Digital Harvest (Janet Hospitals)']);
        $client->forceFill(['display_name' => 'Janet Hospitals Trichy'])->save();

        return PortfolioItem::create($attributes + [
            'title' => 'Newborn Care Reel',
            'is_visible' => true,
            'portfolio_category_id' => $category->id,
            'client_id' => $client->id,
            'video_url' => 'https://www.instagram.com/reel/abc/',
            'published_on' => '2026-09-05',
            'views' => 1000,
            'description' => "First, we place the baby in a warmer 👶❤️\n\nFollow @janethospitaltrichy\n\n[baby warmer, newborn care]\n\n#BabyWarmer #NewbornCare",
        ]);
    }

    public function test_the_homepage_names_the_places_and_describes_the_agency(): void
    {
        $page = $this->get('/')->assertOk()
            ->assertSee('<title>Chakra Productions — Digital Marketing &amp; Video Agency in Trichy, Manapparai</title>', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('Trichy and Manapparai, Tamil Nadu');

        $agency = collect($this->schema($page))->firstWhere('@type', 'MarketingAgency');
        $this->assertSame('Chakra Productions', $agency['name']);
        $this->assertContains('Tiruchirappalli', array_column($agency['areaServed'], 'name'));
        $this->assertContains('https://www.instagram.com/thechakra_productions/', $agency['sameAs']);
        $this->assertNotEmpty($agency['hasOfferCatalog']['itemListElement']);
        // No contact details are configured, so none are claimed.
        $this->assertArrayNotHasKey('telephone', $agency);
        $this->assertArrayNotHasKey('address', $agency);
    }

    public function test_contact_details_join_the_structured_data_once_configured(): void
    {
        config(['studio.phone' => '+91 90000 00000', 'studio.address.locality' => 'Manapparai']);

        $agency = Seo::organization();

        $this->assertSame('+91 90000 00000', $agency['telephone']);
        $this->assertSame('Manapparai', $agency['address']['addressLocality']);
        $this->assertSame('IN', $agency['address']['addressCountry']);
    }

    public function test_a_case_study_gets_a_clean_description_its_cover_and_video_schema(): void
    {
        $item = $this->piece(['views' => 134740]);

        $page = $this->get(route('portfolio.detail', $item))->assertOk()
            ->assertSee('<title>Newborn Care Reel — Janet Hospitals Trichy | Chakra Productions</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('portfolio.detail', $item).'">', false)
            ->assertSee('<meta property="og:type" content="article">', false);

        $schema = collect($this->schema($page));
        $video = $schema->firstWhere('@type', 'VideoObject') ?? $schema->firstWhere('@type', 'CreativeWork');
        $this->assertSame('Newborn Care Reel', $video['name']);
        $this->assertSame(['@type' => 'Organization', 'name' => 'Janet Hospitals Trichy'], $video['about']);
        $this->assertSame(134740, $video['interactionStatistic']['userInteractionCount']);

        // Hashtags, handles, keyword brackets and emoji are gone.
        $this->assertStringStartsWith('First, we place the baby in a warmer', $video['description']);
        $this->assertStringNotContainsString('#', $video['description']);
        $this->assertStringNotContainsString('@janet', $video['description']);
        $this->assertStringNotContainsString('[', $video['description']);

        $crumbs = $schema->firstWhere('@type', 'BreadcrumbList');
        $this->assertSame(['Home', 'Portfolio', 'Healthcare Marketing', 'Newborn Care Reel'], array_column($crumbs['itemListElement'], 'name'));
    }

    public function test_a_category_tab_is_its_own_landing_page(): void
    {
        $this->piece();

        $page = $this->get(route('portfolio', ['category' => 'healthcare-marketing']))->assertOk()
            ->assertSee('<title>Healthcare Marketing — Portfolio | Chakra Productions, Trichy and Manapparai</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('portfolio', ['category' => 'healthcare-marketing']).'">', false);

        $collection = collect($this->schema($page))->firstWhere('@type', 'CollectionPage');
        $this->assertSame(1, $collection['mainEntity']['numberOfItems']);
    }

    public function test_the_sitemap_lists_published_pages_only(): void
    {
        $item = $this->piece();
        $hidden = PortfolioItem::create(['title' => 'Draft', 'is_visible' => false, 'views' => 50]);
        $noCaseStudy = PortfolioItem::create(['title' => 'Just a film', 'is_visible' => true]);

        $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.url('/').'</loc>', false)
            ->assertSee('<loc>'.route('portfolio.detail', $item).'</loc>', false)
            ->assertSee(e(route('portfolio', ['category' => 'healthcare-marketing'])), false)
            ->assertDontSee(route('portfolio.detail', $hidden), false)
            ->assertDontSee(route('portfolio.detail', $noCaseStudy), false);
    }

    public function test_descriptions_are_cut_at_a_word(): void
    {
        $text = Seo::description(str_repeat('reels for local brands ', 20));

        $this->assertLessThanOrEqual(155, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
