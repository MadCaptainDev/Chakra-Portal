<?php

namespace App\Tools\Finance;

use App\Models\ClientAdvance;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;

class ClientAdvancesOwed extends Tool
{
    public function name(): string
    {
        return 'client_advances_owed';
    }

    public function title(): string
    {
        return 'Who owes the studio for costs it paid';
    }

    public function group(): string
    {
        return 'Finance';
    }

    public function description(): string
    {
        return 'What clients still owe the studio for costs it paid on their behalf (ad spend, '
            .'subscriptions, model fees) and has not yet recovered. Totals per client, worst first; pass a '
            .'client to see their individual unrecovered items. USE WHEN asked "who owes me what", "how '
            .'much am I out of pocket for Riya". This is NOT unpaid invoices -- use money_due for those. Read-only.';
    }

    public function permission(): ?string
    {
        return 'client-advances.view';
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Optional. Client id or portal name, to list their items.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $query = ClientAdvance::outstanding()->with(['client', 'categoryTerm'])->orderBy('spent_on');

        if (filled($arguments['client'] ?? null)) {
            $client = ClientResolver::resolve($arguments['client']);
            $items = $query->where('client_id', $client->id)->get();

            return [
                'client' => $client->name,
                'owed_total' => round($items->sum(fn ($a) => $a->billable()), 2),
                'items' => $items->map(fn (ClientAdvance $a) => [
                    'advance_id' => $a->id,
                    'name' => $a->name,
                    'category' => $a->categoryLabel(),
                    'spent_on' => $a->spent_on?->toDateString(),
                    'paid' => (float) $a->amount,
                    'to_charge_client' => $a->billable(),
                    'payee' => $a->payee,
                ])->all(),
            ];
        }

        $byClient = $query->get()->groupBy('client_id')->map(fn ($rows) => [
            'client' => $rows->first()->client?->name,
            'client_id' => $rows->first()->client_id,
            'items' => $rows->count(),
            'owed' => round($rows->sum(fn ($a) => $a->billable()), 2),
            'oldest' => $rows->min('spent_on')?->toDateString(),
        ])->sortByDesc('owed')->values();

        return [
            'owed_total' => round($byClient->sum('owed'), 2),
            'clients' => $byClient->all(),
        ];
    }
}
