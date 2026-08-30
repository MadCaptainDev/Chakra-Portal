<?php

namespace App\Services;

use App\Models\NotionSetting;
use Illuminate\Support\Facades\Artisan;

/**
 * Refresh Notion content and Instagram metadata, then generate any due
 * recurring invoices so quantity variables see up-to-date published counts.
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

        Artisan::call('instagram:sync', ['--force' => true]);
    }
}
