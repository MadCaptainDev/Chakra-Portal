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

// The Notion content module is switched off for now. The sync service, the
// notion:sync-content command and the synced content_items rows are all still
// in place -- only the schedule and the UI are disabled, so re-enabling it is
// uncommenting this line and restoring the routes/views.
// Schedule::command('notion:sync-content')->everyThirtyMinutes();
