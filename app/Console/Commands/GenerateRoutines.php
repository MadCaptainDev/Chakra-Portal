<?php

namespace App\Console\Commands;

use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Console\Command;

class GenerateRoutines extends Command
{
    protected $signature = 'inbox-check:generate';

    protected $description = 'Generate the Comment & Message Checker's open checks for today, catching up within catch_up_days.';

    public function handle(RoutineOccurrenceGenerator $generator): int
    {
        $created = $generator->run();

        $this->info($created > 0
            ? "Generated {$created} routine occurrence(s)."
            : 'No routine occurrences were due.');

        return self::SUCCESS;
    }
}
