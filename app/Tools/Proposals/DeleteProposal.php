<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Support\PublicUpload;
use App\Tools\Tool;
use App\Tools\ToolException;

class DeleteProposal extends Tool
{
    public function name(): string
    {
        return 'delete_proposal';
    }

    public function description(): string
    {
        return 'Permanently delete a proposal, its client link and all its comments. Cannot be undone. '
            .'Only when the person has clearly asked for this proposal to be deleted; pass its exact '
            .'title in confirm_title.';
    }

    public function permission(): ?string
    {
        return 'proposals.delete';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isDestructive(): bool
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
            'proposal' => ['type' => 'string', 'description' => 'Its id.'],
            'confirm_title' => ['type' => 'string', 'description' => 'The proposal\'s exact title.'],
        ], ['proposal', 'confirm_title']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);

        if (trim((string) ($arguments['confirm_title'] ?? '')) !== $proposal->title) {
            throw new ToolException('confirm_title does not match. The title is exactly: '.$proposal->title);
        }

        $logo = $proposal->cover()['client_logo'] ?? null;
        $id = $proposal->id;
        $title = $proposal->title;
        $proposal->delete();

        // Same rule as the web delete: an uploaded logo goes only when no
        // other proposal (a duplicate) still uses it.
        if ($logo !== null && str_starts_with($logo, 'uploads/')
            && ! \App\Models\Proposal::query()->get(['id', 'sections'])->contains(fn ($p) => ($p->cover()['client_logo'] ?? null) === $logo)) {
            PublicUpload::delete($logo);
        }

        return ['deleted' => true, 'id' => $id, 'title' => $title];
    }
}
