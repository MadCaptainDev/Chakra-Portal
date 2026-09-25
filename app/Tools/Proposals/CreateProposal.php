<?php

namespace App\Tools\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Support\ProposalBlocks;
use App\Tools\Tool;
use App\Tools\ToolException;

class CreateProposal extends Tool
{
    public function name(): string
    {
        return 'create_proposal';
    }

    public function description(): string
    {
        return 'Start a new proposal, as a draft nobody outside the studio can see. Either pass the '
            .'whole document in sections (see proposal_guide for the shapes), or duplicate_of an '
            .'existing proposal to copy its sections and then rewrite them. Returns the id, links and '
            .'any problems with what was written.';
    }

    public function permission(): ?string
    {
        return 'proposals.create';
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
            'title' => ['type' => 'string', 'description' => 'Internal name, e.g. "Acme — Online store". The client sees the cover, not this.'],
            'client' => ['type' => 'string', 'description' => 'The client, by id or name. Leave out for a prospect who is not a client yet.'],
            'valid_until' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD, optional.'],
            'sections' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'The document: a cover, then sections. See proposal_guide.'],
            'duplicate_of' => ['type' => 'string', 'description' => 'Copy the sections of this proposal (id or title) instead of passing sections.'],
        ], ['title']);
    }

    public function handle(array $arguments, User $user): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '') {
            throw new ToolException('Give the proposal a title.');
        }

        $client = ProposalToolSupport::client($arguments['client'] ?? null);
        $problems = [];

        if (isset($arguments['duplicate_of'])) {
            if (! empty($arguments['sections'])) {
                throw new ToolException('Pass either sections or duplicate_of, not both.');
            }
            $sections = ProposalToolSupport::find($arguments['duplicate_of'])->normalizedSections();
        } elseif (! empty($arguments['sections'])) {
            ['sections' => $sections, 'problems' => $problems] = ProposalToolSupport::prepare((array) $arguments['sections']);
        } else {
            $sections = ProposalBlocks::normalizeSections([
                ['key' => 'cover', 'type' => 'cover', 'data' => [
                    'title' => $client?->name ?? '',
                    'client_name' => $client?->name ?? '',
                    'date_label' => now()->format('F Y'),
                ]],
            ]);
        }

        $proposal = Proposal::create([
            'title' => $title,
            'client_id' => $client?->id,
            'valid_until' => ProposalToolSupport::date($arguments['valid_until'] ?? null),
            'status' => Proposal::STATUS_DRAFT,
            'created_by_id' => $user->id,
            'sections' => $sections,
        ]);

        return ProposalToolSupport::summary($proposal) + [
            'outline' => ProposalToolSupport::outline($proposal),
            'problems' => $problems,
        ];
    }
}
