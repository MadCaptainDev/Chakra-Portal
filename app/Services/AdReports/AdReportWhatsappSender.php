<?php

namespace App\Services\AdReports;

use App\Models\AdReport;
use App\Services\WhatsappSender;
use App\Support\WhatsappServiceWindow;
use RuntimeException;

/**
 * Sending a client their ads report link on WhatsApp -- one path for the
 * ad-reports:send command and the send_ad_report MCP tool.
 *
 * Inside the 24-hour window after the client last messaged the studio the
 * link goes as plain text; outside it Meta only accepts the approved
 * ad_report_ready template, whose button carries the token into
 * results/{{1}}. Same two routes as ProposalWhatsappSender.
 */
class AdReportWhatsappSender
{
    /**
     * @return array{to: string, as: string, wamid: string|null}
     *
     * @throws RuntimeException when there is nothing to send, nowhere to send
     *                          it, or WhatsApp refuses
     */
    public function send(AdReport $report, ?string $phone = null): array
    {
        $report->loadMissing('client');

        if ($report->public_token === null) {
            throw new RuntimeException('This report\'s link is switched off, so there is nothing to send.');
        }

        $phone = filled($phone) ? $phone : $report->client?->phone;

        if (blank($phone)) {
            throw new RuntimeException('No phone number: the report has no client with one on file.');
        }

        $name = $report->client?->name ?? ($report->data['report']['client'] ?? 'your');
        $to = WhatsappSender::normalise($phone);

        if (WhatsappServiceWindow::isOpen($to)) {
            $result = WhatsappSender::make()->sendText(
                $to,
                "Hi, {$name}'s {$report->periodLabel()} ads report is ready. See how your ads performed this month:\n".$report->publicUrl()
            );

            return ['to' => $to, 'as' => 'plain message', 'wamid' => $result['wamid']];
        }

        $result = WhatsappSender::make()->sendTemplate(
            to: $to,
            template: AdReport::WHATSAPP_TEMPLATE,
            bodyParameters: [$name, $report->periodLabel()],
            buttonUrlParameter: $report->public_token,
        );

        return ['to' => $to, 'as' => 'template '.AdReport::WHATSAPP_TEMPLATE, 'wamid' => $result['wamid']];
    }
}
