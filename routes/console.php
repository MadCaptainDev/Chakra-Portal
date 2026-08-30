<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires Windows Task Scheduler (or cron on Linux) to run
// `php artisan schedule:run` every minute. On a machine that may be
// off at the scheduled time, the EnsureRecurringInvoicesGenerated
// middleware provides a catch-up on the first request of the day.
Schedule::command('invoices:generate-recurring')->dailyAt('08:00');

// Materialise open routine occurrences for the day. EnsureRoutinesGenerated
// middleware is the catch-up when this host was off at 06:00.
Schedule::command('routines:generate')->dailyAt('06:00');

// Notion content (Reel/Post/YT planners) must land before recurring invoices
// that bill by {{published_*}} quantity variables. Runs before Instagram
// (02:00) and invoices:generate-recurring (08:00).
Schedule::command('notion:sync-content')->dailyAt('01:00')->timezone(config('app.timezone'));

// Keeps every connected client's Instagram cache current WITHOUT depending
// on a staff member opening that client's page. Page-view-triggered syncing
// (InstagramSyncRunner::ensureFresh()) is the fast path for "I'm looking at
// this right now"; this is the safety net underneath it -- a client nobody
// has opened in weeks would otherwise silently lose account-level history
// as it ages past Instagram's 90-day retention, unrecoverable once gone
// (found live on Digital Harvest/Janet Hospitals: April's daily reach and
// follower trend were already unrecoverable by the time anyone noticed,
// because the account had never been synced before). --force bypasses the
// per-account throttle, which is pointless friction against a job that only
// runs once a day anyway. Runs at 2am IST, after normal studio hours and
// clear of the 8am invoices job.
Schedule::command('instagram:sync --force')->dailyAt('02:00')->timezone(config('app.timezone'));

// The Notion content module UI is switched off for now, but the sync service
// and content_items rows remain — only the old everyThirtyMinutes schedule
// was removed in favour of the daily run above (before recurring invoices).
// Schedule::command('notion:sync-content')->everyThirtyMinutes();

// Shoots are a different story: the portal's Shoots screen is the only
// place either a portal-made or a Notion-made shoot is shown, and this is
// what keeps it current without someone opening the screen and pressing
// "Sync from Notion" themselves. See NotionShootImporter.
Schedule::command('notion:sync-shoots')->everyThirtyMinutes();

// Promotes due WhatsApp campaigns from scheduled to sending and queues their
// messages. Every minute, not less often: a campaign scheduled for "now" on
// the campaign form should start within a minute of that click, not wait for
// the next half-hour or daily tick the way the sync jobs above do.
//
// withoutOverlapping() is a belt-and-suspenders guard, not the actual fix for
// double-dispatch -- a large campaign's run can plausibly take longer than a
// minute (one HTTP call to Meta per pending log), so without this a second
// tick could start before the first finishes. The real fix is the per-row
// atomic claim in DispatchWhatsappCampaigns::claimAndDispatch(), which holds
// even if two runs do overlap (a manual `artisan whatsapp:dispatch-campaigns`
// bypasses this scheduler mutex entirely, for instance).
Schedule::command('whatsapp:dispatch-campaigns')->everyMinute()->withoutOverlapping();
