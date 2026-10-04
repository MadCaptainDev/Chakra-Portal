<?php

namespace App\Tools\AdReports;

use App\Models\AdReport;
use App\Models\User;
use App\Services\AdReports\AdReportImporter;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;

class ImportAdReport extends Tool
{
    public function name(): string
    {
        return 'import_ad_report';
    }

    public function title(): string
    {
        return 'Import a monthly ads report';
    }

    public function group(): string
    {
        return 'Ad reports';
    }

    public function description(): string
    {
        return 'Store a monthly paid-ads report (the JSON produced by the Meta Ads report prompt) against '
            .'a portal client, and return its client-facing link. '
            .'USE WHEN: the person has a finished report JSON and wants it in the portal or wants a link. '
            .'The JSON must have exactly these top-level keys: report, ad_accounts, summary, campaigns, '
            .'funds, budget_changes, timeline, insights, recommendations. Never invent or adjust numbers '
            .'in it -- pass it exactly as produced. '
            .'The portal cross-checks the numbers (campaign spend vs total, daily charges vs billed, '
            .'top-ups vs added, best performer really cheapest) and returns any disagreements as '
            .'"warnings": show them to the person before suggesting the link is sent. '
            .'Set check_only=true to run the checks without saving. '
            .'Importing the same client and month again REPLACES that report and keeps its existing link. '
            .'This does NOT message anyone; use send_ad_report for that, and only when asked. '
            .'The client page hides the insights, recommendations, staff names and account ids.';
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => [
                'type' => 'string',
                'description' => 'The PORTAL client this report is for: its id (preferred) or its exact portal name. '
                    .'Not the name written inside the report -- those often differ ("Thillai Pet Clinic" vs "Thillai Pets Clinic").',
            ],
            'report' => [
                'type' => 'object',
                'description' => 'The whole report JSON object, unchanged.',
            ],
            'check_only' => [
                'type' => 'boolean',
                'description' => 'true = only run the checks and report problems/warnings; nothing is saved.',
            ],
        ], ['client', 'report']);
    }

    public function handle(array $arguments, User $user): array
    {
        $data = $arguments['report'] ?? null;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (! is_array($data)) {
            throw new ToolException('"report" must be the report JSON object.');
        }

        $client = ClientResolver::resolve($arguments['client'] ?? null);
        $importer = app(AdReportImporter::class);

        if ($problems = $importer->problems($data)) {
            throw new ToolException('This report cannot be imported: '.implode(' ', $problems));
        }

        $warnings = $importer->warnings($data);

        if (($arguments['check_only'] ?? false) === true) {
            return [
                'saved' => false,
                'client' => $client->name,
                'warnings' => $warnings,
                'verdict' => $warnings === [] ? 'All numbers agree.' : 'The numbers disagree in the places listed.',
            ];
        }

        $existed = AdReport::query()
            ->where('client_id', $client->id)
            ->where('platform', $data['report']['platform'] ?? 'Meta Ads')
            ->whereDate('period_start', $data['report']['period']['start'])
            ->exists();

        $report = $importer->import($data, $client->id, $user->id);

        return [
            'saved' => true,
            'report_id' => $report->id,
            'client' => $client->name,
            'period' => $report->periodLabel(),
            'replaced_existing' => $existed,
            'client_link' => $report->publicUrl(),
            'link_switched_off' => $report->public_token === null,
            'warnings' => $warnings,
        ];
    }
}
