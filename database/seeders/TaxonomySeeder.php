<?php

namespace Database\Seeders;

use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;

/**
 * Starter values for the master lists.
 *
 * A fresh install has empty pickers, which reads as broken rather than as
 * "nothing configured yet". These are a studio's sensible defaults, not
 * gospel: every one can be renamed, reordered or retired from the Master data
 * screen, and nothing in the app depends on a particular term existing.
 *
 * Idempotent -- it adds what is missing and leaves edits alone.
 */
class TaxonomySeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private const STARTERS = [
        TaxonomyTerm::TYPE_PLATFORM => [
            'Instagram Reels', 'Instagram Post', 'YouTube', 'YouTube Shorts',
            'Facebook', 'LinkedIn', 'WhatsApp', 'In store', 'Client website',
        ],
        TaxonomyTerm::TYPE_FORMAT => [
            '9:16 vertical', '1:1 square', '16:9 landscape', '4:5 portrait', 'Carousel', 'Stills',
        ],
        TaxonomyTerm::TYPE_OBJECTIVE => [
            'Awareness', 'Awareness + sales', 'Product launch', 'Collection launch',
            'Store awareness', 'Recruitment', 'Brand film',
        ],
        TaxonomyTerm::TYPE_SERVICE => [
            'Product Reel', 'Brand film', 'Event coverage', 'Testimonial',
            'Explainer', 'Photography', 'Content retainer',
        ],
        TaxonomyTerm::TYPE_INDUSTRY => [
            'Jewellery', 'Fashion', 'Food & beverage', 'Hospitality', 'Retail',
            'Real estate', 'Healthcare', 'Education', 'Fitness', 'Professional services',
        ],
        TaxonomyTerm::TYPE_TAG => [
            'Bridal', 'Festive', 'Behind the scenes', 'Founder led',
            'UGC style', 'Macro', 'Studio', 'On location', 'Voiceover', 'No dialogue',
        ],
    ];

    public function run(): void
    {
        foreach (self::STARTERS as $type => $names) {
            foreach ($names as $order => $name) {
                TaxonomyTerm::firstOrCreate(
                    ['type' => $type, 'slug' => TaxonomyTerm::uniqueSlug($type, $name)],
                    ['name' => $name, 'sort_order' => $order, 'is_active' => true],
                );
            }
        }
    }
}
