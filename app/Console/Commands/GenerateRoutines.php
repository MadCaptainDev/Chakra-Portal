<?php

namespace App\Console\Commands;

use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Console\Command;

class GenerateRoutines extends Command
{
    protected $signature = 'routines:generate';

    protected $description = 'Generate open routine occurrences for every active routine that is due, catching up within catch_up_days.';

    public function handle(RoutineOccurrenceGenerator $generator): int
    {
        $created = $generator->run();

        $this->info($created > 0
            ? "Generated {$created} routine occurrence(s)."
            : 'No routine occurrences were due.');

        return self::SUCCESS;
    }
}
