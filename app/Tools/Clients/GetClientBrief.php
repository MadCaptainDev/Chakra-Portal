<?php

namespace App\Tools\Clients;

use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;

class GetClientBrief extends Tool
{
    public function name(): string
    {
        return 'get_client_brief';
    }

    public function title(): string
    {
        return 'Read a client\'s brand brief';
    }

    public function group(): string
    {
        return 'Clients';
    }

    public function description(): string
    {
        return 'A client\'s brand brief -- their own answers about their business, audience, tone, '
            .'offers and do\'s/don\'ts -- plus its status (not started / in progress / submitted) and how '
            .'many required questions are still unanswered. USE BEFORE writing scripts, captions, a '
            .'proposal or ad copy for a client, so the work matches what they told us. Quote it; do not '
            .'invent answers the brief does not contain. Read-only.';
    }

    public function permission(): ?string
    {
        return 'clients.view';
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
        ], ['client']);
    }

    public function handle(array $arguments, User $user): array
    {
        $client = ClientResolver::resolve($arguments['client'] ?? null);
        $brief = $client->brief;

        if (! $brief) {
            return [
                'client' => $client->name,
                'status' => 'Not started',
                'brief' => null,
                'hint' => 'No brief yet. send_brief_reminder can ask the client to fill it in, if the person wants that.',
            ];
        }

        return [
            'client' => $client->name,
            'status' => $brief->statusLabel(),
            'submitted_at' => $brief->submitted_at?->format('Y-m-d'),
            'required_answered' => $brief->requiredAnswered(),
            'required_total' => $brief->requiredTotal(),
            'brief' => $brief->toText(),
        ];
    }
}
