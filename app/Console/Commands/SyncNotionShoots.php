<?php

namespace App\Console\Commands;

use App\Models\NotionSetting;
use App\Models\NotionShoot;
use App\Services\Notion\ContentSyncService;
use App\Services\Notion\NotionShootImporter;
use Illuminate\Console\Command;

/**
 * Syncs only the Shoots database, independent of the YouTube/Reel/Post/
 * Story content board -- which stays switched off (see routes/console.php)
 * while this runs on its own schedule. Production scheduling shouldn't
 * wait on that board being turned back on.
 */
class SyncNotionShoots extends Command
{
    protected $signature = 'notion:sync-shoots';

    protected $description = 'Pull the Shoots database from Notion and fold it into the portal\'s own Shoots screen.';

    public function handle(ContentSyncService $service, NotionShootImporter $importer): int
    {
        if (! NotionSetting::current()->api_key) {
            $this->error('No Notion API key saved. Add one under Setup → Notion.');

            return self::FAILURE;
        }

        if (! ($service->sourceAvailability()[NotionShoot::SOURCE] ?? false)) {
            $this->warn('Shoots database not shared with the integration — skipped.');
            $this->line('In Notion: open the Shoots database → ••• → Connections → add your integration.');

            return self::FAILURE;
        }

        $synced = $service->syncSource(NotionShoot::SOURCE);
        $importer->autoMapClients();
        $result = $importer->importAll();

        $this->info("Synced {$synced} shoot(s) from Notion: {$result['imported']} imported, {$result['updated']} refreshed, {$result['skipped']} skipped (no date in Notion).");

        return self::SUCCESS;
    }
}
