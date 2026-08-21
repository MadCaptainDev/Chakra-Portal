<?php

namespace App\Console\Commands;

use App\Models\NotionSetting;
use App\Models\NotionShoot;
use App\Services\Notion\ContentSyncService;
use App\Services\Notion\NotionShootImporter;
use Illuminate\Console\Command;

class SyncNotionContent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notion:sync-content {--fresh : Re-discover which databases are shared instead of using the cached list}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull the latest items from the YouTube, Reel, Post, Story and Shoots Notion databases into the portal.';

    public function handle(ContentSyncService $service, NotionShootImporter $importer): int
    {
        if (! NotionSetting::current()->api_key) {
            $this->error('No Notion API key saved. Add one under Setup → Notion.');

            return self::FAILURE;
        }

        $counts = $service->syncAll(fresh: (bool) $this->option('fresh'));
        $available = $service->sourceAvailability();

        foreach ($counts as $source => $count) {
            $available[$source] ?? false
                ? $this->info("{$source}: {$count} item(s) synced.")
                : $this->warn("{$source}: not shared with the integration — skipped.");
        }

        // Notion shoots become real portal shoots right away -- there is
        // no separate "import" step or screen any more.
        if ($available[NotionShoot::SOURCE] ?? false) {
            $importer->autoMapClients();
            $result = $importer->importAll();
            $this->info("shoot: {$result['imported']} imported, {$result['updated']} refreshed, {$result['skipped']} skipped (no date in Notion).");
        }

        $unreachable = array_keys(array_filter($available, fn ($ok) => ! $ok));

        if ($unreachable !== []) {
            $this->newLine();
            $this->warn('Share these databases with your Notion integration to sync them: '.implode(', ', $unreachable));
            $this->line('In Notion: open the database → ••• → Connections → add your integration.');
        }

        return self::SUCCESS;
    }
}
