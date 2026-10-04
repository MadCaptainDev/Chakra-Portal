<?php

namespace App\Tools\AdReports;

use App\Models\AdReport;
use App\Models\User;
use App\Services\AdReports\AdReportWhatsappSender;
use App\Tools\Tool;
use App\Tools\ToolException;
use Throwable;

class SendAdReport extends Tool
{
    public function name(): string
    {
        return 'send_ad_report';
    }

    public function title(): string
    {
        return 'Send an ads report to the client on WhatsApp';
    }

    public function group(): string
    {
        return 'Ad reports';
    }

    public function description(): string
    {
        return 'Send a client their monthly ads report link on WhatsApp, from the studio number. '
            .'THIS MESSAGES A REAL CLIENT. Only call it when the person has explicitly asked to send this '
            .'report, and after they have seen any warnings import_ad_report returned. Never send '
            .'speculatively or to "test". '
            .'Goes to the client\'s phone on file unless a phone is given. Inside 24 hours of the client '
            .'last messaging the studio it goes as plain text; otherwise as the approved ad_report_ready template.';
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function messagesClient(): bool
    {
        return true;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'report_id' => ['type' => 'integer', 'description' => 'The ad report id, from import_ad_report or list_ad_reports.'],
            'phone' => ['type' => 'string', 'description' => 'Optional. Only if the person gave a different number than the client\'s on file.'],
        ], ['report_id']);
    }

    public function handle(array $arguments, User $user): array
    {
        $report = AdReport::find((int) ($arguments['report_id'] ?? 0))
            ?? throw new ToolException('There is no ad report with that id. Use list_ad_reports.');

        $phone = trim((string) ($arguments['phone'] ?? ''));

        if ($phone !== '' && ! preg_match('/^[0-9+\-\s()]{10,20}$/', $phone)) {
            throw new ToolException('"'.$phone.'" does not look like a phone number.');
        }

        try {
            $sent = app(AdReportWhatsappSender::class)->send($report, $phone ?: null);
        } catch (Throwable $e) {
            throw new ToolException('Not sent: '.$e->getMessage());
        }

        return [
            'sent' => true,
            'to' => $sent['to'],
            'as' => $sent['as'],
            'note' => '"sent" means WhatsApp accepted it; delivery is confirmed separately.',
            'client_link' => $report->publicUrl(),
        ];
    }
}
