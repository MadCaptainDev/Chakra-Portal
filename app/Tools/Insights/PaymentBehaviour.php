<?php

namespace App\Tools\Insights;

use App\Models\User;
use App\Services\Insights\PaymentBehaviour as PaymentBehaviourReport;
use App\Tools\Tool;

class PaymentBehaviour extends Tool
{
    public function name(): string
    {
        return 'payment_behaviour';
    }

    public function title(): string
    {
        return 'Who pays late';
    }

    public function group(): string
    {
        return 'Insights';
    }

    public function description(): string
    {
        return 'Per client, how many days after the due date they usually pay (negative = early), their '
            .'worst delay, how much they have paid and when last. Worst payers first. "confident" is false '
            .'when there are too few payments to judge -- do not label a client a late payer on one '
            .'payment. USE WHEN asked "who pays late", "should we ask for advance from X". Read-only.';
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
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        return [
            'clients' => PaymentBehaviourReport::all()->map(fn ($r) => [
                'client' => $r['name'],
                'payments' => $r['payments'],
                'paid_total' => round((float) $r['paid'], 2),
                'average_days_late' => $r['average_days'],
                'worst_days_late' => $r['worst_days'],
                'last_paid_on' => $r['last_paid_on'] ? \Illuminate\Support\Carbon::parse($r['last_paid_on'])->toDateString() : null,
                'confident' => $r['confident'],
            ])->values()->all(),
        ];
    }
}
