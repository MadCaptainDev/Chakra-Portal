<?php

namespace App\Console\Commands;

use App\Models\AdReport;
use App\Services\AdReports\AdReportWhatsappSender;
use Illuminate\Console\Command;
use Throwable;

/**
 * Send a client their ads report link on WhatsApp.
 *
 *   php artisan ad-reports:send 1            (to the client's phone on file)
 *   php artisan ad-reports:send 1 --phone=98xxxxxxxx
 *
 * The sending itself is AdReportWhatsappSender, shared with the
 * send_ad_report MCP tool.
 */
class SendAdReport extends Command
{
    protected $signature = 'ad-reports:send
        {report : The ad report id}
        {--phone= : Send here instead of the client\'s phone on file}';

    protected $description = 'Send an ads report link to the client on WhatsApp';

    public function handle(AdReportWhatsappSender $sender): int
    {
        $report = AdReport::find($this->argument('report'));

        if (! $report) {
            $this->error('No such report.');

            return self::FAILURE;
        }

        try {
            $sent = $sender->send($report, $this->option('phone'));
        } catch (Throwable $e) {
            $this->error('Not sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$sent['to']} as a {$sent['as']}.");
        $this->line('  wamid: '.($sent['wamid'] ?? '(none returned)'));

        return self::SUCCESS;
    }
}
