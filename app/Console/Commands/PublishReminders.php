<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\User;
use App\Notifications\VideoNotPublished;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Video ready, publishing time passed 30 minutes ago, nothing marked posted:
 * push the editor and the admins, once per video (publish_reminded_at; a new
 * publishing time clears it). Scheduled every five minutes.
 */
class PublishReminders extends Command
{
    public const GRACE_MINUTES = 30;

    protected $signature = 'editing:publish-reminders';

    protected $description = 'Push when a Video ready is still not posted 30 minutes after its publishing time.';

    public function handle(): int
    {
        $late = Todo::query()
            ->whereNull('parent_id')
            ->where('status', Todo::STATUS_COMPLETED)
            ->whereNotNull('ready_at')
            ->whereNull('posted_at')
            ->whereNull('publish_reminded_at')
            ->where('publish_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            // Not a backlog alarm: only times from the last day.
            ->where('publish_at', '>=', now()->subDay())
            ->with('user')
            ->get();

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        foreach ($late as $video) {
            // Claim it first, so two runs never push twice.
            if (! Todo::query()->whereKey($video->id)->whereNull('publish_reminded_at')->update(['publish_reminded_at' => now()])) {
                continue;
            }
            try {
                Notification::send($admins->concat([$video->user])->filter()->unique("id"), new VideoNotPublished($video));
            } catch (Throwable $e) {
                Log::warning('Publish reminder push failed.', ['todo_id' => $video->id, 'error' => $e->getMessage()]);
            }
            $this->line("Reminded: {$video->title}");
        }

        $this->info($late->count() > 0 ? "{$late->count()} late video(s)." : 'Nothing late.');

        return self::SUCCESS;
    }
}
