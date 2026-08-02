<?php

namespace App\Console\Commands;

use App\Services\Notion\ContentSyncService;
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
    protected $description = 'Pull the latest items from the YouTube, Reel, Post, and Story Notion planners into the local content_items table.';

    public function handle(ContentSyncService $service): int
    {
        if (! config('notion.api_key')) {
            $this->error('NOTION_API_KEY is not set.');

            return self::FAILURE;
        }

        $counts = $service->syncAll(fresh: (bool) $this->option('fresh'));
        $available = $service->sourceAvailability();

        foreach ($counts as $source => $count) {
            $available[$source] ?? false
                ? $this->info("{$source}: {$count} item(s) synced.")
                : $this->warn("{$source}: not shared with the integration — skipped.");
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
