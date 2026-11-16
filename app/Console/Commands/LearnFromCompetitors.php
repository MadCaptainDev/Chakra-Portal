<?php

namespace App\Console\Commands;

use App\Models\CompetitorAccount;
use App\Services\Competitors\BrainQueue;
use Illuminate\Console\Command;

/**
 * Feed the competitor brain from SSH: take every waiting reel's script and
 * learn from it, one at a time, until nothing is left (or --max steps).
 *
 *     php artisan competitors:learn            # accounts already asked to learn
 *     php artisan competitors:learn --all      # ask every tracked account first
 */
class LearnFromCompetitors extends Command
{
    protected $signature = 'competitors:learn {--all : Turn learning on for every tracked account first} {--max=100 : Most steps in this run}';

    protected $description = 'Take competitor reels\' scripts with Gemini and teach the competitor brain';

    public function handle(): int
    {
        if ($this->option('all')) {
            CompetitorAccount::query()->whereNull('learning_requested_at')->update(['learning_requested_at' => now()]);
        }

        $this->info(BrainQueue::pendingCount().' steps waiting.');
        for ($i = 0; $i < (int) $this->option('max'); $i++) {
            $did = BrainQueue::step();
            if ($did === null) {
                $this->info('Done — nothing left to learn from.');

                return self::SUCCESS;
            }
            $this->line('→ '.$did);
        }

        return self::SUCCESS;
    }
}
