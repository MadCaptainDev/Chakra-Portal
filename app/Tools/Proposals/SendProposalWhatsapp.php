<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Services\ProposalWhatsappSender;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

class SendProposalWhatsapp extends Tool
{
    public function name(): string
    {
        return 'send_proposal_whatsapp';
    }

    public function description(): string
    {
        return 'Send the client their proposal link on WhatsApp from the studio number (creating the '
            .'link if needed). This messages a real person: only do it when the person has asked, to '
            .'the number they gave. Goes as a plain message if that number wrote to the studio in the '
            .'last day, otherwise as the approved proposal_ready template.';
    }

    public function permission(): ?string
    {
        return 'proposals.edit';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'proposal' => ['type' => 'string', 'description' => 'Its id, or words from its title.'],
            'phone' => ['type' => 'string', 'description' => 'The client\'s WhatsApp number, e.g. 9876543210 or +91 98765 43210.'],
        ], ['proposal', 'phone']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $phone = trim((string) ($arguments['phone'] ?? ''));

        if (! preg_match('/^[0-9+\-\s()]{10,20}$/', $phone)) {
            throw new ToolException('"'.$phone.'" does not look like a phone number.');
        }

        try {
            $route = app(ProposalWhatsappSender::class)->send($proposal, $phone, $user->id);
        } catch (RuntimeException $e) {
            throw new ToolException('WhatsApp did not send it: '.$e->getMessage());
        }

        return [
            'sent' => true,
            'to' => $phone,
            'as' => $route,
            'client_link' => $proposal->refresh()->publicUrl(),
        ];
    }
}
