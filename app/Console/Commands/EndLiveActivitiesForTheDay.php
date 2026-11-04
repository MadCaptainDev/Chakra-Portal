<?php

namespace App\Console\Commands;

use App\Services\LiveActivities\EditingBoardActivity;
use Illuminate\Console\Command;

class EndLiveActivitiesForTheDay extends Command
{
    protected $signature = 'live-activities:end-day';

    protected $description = 'End the admins\' EditingBoard Live Activity for the night (iPhone app).';

    public function handle(): int
    {
        $this->info('Ended '.EditingBoardActivity::endDay().' EditingBoard activities.');

        return self::SUCCESS;
    }
}
