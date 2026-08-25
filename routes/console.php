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

// The Notion content module (YouTube/Reel/Post/Story board) is switched off
// for now. The sync service, the notion:sync-content command and the synced
// content_items rows are all still in place -- only the schedule and the UI
// are disabled, so re-enabling it is uncommenting this line and restoring
// the routes/views.
// Schedule::command('notion:sync-content')->everyThirtyMinutes();

// Shoots are a different story: the portal's Shoots screen is the only
// place either a portal-made or a Notion-made shoot is shown, and this is
// what keeps it current without someone opening the screen and pressing
// "Sync from Notion" themselves. See NotionShootImporter.
Schedule::command('notion:sync-shoots')->everyThirtyMinutes();
