<?php

namespace App\Tools\Clients;

use App\Models\User;
use App\Services\ClientBriefNudge;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

class SendBriefReminder extends Tool
{
    public function name(): string
    {
        return 'send_brief_reminder';
    }

    public function title(): string
    {
        return 'Remind a client to fill in their brand brief';
    }

    public function group(): string
    {
        return 'Clients';
    }

    public function description(): string
    {
        return 'Send a client a WhatsApp from the studio number asking them to fill in their brand brief, '
            .'with their personal link. THIS MESSAGES A REAL CLIENT: only call it when the person '
            .'explicitly asks to remind this client. Refuses if the brief is already submitted or the '
            .'client has no phone. Check get_client_brief first.';
    }

    public function permission(): ?string
    {
        return 'clients.edit';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function messagesClient(): bool
    {
        return true;
    }

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

        try {
            app(ClientBriefNudge::class)->send($client, $user);
        } catch (RuntimeException $e) {
            throw new ToolException('Not sent: '.$e->getMessage());
        }

        return [
            'sent' => true,
            'client' => $client->name,
            'to' => $client->phone,
            'note' => '"sent" means WhatsApp accepted it; delivery is confirmed separately.',
        ];
    }
}
