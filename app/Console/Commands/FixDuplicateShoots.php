<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\Notion\NotionShootWriter;
use App\Services\ShootMerger;
use Illuminate\Console\Command;

/**
 * Put the portal and Notion back to one shoot each:
 *
 *   1. merge each shoot that was booked in the portal AND made again in
 *      Notion (same client, same day) into one row, linked to the Notion page
 *   2. create in Notion every remaining portal shoot that has no page there
 *   3. list same-day shoots that are two separate Notion pages, for a person
 *      to check -- several shoots for one client on one day is often real
 */
class FixDuplicateShoots extends Command
{
    protected $signature = 'shoots:fix-duplicates {--dry : Show what would change, change nothing}';

    protected $description = 'Merge shoots duplicated between the portal and Notion, and create missing ones in Notion.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $pairs = ShootMerger::pairs();
        $this->info(($dry ? 'Would merge ' : 'Merging ').$pairs->count().' duplicate shoot(s):');
        foreach ($pairs as [$portal, $notion]) {
            $line = $portal->starts_at->format('j M').' · #'.$portal->id.' "'.trim($portal->title).'" + #'.$notion->id.' "'.trim($notion->title).'" (Notion)';
            if (! $dry) {
                $kept = ShootMerger::merge($portal, $notion);
                $line .= ' → kept #'.$kept->id;
            }
            $this->line('  '.$line);
        }

        // A shoot named just "test" is somebody trying the form, not a shoot.
        $missing = Shoot::query()->whereNull('notion_shoot_id')->where('status', '!=', Shoot::STATUS_CANCELLED)->orderBy('starts_at')->get()
            ->reject(fn (Shoot $s) => preg_match('/^\s*test\b/i', (string) $s->title));
        $this->info(($dry ? 'Would create ' : 'Creating ').$missing->count().' shoot(s) in Notion:');
        foreach ($missing as $shoot) {
            $ok = $dry ? null : NotionShootWriter::create($shoot);
            $this->line('  '.$shoot->starts_at->format('j M').' · #'.$shoot->id.' "'.trim($shoot->title).'"'.($dry ? '' : ($ok ? ' ✓' : ' — failed, see the log')));
        }

        $notionSide = ShootMerger::notionSide();
        if ($notionSide->isNotEmpty()) {
            $this->warn($notionSide->count().' day(s) with more than one Notion page for the same client (left alone -- check in Notion):');
            foreach ($notionSide as $group) {
                $this->line('  '.$group->first()->starts_at->format('j M').' · '.$group->map(fn ($s) => '"'.trim($s->title).'"')->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
