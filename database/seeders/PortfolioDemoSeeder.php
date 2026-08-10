<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;

/**
 * Sample portfolio work, for looking at the public site and for research
 * sessions where someone has to react to a real screen.
 *
 * The three pieces deliberately span the range the case-study screen has to
 * cover, because each one renders a different set of sections:
 *
 *   1. fully measured   -- reach, engagement, money, benchmarks: every section
 *   2. figures withheld -- the same numbers on file, `show_business_impact`
 *                          off, so nothing about sales reaches the public page
 *   3. reach only       -- no business data at all, so those sections simply
 *                          do not exist rather than rendering empty
 *
 * Idempotent: run it as often as you like, it updates rather than duplicates.
 * To take it back out again:
 *
 *   php artisan db:seed --class=PortfolioDemoSeeder   # add or refresh
 *   php artisan tinker --execute="(new Database\Seeders\PortfolioDemoSeeder)->remove();"
 */
class PortfolioDemoSeeder extends Seeder
{
    private const CATEGORY_SLUG = 'demo-product-reels';

    public function run(): void
    {
        // The pieces point at master-list terms, so the lists have to exist.
        $this->callOnce(TaxonomySeeder::class);

        $category = PortfolioCategory::updateOrCreate(
            ['slug' => self::CATEGORY_SLUG],
            ['name' => 'Product Reels', 'sort_order' => 0, 'is_visible' => true],
        );

        foreach ($this->pieces() as $index => $piece) {
            $tags = $piece['tags'] ?? [];
            unset($piece['tags']);

            // Sample work is linked to a real client record, because that is
            // the path staff will actually use.
            $client = Client::firstOrCreate(['name' => $piece['client_name']]);
            unset($piece['client_name']);

            $item = PortfolioItem::updateOrCreate(
                ['title' => $piece['title']],
                $piece + [
                    'portfolio_category_id' => $category->id,
                    'client_id' => $client->id,
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );

            $item->tags()->sync($this->termIds(TaxonomyTerm::TYPE_TAG, $tags));
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private function termIds(string $type, array $names): array
    {
        return $names === []
            ? []
            : TaxonomyTerm::ofType($type)->whereIn('name', $names)->pluck('id')->all();
    }

    private function termId(string $type, string $name): ?int
    {
        return TaxonomyTerm::ofType($type)->where('name', $name)->value('id');
    }

    /**
     * Drop everything this seeder created, and the category with it once it is
     * empty. Leaves any real work alone -- it only matches its own titles.
     */
    public function remove(): void
    {
        PortfolioItem::whereIn('title', array_column($this->pieces(), 'title'))->delete();

        $category = PortfolioCategory::where('slug', self::CATEGORY_SLUG)->first();

        if ($category && $category->items()->doesntExist()) {
            $category->delete();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pieces(): array
    {
        return [
            // 1. Everything on: the screen renders all seven sections.
            [
                'title' => 'The Bridal Set That Sold Itself',
                'client_name' => 'SVA Jewels',
                'description' => 'A 32-second product Reel for a bridal jewellery campaign.',
                'summary' => 'One 32-second Reel produced for SVA Jewels. This is what we created, how far it travelled, how people engaged with it, and what it did for the business.',
                'platform_id' => $this->termId(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels'),
                'format_id' => $this->termId(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical'),
                'duration_seconds' => 32,
                'objective_id' => $this->termId(TaxonomyTerm::TYPE_OBJECTIVE, 'Awareness + sales'),
                'service_type_id' => $this->termId(TaxonomyTerm::TYPE_SERVICE, 'Product Reel'),
                'tags' => ['Bridal', 'Macro', 'No dialogue'],
                'published_on' => '2026-06-12',
                'show_business_impact' => true,
                'views' => 3_200_000,
                'reach' => 1_800_000,
                'likes' => 42_000,
                'comments' => 2_340,
                'shares' => 1_240,
                'saves' => 6_800,
                'profile_visits' => 3_140,
                'enquiries' => 620,
                'engagement_rate' => 8.4,
                'completion_rate' => 61,
                'watch_hours' => 14_200,
                'avg_watch_seconds' => 19.6,
                'leads' => 412,
                'whatsapp_enquiries' => 286,
                'calls' => 134,
                'store_visits' => 58,
                'orders' => 78,
                'sales_amount' => 1_220_000,
                'sales_before_amount' => 850_000,
                'roi' => 6.4,
                'benchmark_views' => 180_000,
                'benchmark_reach' => 120_000,
                'benchmark_engagements' => 6_000,
                'benchmark_enquiries' => 85,
                'benchmark_sales_amount' => 210_000,
                'creative_hook' => 'The clasp closing in macro, three seconds before a face is ever shown.',
                'creative_concept' => 'One bridal set, shot as a single unbroken getting-ready sequence.',
                'creative_storytelling' => "Product detail cut against the bride's reaction, with no voiceover.",
                'creative_cta' => '"DM BRIDAL" on the final frame, held for four full seconds.',
                'creative_offer' => 'Complimentary bridal styling session with any set booked in June.',
                'creative_audience' => 'Women 24-34 in Surat, Mumbai and Ahmedabad, shopping for a wedding.',
                'before_after' => [
                    ['label' => 'Monthly reach', 'before' => '240K', 'after' => '2.1M'],
                    ['label' => 'Enquiries', 'before' => '96', 'after' => '620'],
                    ['label' => 'Website traffic', 'before' => '3.4K', 'after' => '18.7K'],
                    ['label' => 'Orders', 'before' => '41', 'after' => '78'],
                    ['label' => 'Followers', 'before' => '28.4K', 'after' => '54.9K'],
                ],
            ],

            // 2. Same shape, money withheld: the figures are recorded but the
            //    public page must show none of them.
            [
                'title' => 'Temple Gold, Reimagined',
                'client_name' => 'Meher & Co.',
                'description' => 'A heritage collection film for a jeweller who does not publish revenue.',
                'summary' => 'A three-part film introducing a heritage gold collection. The client asked us to keep their sales figures private, so this page shows reach and engagement only.',
                'platform_id' => $this->termId(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels'),
                'format_id' => $this->termId(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical'),
                'duration_seconds' => 45,
                'objective_id' => $this->termId(TaxonomyTerm::TYPE_OBJECTIVE, 'Collection launch'),
                'service_type_id' => $this->termId(TaxonomyTerm::TYPE_SERVICE, 'Product Reel'),
                'tags' => ['Festive', 'Studio'],
                'published_on' => '2026-05-02',
                'show_business_impact' => false,
                'views' => 860_000,
                'reach' => 540_000,
                'likes' => 19_400,
                'comments' => 880,
                'shares' => 610,
                'saves' => 3_900,
                'profile_visits' => 1_720,
                'enquiries' => 141,
                'engagement_rate' => 5.2,
                'completion_rate' => 48,
                'watch_hours' => 5_600,
                'avg_watch_seconds' => 23.4,
                // On file, and deliberately never printed.
                'orders' => 44,
                'sales_amount' => 340_000,
                'sales_before_amount' => 260_000,
                'roi' => 3.1,
                'benchmark_views' => 96_000,
                'benchmark_reach' => 70_000,
                'benchmark_engagements' => 4_100,
                'benchmark_enquiries' => 52,
                'creative_hook' => 'A single earring lowered into water, shot at 240fps.',
                'creative_concept' => 'Three films, one per piece, cut to the same score.',
                'creative_audience' => 'Existing customers on the mailing list, plus lookalikes in Gujarat.',
            ],

            // 3. Nothing but the film and a short note: proves the screen holds
            //    up when there is no measurement behind a piece at all.
            [
                'title' => 'Store Launch — Adajan',
                'client_name' => 'SVA Jewels',
                'description' => 'Opening-day film for the Adajan store.',
                'summary' => 'A single-day shoot covering the Adajan opening, cut for social the same evening.',
                'platform_id' => $this->termId(TaxonomyTerm::TYPE_PLATFORM, 'Instagram Reels'),
                'format_id' => $this->termId(TaxonomyTerm::TYPE_FORMAT, '9:16 vertical'),
                'duration_seconds' => 28,
                'objective_id' => $this->termId(TaxonomyTerm::TYPE_OBJECTIVE, 'Store awareness'),
                'service_type_id' => $this->termId(TaxonomyTerm::TYPE_SERVICE, 'Event coverage'),
                'tags' => ['On location', 'Behind the scenes'],
                'published_on' => '2026-04-18',
                'show_business_impact' => true,
                'views' => 410_000,
                'reach' => 300_000,
                'likes' => 8_200,
                'engagement_rate' => 3.1,
                'creative_hook' => 'The shutter going up, in one unbroken take.',
                'creative_audience' => 'Adajan and the surrounding neighbourhoods.',
            ],
        ];
    }
}
