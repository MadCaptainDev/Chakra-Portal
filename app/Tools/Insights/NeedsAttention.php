<?php

namespace App\Tools\Insights;

use App\Models\User;
use App\Services\Insights\NeedsAttention as NeedsAttentionReport;
use App\Tools\Tool;

class NeedsAttention extends Tool
{
    public function name(): string
    {
        return 'needs_attention';
    }

    public function title(): string
    {
        return 'What needs attention right now';
    }

    public function group(): string
    {
        return 'Insights';
    }

    public function description(): string
    {
        return 'The studio\'s current problems: shoots in the next 72 hours with nobody crewed, invoices '
            .'30+ days overdue, and clients who owe money but have gone 60+ days without paying. Each with '
            .'a severity and a link to fix it in the portal. An empty list means nothing is wrong. '
            .'USE WHEN asked "what needs my attention", "anything urgent". Read-only.';
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
        $items = NeedsAttentionReport::all()->values();

        return [
            'count' => $items->count(),
            'items' => $items->all(),
        ];
    }
}
