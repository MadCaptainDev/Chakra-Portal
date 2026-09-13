<?php

namespace App\Services\AdminAgent\Tools;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AdminAgent\Tool;

/**
 * Who a name refers to, and where they stand.
 *
 * The tool the model reaches for first when the admin says a name, because a
 * name on WhatsApp is never an id: "how much does Saravana owe" has to become
 * a client row before any figure means anything. Returning several matches
 * with their ids is on purpose -- the model can then ask which one rather
 * than quietly answering about the wrong Saravana.
 */
class FindClientTool implements Tool
{
    private const LIMIT = 6;

    public function name(): string
    {
        return 'find_client';
    }

    public function definition(): array
    {
        return [
            'name' => 'find_client',
            'description' => 'Look up clients by name (partial is fine). Returns each match with its id, contact details, whether the account is active, and how much it currently owes. Use this to resolve a name before asking about their invoices.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'All or part of the client name, as the admin said it.',
                    ],
                ],
                'required' => ['name'],
            ],
        ];
    }

    public function run(User $admin, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return 'No name was given to search for.';
        }

        $clients = Client::query()
            ->where('name', 'like', '%'.$name.'%')
            ->orderBy('name')
            ->limit(self::LIMIT + 1)
            ->get();

        if ($clients->isEmpty()) {
            return 'No client matches "'.$name.'".';
        }

        $lines = [];

        foreach ($clients->take(self::LIMIT) as $client) {
            $owed = Invoice::unpaid()
                ->where('client_id', $client->id)
                ->with('payments')
                ->get()
                ->sum(fn (Invoice $invoice) => $invoice->balanceDue());

            $lines[] = implode(' | ', array_filter([
                'id '.$client->id,
                $client->name,
                $client->is_active ? 'active' : 'inactive',
                $client->phone ? 'phone '.$client->phone : null,
                'owes '.number_format($owed, 0),
            ]));
        }

        if ($clients->count() > self::LIMIT) {
            $lines[] = 'More than '.self::LIMIT.' matches — ask for a narrower name.';
        }

        return implode("\n", $lines);
    }
}
