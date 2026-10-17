<?php

namespace App\Tools\Studio;

use App\Models\User;
use App\Models\WhatsappConversation;
use App\Tools\Tool;

class WhatsappInbox extends Tool
{
    public function name(): string
    {
        return 'whatsapp_inbox';
    }

    public function title(): string
    {
        return 'WhatsApp inbox';
    }

    public function group(): string
    {
        return 'WhatsApp';
    }

    public function description(): string
    {
        return 'The studio WhatsApp number\'s conversations, newest first: who, unread count, the last message, when, status and '
            .'who it is assigned to. USE WHEN asked "any WhatsApp messages waiting", "who messaged us". Read-only; replying '
            .'stays in the portal\'s WhatsApp inbox.';
    }

    public function permission(): ?string
    {
        return 'whatsapp-crm.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'only_unread' => ['type' => 'boolean', 'description' => 'Only conversations with unread messages. Defaults to true.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $rows = WhatsappConversation::query()->with(['contact', 'assignedTo'])
            ->when($arguments['only_unread'] ?? true, fn ($q) => $q->where('unread_count', '>', 0))
            ->orderByDesc('last_message_at')->limit(40)->get();

        return [
            'count' => $rows->count(),
            'conversations' => $rows->map(fn (WhatsappConversation $c) => [
                'who' => $c->contact?->name ?: $c->wa_id, 'number' => $c->wa_id, 'unread' => $c->unread_count,
                'last_message' => $c->last_message_summary, 'at' => $c->last_message_at?->format('Y-m-d H:i'),
                'status' => $c->status, 'assigned_to' => $c->assignedTo?->name,
            ])->all(),
        ];
    }
}
