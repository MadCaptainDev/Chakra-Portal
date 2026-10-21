<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramSyncQueue;
use Illuminate\Console\Command;

class WorkInstagramSyncQueue extends Command
{
    protected $signature = 'instagram:work-queue {--seconds=50 : How long to keep working}';

    protected $description = 'Work through queued "Sync all Instagram" jobs, one account at a time';

    public function handle(InstagramSyncQueue $queue): int
    {
        $done = $queue->work((int) $this->option('seconds'));
        if ($done) {
            $this->info("Synced {$done} account(s).");
        }

        return self::SUCCESS;
    }
}
