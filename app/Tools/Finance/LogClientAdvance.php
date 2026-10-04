<?php

namespace App\Tools\Finance;

use App\Models\ClientAdvance;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class LogClientAdvance extends Tool
{
    public function name(): string
    {
        return 'log_client_advance';
    }

    public function title(): string
    {
        return 'Log money paid on a client\'s behalf';
    }

    public function group(): string
    {
        return 'Finance';
    }

    public function description(): string
    {
        return 'Record a cost the studio paid FOR a client and expects back -- Meta/Google ad spend, an '
            .'API or software subscription, a model\'s fee, printing. Example: "I paid ₹5,000 Meta Ads for '
            .'Riya today". It then shows as owed by that client until marked recovered in the portal. '
            .'DO NOT use for the studio\'s own costs (rent, salaries, equipment) -- those are Expenses. '
            .'amount is what was actually paid; billable_amount only if the client is charged a different '
            .'figure (e.g. with a margin) -- leave it out to bill at cost. Never guess an amount or date the '
            .'person did not give; ask. Returns what was logged so it can be read back.';
    }

    public function permission(): ?string
    {
        return 'client-advances.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Client id or portal name.'],
            'name' => ['type' => 'string', 'description' => 'What it was for, e.g. "October Meta Ads top-up".'],
            'amount' => ['type' => 'number', 'description' => 'Rupees actually paid, e.g. 5000.'],
            'billable_amount' => ['type' => 'number', 'description' => 'Optional. Rupees to charge the client, only if different from amount.'],
            'category' => ['type' => 'string', 'description' => 'Optional. One of the Client cost categories, e.g. "Meta Ads", "Google Ads", "API & Software", "Model / Talent", "Printing", "Other".'],
            'payee' => ['type' => 'string', 'description' => 'Optional. Who was paid, e.g. "Meta".'],
            'spent_on' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD; defaults to today.'],
            'notes' => ['type' => 'string', 'description' => 'Optional.'],
        ], ['client', 'name', 'amount']);
    }

    public function handle(array $arguments, User $user): array
    {
        $client = ClientResolver::resolve($arguments['client'] ?? null);

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new ToolException('Say what the payment was for.');
        }

        $amount = $arguments['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new ToolException('amount must be a positive number of rupees.');
        }

        $billable = $arguments['billable_amount'] ?? null;
        if ($billable !== null && (! is_numeric($billable) || (float) $billable < 0)) {
            throw new ToolException('billable_amount must be a number of rupees, or left out to bill at cost.');
        }

        $spentOn = $arguments['spent_on'] ?? today()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $spentOn)) {
            throw new ToolException('spent_on must be YYYY-MM-DD.');
        }
        if (Carbon::parse($spentOn)->isAfter(today())) {
            throw new ToolException('spent_on is in the future — an advance is money already paid.');
        }

        $categoryId = null;
        if (filled($arguments['category'] ?? null)) {
            $term = TaxonomyTerm::query()
                ->where('type', TaxonomyTerm::TYPE_CLIENT_COST)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($arguments['category']))])
                ->first();

            if (! $term) {
                $known = TaxonomyTerm::query()->where('type', TaxonomyTerm::TYPE_CLIENT_COST)->orderBy('sort_order')->pluck('name')->implode(', ');
                throw new ToolException("There is no category \"{$arguments['category']}\". The categories are: {$known}.");
            }
            $categoryId = $term->id;
        }

        $advance = ClientAdvance::create([
            'client_id' => $client->id,
            'category_id' => $categoryId,
            'name' => $name,
            'payee' => $arguments['payee'] ?? null,
            'amount' => round((float) $amount, 2),
            'billable_amount' => $billable === null ? null : round((float) $billable, 2),
            'spent_on' => $spentOn,
            'notes' => $arguments['notes'] ?? null,
            'created_by_id' => $user->id,
        ]);

        return [
            'logged' => true,
            'advance_id' => $advance->id,
            'client' => $client->name,
            'name' => $advance->name,
            'paid' => (float) $advance->amount,
            'to_charge_client' => $advance->billable(),
            'spent_on' => $spentOn,
            'category' => $advance->categoryLabel(),
            'client_now_owes_in_total' => round(ClientAdvance::outstanding()->where('client_id', $client->id)->get()->sum(fn ($a) => $a->billable()), 2),
        ];
    }
}
