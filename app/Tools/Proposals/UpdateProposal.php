<?php

namespace App\Tools\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

class UpdateProposal extends Tool
{
    public function name(): string
    {
        return 'update_proposal';
    }

    public function description(): string
    {
        return 'Change a proposal\'s details: its internal title, the linked client, the valid-until '
            .'date, or its status (draft, sent, viewed, accepted, declined -- e.g. mark it accepted '
            .'when the client says yes). Only what is passed changes. For the document itself use '
            .'write_proposal_sections.';
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
            'title' => ['type' => 'string'],
            'client' => ['type' => 'string', 'description' => 'Client id or name; empty string or null to unlink.'],
            'valid_until' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD; empty to clear.'],
            'status' => ['type' => 'string', 'enum' => array_keys(Proposal::STATUSES)],
        ], ['proposal']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);

        if (array_key_exists('title', $arguments)) {
            $title = trim((string) $arguments['title']);
            if ($title === '') {
                throw new ToolException('The title cannot be empty.');
            }
            $proposal->title = $title;
        }

        if (array_key_exists('client', $arguments)) {
            $proposal->client_id = ProposalToolSupport::client($arguments['client'])?->id;
        }

        if (array_key_exists('valid_until', $arguments)) {
            $proposal->valid_until = ProposalToolSupport::date($arguments['valid_until']);
        }

        if (array_key_exists('status', $arguments)) {
            if (! array_key_exists((string) $arguments['status'], Proposal::STATUSES)) {
                throw new ToolException('Status must be one of: '.implode(', ', array_keys(Proposal::STATUSES)).'.');
            }
            $proposal->status = $arguments['status'];
        }

        $proposal->save();

        return ProposalToolSupport::summary($proposal->refresh());
    }
}
