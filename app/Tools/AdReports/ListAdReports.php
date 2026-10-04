<?php

namespace App\Tools\AdReports;

use App\Models\AdReport;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class ListAdReports extends Tool
{
    public function name(): string
    {
        return 'list_ad_reports';
    }

    public function title(): string
    {
        return 'List monthly ads reports';
    }

    public function group(): string
    {
        return 'Ad reports';
    }

    public function description(): string
    {
        return 'List the monthly ads reports stored in the portal: client, month, link, and whether the '
            .'client has opened it. USE WHEN asked "who has had this month\'s report", "has Thillai seen '
            .'it", or before sending one. Optionally filter by client and/or month. Read-only.';
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Optional. Client id or portal name.'],
            'month' => ['type' => 'string', 'description' => 'Optional. YYYY-MM, e.g. 2026-09.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $query = AdReport::query()->with('client')->orderByDesc('period_start')->orderBy('client_id');

        if (filled($arguments['client'] ?? null)) {
            $query->where('client_id', ClientResolver::resolve($arguments['client'])->id);
        }

        if (filled($arguments['month'] ?? null)) {
            if (! preg_match('/^\d{4}-\d{2}$/', (string) $arguments['month'])) {
                throw new ToolException('month must be YYYY-MM, e.g. 2026-09.');
            }
            $start = Carbon::createFromFormat('Y-m-d', $arguments['month'].'-01');
            $query->whereDate('period_start', $start->toDateString());
        }

        $reports = $query->limit(50)->get();

        return [
            'count' => $reports->count(),
            'reports' => $reports->map(fn (AdReport $r) => [
                'report_id' => $r->id,
                'client' => $r->client?->name ?? ($r->data['report']['client'] ?? null),
                'platform' => $r->platform,
                'period' => $r->periodLabel(),
                'amount_spent' => $r->data['summary']['amount_spent'] ?? null,
                'results' => $r->data['summary']['results'] ?? null,
                'client_link' => $r->publicUrl(),
                'client_opened_at' => $r->first_viewed_at?->format('Y-m-d H:i'),
            ])->all(),
        ];
    }
}
