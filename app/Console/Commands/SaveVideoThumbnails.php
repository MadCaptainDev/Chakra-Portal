<?php

namespace App\Console\Commands;

use App\Services\Videos\VideoThumbnail;
use Illuminate\Console\Command;

/**
 * Save the small local picture for published videos that have none yet.
 * The Instagram sync does sixty of these a night on its own; this is for
 * catching up on a backlog by hand.
 */
class SaveVideoThumbnails extends Command
{
    protected $signature = 'videos:thumbnails {--limit=60 : How many videos to try}';

    protected $description = 'Save a small local thumbnail for published videos matched to an Instagram post.';

    public function handle(): int
    {
        $saved = VideoThumbnail::backfill((int) $this->option('limit'));

        $this->info("Saved {$saved} thumbnail(s).");

        return self::SUCCESS;
    }
}
