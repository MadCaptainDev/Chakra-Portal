<?php

namespace App\Console\Commands;

use App\Models\PortfolioItem;
use App\Models\TaxonomyTerm;
use Illuminate\Console\Command;

/**
 * Sets every portfolio piece's format/platform to 9:16 vertical / Instagram
 * Reels -- the studio's default shape for everything it publishes now.
 *
 * Unconditional: this overwrites format_id/platform_id on EVERY item,
 * including ones already carrying a different value, per an explicit
 * decision to standardise the whole catalogue on one shape rather than
 * only filling in blanks. Safe to run again -- it just re-sets the same
 * two ids, so a second run changes nothing.
 */
class SetPortfolioDefaultFormat extends Command
{
    protected $signature = 'portfolio:set-default-format';

    protected $description = "Set every portfolio item's format to 9:16 vertical and platform to Instagram Reels";

    public function handle(): int
    {
        $format = TaxonomyTerm::where('type', TaxonomyTerm::TYPE_FORMAT)->where('name', '9:16 vertical')->first();
        $platform = TaxonomyTerm::where('type', TaxonomyTerm::TYPE_PLATFORM)->where('name', 'Instagram Reels')->first();

        if (! $format || ! $platform) {
            $this->error('Missing the "9:16 vertical" format or "Instagram Reels" platform term -- run the taxonomy seeder first.');

            return self::FAILURE;
        }

        $count = PortfolioItem::query()->update([
            'format_id' => $format->id,
            'platform_id' => $platform->id,
        ]);

        $this->info("Set format and platform on {$count} portfolio item(s).");

        return self::SUCCESS;
    }
}
