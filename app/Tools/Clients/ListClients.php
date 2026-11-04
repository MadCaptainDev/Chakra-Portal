<?php

namespace App\Tools\Clients;

use App\Models\Client;
use App\Models\User;
use App\Tools\Tool;

/** Clients for pickers and the admin client list: id, name, logo, contact, active. */
class ListClients extends Tool
{
    public function name(): string
    {
        return 'list_clients';
    }

    public function title(): string
    {
        return 'List clients';
    }

    public function group(): string
    {
        return 'Clients';
    }

    public function description(): string
    {
        return 'The studio\'s clients as rows (id, name, logo, phone, email, type, active), A–Z. Active only by '
            .'default. Pass the id as "client" to any other action.';
    }

    public function permission(): ?string
    {
        return 'clients.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'search' => ['type' => 'string', 'description' => 'Optional. Part of the name.'],
            'include_inactive' => ['type' => 'boolean', 'description' => 'Default false.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $query = Client::query()->orderBy('name');
        if (! ($arguments['include_inactive'] ?? false)) {
            $query->active();
        }
        if (filled($arguments['search'] ?? null)) {
            $query->where('name', 'like', '%'.trim((string) $arguments['search']).'%');
        }

        return [
            'clients' => $query->limit(300)->get()->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'logo_url' => $c->logoUrl(),
                'phone' => $c->phone,
                'email' => $c->email,
                'type' => $c->client_type,
                'active' => (bool) $c->is_active,
            ])->values()->all(),
        ];
    }
}
