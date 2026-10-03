<?php

namespace App\Console\Commands;

use App\Support\InstagramGrid;
use App\Support\StudioNumbers;
use Illuminate\Console\Command;

class RefreshHomeGrid extends Command
{
    protected $signature = 'home:refresh-grid';

    protected $description = 'Copy the latest client Instagram posts for the homepage grid, and refresh its numbers';

    public function handle(): int
    {
        foreach (InstagramGrid::refresh() as $line) {
            $this->line($line);
        }

        // The homepage's figures are cached for hours; today's sync just
        // changed them.
        StudioNumbers::forget();

        return self::SUCCESS;
    }
}
