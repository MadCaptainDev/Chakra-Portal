<?php

namespace App\Console\Commands;

use App\Services\ViewMilestones;
use Illuminate\Console\Command;

/**
 * Post "crossed 50K views" announcements for recent reels. Runs on its own
 * after every instagram:sync; this is the manual way in, and --no-push is
 * for a backfill that should show up on dashboards without ringing phones.
 */
class AnnounceViewMilestones extends Command
{
    protected $signature = 'instagram:view-milestones {--no-push : Post the announcements without push notifications}';

    protected $description = 'Announce recent reels that crossed 10K / 25K / 50K / 100K views.';

    public function handle(ViewMilestones $milestones): int
    {
        $posted = $milestones->run(push: ! $this->option('no-push'));

        $this->info($posted > 0 ? "Posted {$posted} views milestone announcement(s)." : 'No new views milestones.');

        return self::SUCCESS;
    }
}
