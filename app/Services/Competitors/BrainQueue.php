<?php

namespace App\Services\Competitors;

use App\Models\CompetitorAccount;
use App\Models\CompetitorBrainLog;
use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Feeds the brain one reel at a time: take its script (ReelAnalysis), then
 * learn from it (CompetitorBrain::learnFrom).
 *
 * This host has no queue worker and its cron is not firing, so the work
 * moves on from page views: while a Competitors or Brain page is open it
 * reloads, each load calls nudge(), and nudge() runs the next step after the
 * response (defer) under a single lease -- one step at a time, never two.
 * `php artisan competitors:learn` does the same in a loop from SSH.
 *
 * Only accounts someone pressed "Learn from all reels" on are scripted
 * (learning_requested_at), so a Gemini bill never starts on its own.
 */
class BrainQueue
{
    private const LEASE = 'competitor-brain-lease';

    private const LEASE_MINUTES = 8;

    /** After a rate limit, page views leave Gemini alone for a while. */
    private const PAUSE = 'competitor-brain-paused';

    private const PAUSE_MINUTES = 10;

    /** Reels still to script, for accounts that asked to learn. */
    public static function pendingScripts(?CompetitorAccount $account = null)
    {
        return CompetitorReel::query()->needsScript()
            ->whereHas('account', fn ($q) => $q->whereNotNull('learning_requested_at'))
            ->when($account, fn ($q) => $q->where('competitor_account_id', $account->id))
            ->highestViewsFirst();
    }

    /** Scripts the brain has not learned from yet. */
    public static function pendingLearning(?CompetitorAccount $account = null)
    {
        return CompetitorReelAnalysis::query()->whereNotNull('transcript')->whereNull('learned_at')
            ->when($account, fn ($q) => $q->whereHas('reel', fn ($r) => $r->where('competitor_account_id', $account->id)))
            ->orderBy('id');
    }

    public static function pendingCount(?CompetitorAccount $account = null): int
    {
        return self::pendingScripts($account)->count() + self::pendingLearning($account)->count();
    }

    public static function busy(): bool
    {
        return Cache::has(self::LEASE);
    }

    /** From a page view: run the next step after the response, if nothing is running. */
    public static function nudge(): void
    {
        if (self::paused() || self::pendingCount() === 0 || ! Cache::add(self::LEASE, now()->toIso8601String(), now()->addMinutes(self::LEASE_MINUTES))) {
            return;
        }

        defer(function () {
            @set_time_limit(0);
            @ignore_user_abort(true);
            try {
                self::step();
            } finally {
                Cache::forget(self::LEASE);
            }
        });
    }

    /**
     * One step: learn from a waiting script, else script the next reel (and
     * learn from it straight away). Returns what it did, for the command.
     */
    public static function step(): ?string
    {
        if ($analysis = self::pendingLearning()->first()) {
            return self::learn($analysis);
        }

        $reel = self::pendingScripts()->with('account')->first();
        if (! $reel) {
            return null;
        }
        if (! ReelAnalysis::claim($reel)) {
            return 'skipped (busy) @'.$reel->account?->username;
        }

        try {
            $analysis = ReelAnalysis::run($reel);
        } catch (Throwable $e) {
            // Google's per-minute/day limit, or the monthly cap: not this reel's fault.
            // Leave it queued and stop for now; the next run picks it up.
            if (self::isLimit($e)) {
                CompetitorBrainLog::write('paused', 'Paused: '.Str::limit($e->getMessage(), 200).' The reel stays in the queue.',
                    ['competitor_account_id' => $reel->competitor_account_id, 'competitor_reel_id' => $reel->id]);
                Cache::put(self::PAUSE, true, now()->addMinutes(self::PAUSE_MINUTES));

                return 'paused (rate limit)';
            }
            CompetitorBrainLog::write('failed', 'Could not take the script of @'.$reel->account?->username.'\'s reel: '.$e->getMessage(),
                ['competitor_account_id' => $reel->competitor_account_id, 'competitor_reel_id' => $reel->id]);
            // Out of the queue until the next scrape gives it a fresh link.
            $reel->forceFill(['scraped_at' => now()->subDays(CompetitorReel::FRESH_DAYS + 1)])->save();
            if (! $e instanceof CompetitorAnalysisException) {
                report($e);
            }

            return 'script failed @'.$reel->account?->username.': '.$e->getMessage();
        } finally {
            ReelAnalysis::release($reel);
        }

        return 'scripted @'.$reel->account?->username.' ('.number_format((int) $reel->play_count).' views); '.self::learn($analysis);
    }

    /** A rate limit or spending cap: wait, don't drop. */
    public static function isLimit(Throwable $e): bool
    {
        return ($e instanceof CompetitorAnalysisException && in_array($e->status, [429, 503], true))
            || preg_match('/\b429\b|rate limit|quota|limit is used up|high demand|overloaded/i', $e->getMessage()) === 1;
    }

    public static function paused(): bool
    {
        return Cache::has(self::PAUSE);
    }

    private static function learn(CompetitorReelAnalysis $analysis): string
    {
        try {
            $done = CompetitorBrain::learnFrom($analysis);

            return "learned {$done['learned']}, confirmed {$done['confirmed']}, contradicted {$done['contradicted']}";
        } catch (Throwable $e) {
            if (self::isLimit($e)) {
                Cache::put(self::PAUSE, true, now()->addMinutes(self::PAUSE_MINUTES));

                return 'paused (rate limit)';
            }
            CompetitorBrainLog::write('failed', 'Could not learn from a script: '.$e->getMessage(), ['competitor_reel_id' => $analysis->competitor_reel_id]);
            // Do not block the queue on one bad answer: mark it learned-with-nothing.
            $analysis->forceFill(['learned_at' => now()])->save();
            if (! $e instanceof CompetitorAnalysisException) {
                report($e);
            }

            return 'learning failed: '.$e->getMessage();
        }
    }
}
