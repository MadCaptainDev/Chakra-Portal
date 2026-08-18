<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Instagram\InstagramException;
use App\Services\Instagram\InstagramInsights;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull fresh Instagram analytics for every connected account.
 *
 *     php artisan instagram:sync
 *     php artisan instagram:sync --client=13
 *     php artisan instagram:sync --days=90
 *
 * One client's failure does not stop the rest -- each account is wrapped in
 * its own try/catch, exactly like GenerateRecurringInvoices treats each
 * invoice. A studio with twenty connected clients should not lose the other
 * nineteen because one token expired overnight.
 *
 * NOT scheduled to run automatically yet. See routes/console.php: this
 * server has no crontab, so `Schedule::command()` entries never fire without
 * one. Run this by hand, or via SSH cron, until that is set up.
 */
class SyncInstagramInsights extends Command
{
    protected $signature = 'instagram:sync
        {--client= : Sync only the account belonging to this client id}
        {--days=30 : How many days of account-level history to pull}
        {--force : Sync even an account still inside its throttle window}';

    protected $description = 'Fetch and cache Instagram analytics for connected accounts';

    public function handle(): int
    {
        $accounts = SocialAccount::query()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->with('client')
            ->when($this->option('client'), fn ($q, $id) => $q->where('client_id', $id))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No connected Instagram accounts to sync.');

            return self::SUCCESS;
        }

        $since = now()->subDays((int) $this->option('days'))->startOfDay();
        $until = now()->endOfDay();
        $insights = InstagramInsights::make();
        $failures = 0;
        $throttled = 0;

        foreach ($accounts as $account) {
            $label = $account->client?->name ?? 'client #'.$account->client_id;
            $this->line("→ {$label} ({$account->handle()})");

            if (! $this->option('force') && ! $account->canSyncNow()) {
                $throttled++;
                $this->comment('  Skipped — synced too recently. Use --force to sync anyway.');

                continue;
            }

            try {
                $result = $insights->syncAll($account, $since, $until);

                $account->forceFill(['last_synced_at' => now()])->save();
                $account->clearFailure();
                $account->refreshLinkedPortfolioItems();

                $this->info(sprintf(
                    '  OK — %d account metric(s), %d item(s) with %d media metric(s)',
                    $result['account']['synced'],
                    $result['media']['items'],
                    $result['media']['synced'],
                ));

                $skipped = array_unique([...$result['account']['skipped'], ...$result['media']['skipped']]);

                if ($skipped !== []) {
                    $this->comment('  Not supported for this account, skipped: '.implode(', ', $skipped));
                }
            } catch (InstagramException $e) {
                $failures++;
                $account->recordFailure($e->userMessage(), fatal: $e->isAuthFailure());
                $this->error('  FAILED — '.$e->userMessage());
            } catch (Throwable $e) {
                // Deliberately not $e->getMessage() to the terminal history:
                // an unexpected exception here may be carrying a request body,
                // and that body can contain the token.
                $failures++;
                $account->recordFailure('Sync failed unexpectedly — see the log.');
                report($e);
                $this->error('  FAILED — unexpected error, logged.');
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d synced, %d failed, %d throttled.',
            $accounts->count() - $failures - $throttled,
            $failures,
            $throttled,
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
