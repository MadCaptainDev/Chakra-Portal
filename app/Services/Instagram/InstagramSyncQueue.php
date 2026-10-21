<?php

namespace App\Services\Instagram;

use App\Models\InstagramSyncJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Videos\VideoLinker;
use App\Services\ViewMilestones;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Sync all Instagram", as a queue. Pressing the button only lines up one
 * job per account; the work happens one account at a time -- straight after
 * the page answers, and every minute from the scheduler -- so nobody waits
 * on a page that takes minutes to load, and a slow or broken account never
 * holds up the rest.
 *
 * Each job first renews the account's connection when it is near expiry
 * (Instagram's long-lived tokens die after ~60 days unless refreshed), then
 * syncs the last 30 days. When the run is finished, new posts are paired
 * with the Notion planners and milestone announcements go out, as after
 * the nightly instagram:sync.
 */
class InstagramSyncQueue
{
    private const LOCK = 'instagram-sync-queue';

    /** Line up every Instagram account. Returns the run's batch id. */
    public function queueAll(?User $by): string
    {
        $open = InstagramSyncJob::query()->whereIn('status', [InstagramSyncJob::QUEUED, InstagramSyncJob::RUNNING])->latest('id')->first();
        if ($open) {
            return $open->batch; // a run is already going: show that one
        }

        $batch = (string) Str::uuid();
        SocialAccount::query()->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)->whereNotNull('access_token')
            ->with('client')->get()
            ->sortBy(fn (SocialAccount $a) => $a->client?->name)
            ->each(fn (SocialAccount $a) => InstagramSyncJob::create([
                'batch' => $batch, 'social_account_id' => $a->id, 'queued_by_id' => $by?->id, 'status' => InstagramSyncJob::QUEUED,
            ]));

        return $batch;
    }

    /**
     * Work through queued jobs for up to $seconds. One worker at a time (a
     * cache lock), so the scheduler and the button never sync one account
     * twice. Returns how many jobs it finished.
     */
    public function work(int $seconds = 50): int
    {
        $lock = Cache::lock(self::LOCK, $seconds + 120);
        if (! $lock->get()) {
            return 0;
        }

        $done = 0;
        $batches = [];
        $until = microtime(true) + $seconds;

        try {
            // A job left "running" by a worker that died is put back in line.
            InstagramSyncJob::query()->where('status', InstagramSyncJob::RUNNING)
                ->where('started_at', '<', now()->subMinutes(10))->update(['status' => InstagramSyncJob::QUEUED]);

            while (microtime(true) < $until) {
                $job = InstagramSyncJob::query()->where('status', InstagramSyncJob::QUEUED)->with('account.client')->orderBy('id')->first();
                if (! $job) {
                    break;
                }
                $this->run($job);
                $batches[$job->batch] = true;
                $done++;
            }

            foreach (array_keys($batches) as $batch) {
                if (! InstagramSyncJob::where('batch', $batch)->whereIn('status', [InstagramSyncJob::QUEUED, InstagramSyncJob::RUNNING])->exists()) {
                    $this->afterRun();
                }
            }
        } finally {
            $lock->release();
        }

        return $done;
    }

    private function run(InstagramSyncJob $job): void
    {
        $job->update(['status' => InstagramSyncJob::RUNNING, 'started_at' => now(), 'message' => null]);
        $account = $job->account;

        if (! $account || ! $account->access_token) {
            $job->update(['status' => InstagramSyncJob::FAILED, 'message' => 'Account no longer connected.', 'finished_at' => now()]);

            return;
        }

        if ($account->isExpired() || $account->status === SocialAccount::STATUS_REVOKED) {
            $job->update(['status' => InstagramSyncJob::RECONNECT, 'finished_at' => now(),
                'message' => 'The Instagram connection has expired. The client needs to connect Instagram again.']);

            return;
        }

        $renewed = $this->renewIfDue($account);

        $result = InstagramSyncRunner::run($account, now()->subDays(30)->startOfDay(), now()->endOfDay(), now()->subDays(30)->startOfDay());
        $account->refresh();
        $failed = str_starts_with($result, 'Could not sync');
        if (! $failed) {
            try {
                $covers = app(\App\Services\Portfolio\TopReelsImporter::class)->fillMissingCovers($account);
                $result .= $covers ? " {$covers} portfolio cover(s) added." : '';
            } catch (Throwable $e) {
                report($e);
            }
        }

        $job->update([
            'status' => $failed ? ($account->status === SocialAccount::STATUS_REVOKED ? InstagramSyncJob::RECONNECT : InstagramSyncJob::FAILED) : InstagramSyncJob::DONE,
            'message' => Str::limit(($renewed ? 'Connection renewed. ' : '').$result, 480),
            'finished_at' => now(),
        ]);
    }

    /**
     * Renew a long-lived token that is close to expiry. Instagram only
     * renews a token that is still alive, which is why this has to happen
     * well before the end and not when a sync first fails.
     */
    public function renewIfDue(SocialAccount $account): bool
    {
        if (! $account->needsRefreshSoon() || $account->isExpired()) {
            return false;
        }

        try {
            $fresh = app(InstagramGraph::class)->refreshToken($account->access_token);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        if (empty($fresh['access_token'])) {
            return false;
        }

        $account->forceFill([
            'access_token' => $fresh['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($fresh['expires_in'] ?? 5_184_000)),
        ])->save();

        return true;
    }

    /** What the nightly sync does once fresh numbers are in. */
    private function afterRun(): void
    {
        foreach ([
            fn () => app(InstagramContentMatcher::class)->matchAll(),
            fn () => app(VideoLinker::class)->run(),
            fn () => app(ViewMilestones::class)->run(),
        ] as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
