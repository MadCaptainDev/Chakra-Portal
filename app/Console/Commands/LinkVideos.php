<?php

namespace App\Console\Commands;

use App\Services\Videos\VideoLinker;
use Illuminate\Console\Command;

class LinkVideos extends Command
{
    protected $signature = 'videos:link';

    protected $description = 'Create a Video for every Notion content item and link its script, shoot, edit, post and numbers.';

    public function handle(VideoLinker $linker): int
    {
        $result = $linker->run();
        $this->info("{$result['videos']} video(s): {$result['created']} created, {$result['updated']} updated.");

        return self::SUCCESS;
    }
}
