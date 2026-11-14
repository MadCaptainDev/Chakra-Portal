<?php

namespace App\Services;

use App\Models\ReviewComment;
use App\Models\ReviewSetting;
use App\Models\ReviewVideo;
use App\Models\User;
use App\Notifications\VideoReviewUpdate;
use App\Services\Ai\VideoCheck;
use App\Services\Ai\VoiceNoteTranscriber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Keeps video review moving without the every-minute job, which does not run
 * on this host: after any page anywhere in the portal has been sent, at most
 * once every 25 seconds, it
 *
 *  - carries one waiting AI check on by a slice (before, a check moved only
 *    while somebody had that review open, and 1 in 4 timed out), and
 *  - asks Gemini again for a voice note it was too busy to write down, and
 *  - reminds the reviewers, once, about a cut that has waited 3 hours
 *    (9 AM to 9 PM only).
 *
 * Runs after the response (app terminating), so nobody's page is slower.
 */
class ReviewPulse
{
    private const EVERY_SECONDS = 25;

    private const CHECK_SLICE = 20;

    public const REMIND_AFTER_HOURS = 3;

    public static function tick(): void
    {
        if (! Cache::add('reviews:pulse', 1, self::EVERY_SECONDS)) {
            return;
        }

        try {
            self::moveChecks();
            self::retryVoice();
            self::remind();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** One check that wants work, a slice of it. */
    public static function moveChecks(): void
    {
        $video = ReviewVideo::query()->where('version', 1)->whereIn('ai_status', ['queued', 'running', 'failed'])
            ->orderBy('id')->get()->first(fn (ReviewVideo $v) => VideoCheck::wantsWork($v));

        if ($video) {
            ignore_user_abort(true);
            VideoCheck::run($video, self::CHECK_SLICE);
        }
    }

    /** One voice note Gemini was too busy for, asked again (a few minutes apart). */
    public static function retryVoice(): void
    {
        $note = ReviewComment::query()->where('voice_state', 'pending')
            ->where('voice_attempts', '>', 0)->where('voice_attempts', '<', VoiceNoteTranscriber::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subMinutes(VoiceNoteTranscriber::RETRY_MINUTES))
            ->orderBy('id')->first();

        if ($note) {
            ignore_user_abort(true);
            app(VoiceNoteTranscriber::class)->transcribe($note);
        }
    }

    /** Cuts waiting past the mark, their reviewers told once. */
    public static function remind(): int
    {
        $hour = (int) now()->format('G');
        if ($hour < 9 || $hour >= 21) {
            return 0;
        }

        $sent = 0;
        $waiting = ReviewVideo::query()->with('todo.user')
            ->where('status', ReviewVideo::PENDING)->whereNull('reminded_at')->whereNull('deleted_file_at')
            ->where('created_at', '<=', now()->subHours(self::REMIND_AFTER_HOURS))
            ->get();

        foreach ($waiting as $video) {
            // Claim it first, so two requests never send the same reminder.
            if (! ReviewVideo::query()->whereKey($video->id)->whereNull('reminded_at')->update(['reminded_at' => now()])) {
                continue;
            }
            $people = self::stillToDecide($video);
            if ($people->isEmpty() || ! $video->todo) {
                continue;
            }

            Notification::send($people, new VideoReviewUpdate($video,
                'Still waiting for review: '.$video->todo->title,
                ($video->todo->user?->name ?? 'The editor').' uploaded '.($video->version > 1 ? 'v'.$video->version.' ' : '').$video->created_at->diffForHumans().'. Approve it or send it back.'));
            $sent++;
        }

        return $sent;
    }

    /**
     * Who the cut is waiting on: required approvers who have not approved;
     * with none set, the people who review this editor (admins and managers).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function stillToDecide(ReviewVideo $video)
    {
        $editor = $video->todo?->user;
        $approved = $video->approvals()->pluck('user_id');
        $required = ReviewSetting::current()->approvers();

        $people = $required->isNotEmpty()
            ? $required
            : User::query()->where('role', User::ROLE_ADMIN)->get()->merge($editor?->managers()->get() ?? collect());

        return $people->unique('id')
            ->reject(fn (User $u) => $u->id === $editor?->id || $approved->contains($u->id))
            ->values();
    }
}
