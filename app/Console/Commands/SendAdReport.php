<?php

namespace App\Console\Commands;

use App\Models\AdReport;
use App\Services\WhatsappSender;
use App\Support\WhatsappServiceWindow;
use Illuminate\Console\Command;
use Throwable;

/**
 * Send a client their ads report link on WhatsApp.
 *
 *   php artisan ad-reports:send 1            (to the client's phone on file)
 *   php artisan ad-reports:send 1 --phone=98xxxxxxxx
 *
 * Inside the 24-hour window after the client last messaged the studio the
 * link goes as plain text; outside it Meta only accepts the approved
 * ad_report_ready template, whose button carries the token into
 * results/{{1}}. Same two routes as ProposalWhatsappSender.
 */
class SendAdReport extends Command
{
    protected $signature = 'ad-reports:send
        {report : The ad report id}
        {--phone= : Send here instead of the client\'s phone on file}';

    protected $description = 'Send an ads report link to the client on WhatsApp';

    public function handle(): int
    {
        $report = AdReport::with('client')->find($this->argument('report'));

        if (! $report) {
            $this->error('No such report.');

            return self::FAILURE;
        }

        if ($report->public_token === null) {
            $this->error('This report\'s link is switched off, so there is nothing to send.');

            return self::FAILURE;
        }

        $phone = $this->option('phone') ?: $report->client?->phone;

        if (blank($phone)) {
            $this->error('No phone number: the report has no client with one on file. Pass --phone.');

            return self::FAILURE;
        }

        $name = $report->client?->name ?? ($report->data['report']['client'] ?? 'your');
        $to = WhatsappSender::normalise($phone);

        try {
            if (WhatsappServiceWindow::isOpen($to)) {
                $result = WhatsappSender::make()->sendText(
                    $to,
                    "Hi, {$name}'s {$report->periodLabel()} ads report is ready. See how your ads performed this month:\n".$report->publicUrl()
                );
                $route = 'plain message';
            } else {
                $result = WhatsappSender::make()->sendTemplate(
                    to: $to,
                    template: AdReport::WHATSAPP_TEMPLATE,
                    bodyParameters: [$name, $report->periodLabel()],
                    buttonUrlParameter: $report->public_token,
                );
                $route = 'template '.AdReport::WHATSAPP_TEMPLATE;
            }
        } catch (Throwable $e) {
            $this->error('WhatsApp refused it: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$to} as a {$route}.");
        $this->line('  wamid: '.($result['wamid'] ?? '(none returned)'));

        return self::SUCCESS;
    }
}
