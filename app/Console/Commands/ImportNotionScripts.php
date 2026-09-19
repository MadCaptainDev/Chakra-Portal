<?php

namespace App\Console\Commands;

use App\Services\Notion\NotionScriptImporter;
use Illuminate\Console\Command;

/**
 * Bring Notion's written scripts into the Scripts module.
 *
 * The content sync already copies Notion's "Script" property into
 * content_items.script, where nothing can be done with it -- no writer, no
 * review, no comments, and invisible unless somebody opens that one reel.
 * This turns each into a real Script so the work can be picked up.
 *
 * Safe to run repeatedly: anything that already has a Script is left
 * completely alone, so a second run imports only what Notion has written
 * since the first.
 */
class ImportNotionScripts extends Command
{
    protected $signature = 'notion:import-scripts
        {--dry : Say what would be imported without writing anything}
        {--limit= : Import at most this many, for a cautious first run}';

    protected $description = "Create portal Scripts from Notion's script text, for reels that have none yet";

    public function handle(): int
    {
        $pending = NotionScriptImporter::pendingCount();

        if ($pending === 0) {
            $this->info('Nothing to import — every Notion script already has one.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->option('dry')) {
            $this->line("{$pending} Notion ".str('script')->plural($pending).' would be imported'
                .($limit !== null ? " (limited to {$limit})" : '').'.');
            $this->line('Each becomes a draft Script with Notion\'s text in its Body section.');

            return self::SUCCESS;
        }

        $this->line("Importing {$pending} ".str('script')->plural($pending).'…');

        $result = NotionScriptImporter::importMissing($limit);

        $this->info($result['created'].' '.str('script')->plural($result['created']).' imported as drafts.');

        if ($result['unmatched_client'] > 0) {
            // Not an error: the venture map is deliberately partial. Said
            // out loud because a script with no client is harder to find.
            $this->warn($result['unmatched_client'].' could not be matched to a client '
                .'(unmapped venture — see Setup → Content Accounts).');
        }

        return self::SUCCESS;
    }
}
