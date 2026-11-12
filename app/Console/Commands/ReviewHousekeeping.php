<?php

namespace App\Console\Commands;

use App\Models\ReviewVideo;
use App\Models\Todo;
use App\Services\Ai\VideoCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keeps video review tidy:
 *
 *   - runs any AI check that was queued and never ran (the check normally
 *     runs straight after the upload answers; this catches one that died)
 *   - deletes review files a week after the video was posted or delivered,
 *     two weeks after a newer version replaced them, and after 60 days in
 *     any case -- these are review copies; the masters stay on the drive
 *   - throws away uploads abandoned half way for a day
 *
 * Every five minutes for the checks; the deleting is cheap enough to share
 * the run.
 */
class ReviewHousekeeping extends Command
{
    protected $signature = 'reviews:housekeeping';

    protected $description = 'Run stuck AI checks on review videos and delete old review files.';

    public function handle(): int
    {
        $checked = 0;
        ReviewVideo::query()->where('ai_status', 'queued')->where('updated_at', '<=', now()->subMinutes(3))->limit(3)->get()
            ->each(function (ReviewVideo $v) use (&$checked) {
                VideoCheck::run($v);
                $checked++;
            });
        ReviewVideo::query()->where('ai_status', 'running')->where('updated_at', '<=', now()->subMinutes(30))
            ->update(['ai_status' => 'failed', 'ai_error' => 'The check did not finish. Try again.']);

        $deleted = 0;
        $old = ReviewVideo::query()->whereNull('deleted_file_at')->whereNotNull('path')->with('todo:id,posted_at,status')->get()
            ->filter(function (ReviewVideo $v) {
                $card = $v->todo;
                $newer = ReviewVideo::query()->where('todo_id', $v->todo_id)->where('version', '>', $v->version)->min('created_at');

                return ! $card
                    || $card->status === Todo::STATUS_CANCELLED
                    || ($card->posted_at && $card->posted_at->lte(now()->subDays(7)))
                    || ($newer && now()->subDays(14)->gte($newer))
                    || $v->created_at->lte(now()->subDays(60));
            });

        foreach ($old as $v) {
            foreach (array_filter([$v->path, $v->poster_path]) as $p) {
                File::delete(storage_path('app/private/'.$p));
            }
            $v->forceFill(['deleted_file_at' => now()])->save();
            $deleted++;
        }

        foreach (File::glob(storage_path('app/private/review-uploads/*')) ?: [] as $f) {
            if (filemtime($f) < now()->subDay()->timestamp) {
                File::delete($f);
            }
        }

        $this->info("AI checks run: {$checked}. Old review files deleted: {$deleted}.");

        return self::SUCCESS;
    }
}
