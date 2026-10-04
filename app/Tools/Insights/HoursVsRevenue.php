<?php

namespace App\Tools\Insights;

use App\Models\User;
use App\Services\Insights\EffortVersusRevenue;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class HoursVsRevenue extends Tool
{
    public function name(): string
    {
        return 'hours_vs_revenue';
    }

    public function title(): string
    {
        return 'Hours spent vs money billed, per client';
    }

    public function group(): string
    {
        return 'Insights';
    }

    public function description(): string
    {
        return 'For a month (or a whole year), per client: hours the team logged, share of all hours, '
            .'amount invoiced, amount collected, and effective rupees per hour. Also the hours logged '
            .'against no client. USE WHEN asked "which clients are unprofitable", "where does our time '
            .'go", "what do we earn per hour on Riya". per_hour is null (not zero) when no hours were '
            .'logged. Invoiced figures can include costs re-billed at cost (ad spend), so a high rate is '
            .'not always margin -- say so if it matters. Read-only.';
    }

    public function permission(): ?string
    {
        return 'insights.view';
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'month' => ['type' => 'string', 'description' => 'YYYY-MM; defaults to the current month.'],
            'whole_year' => ['type' => 'boolean', 'description' => 'Optional. true = the whole calendar year of that month.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $month = (string) ($arguments['month'] ?? today()->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new ToolException('month must be YYYY-MM, e.g. 2026-09.');
        }

        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        [$from, $to] = ($arguments['whole_year'] ?? false) === true
            ? [$start->copy()->startOfYear(), $start->copy()->endOfYear()]
            : [$start, $start->copy()->endOfMonth()];

        $report = EffortVersusRevenue::between($from, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'clients' => collect($report['rows'])->map(fn ($r) => [
                'client' => $r['name'],
                'hours' => $r['hours'],
                'share_of_hours_percent' => $r['share'],
                'invoiced' => round((float) $r['invoiced'], 2),
                'collected' => round((float) $r['collected'], 2),
                'per_hour' => $r['per_hour'] ?? null,
            ])->values()->all(),
            'hours_on_no_client' => $report['unassigned'],
            'totals' => $report['totals'],
        ];
    }
}
