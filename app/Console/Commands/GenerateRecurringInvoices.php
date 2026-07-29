<?php

namespace App\Console\Commands;

use App\Services\RecurringInvoiceGenerator;
use Illuminate\Console\Command;

class GenerateRecurringInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:generate-recurring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate pending-approval invoices for any recurring schedule that is due, catching up on missed periods.';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        $created = $generator->run();

        $this->info($created > 0
            ? "Generated {$created} pending invoice(s)."
            : 'No recurring invoices were due.');

        return self::SUCCESS;
    }
}
