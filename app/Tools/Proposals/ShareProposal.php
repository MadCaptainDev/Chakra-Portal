<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Tools\Tool;

class ShareProposal extends Tool
{
    public function name(): string
    {
        return 'share_proposal';
    }

    public function description(): string
    {
        return 'The client\'s no-login link to a proposal, where they read it, comment and download '
            .'the PDF. "link" returns the current one, creating it if there is none (a draft then '
            .'becomes "sent"). "new_link" replaces it -- the old one stops working. "close" turns the '
            .'link off. Only when the person asks: anyone with the link can read the proposal.';
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
            'action' => ['type' => 'string', 'enum' => ['link', 'new_link', 'close'], 'description' => 'Default "link".'],
        ], ['proposal']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);

        match ($arguments['action'] ?? 'link') {
            'new_link' => $proposal->issuePublicToken(),
            'close' => $proposal->revokePublicToken(),
            default => $proposal->public_token === null ? $proposal->issuePublicToken() : null,
        };

        $proposal->refresh();

        return [
            'client_link' => $proposal->publicUrl(),
            'status' => $proposal->status,
            'first_viewed_by_client_at' => $proposal->first_viewed_at?->toDateTimeString(),
        ];
    }
}
