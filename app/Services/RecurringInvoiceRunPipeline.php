<?php

namespace App\Services;

use App\Models\NotionSetting;
use Illuminate\Support\Facades\Artisan;

/**
 * Refresh Notion content, then generate any due recurring invoices so
 * {{published_*}} quantity variables (InvoiceQuantityVariable, which reads
 * ContentItem only) see up-to-date counts.
 *
 * Deliberately does NOT also force-sync Instagram here, even though it
 * looks like related "maintenance while we're at it" work: invoice
 * quantities never read Instagram data, Instagram already has its own
 * dedicated daily 2am schedule and its own `instagram.catchup` middleware
 * on the same admin routes this pipeline's `recurring.catchup` rides on,
 * and syncing every connected account's insights is the slow part of this
 * pipeline (minutes, not seconds, once a studio has more than a handful of
 * clients). This pipeline is also the body of the recurring.catchup
 * middleware, run synchronously inside a web request on a day the box's
 * cron didn't fire in time -- a multi-minute run there routinely outlives
 * the host's request timeout, killing the request before invoice
 * generation itself is ever reached, while still leaving that day's
 * once-per-day cache claim spent. Found live: five schedules due
 * 2026-09-01 sat ungenerated because this pipeline was taking ~9 minutes
 * per run purely from the Instagram leg.
 */
class RecurringInvoiceRunPipeline
{
    public function __construct(private readonly RecurringInvoiceGenerator $generator) {}

    public function run(): int
    {
        if (! app()->runningUnitTests()) {
            $this->refreshSources();
        }

        return $this->generator->run();
    }

    private function refreshSources(): void
    {
        if (NotionSetting::current()->api_key) {
            Artisan::call('notion:sync-content');
        }
    }
}
