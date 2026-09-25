<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

class GetProposal extends Tool
{
    public function name(): string
    {
        return 'get_proposal';
    }

    public function description(): string
    {
        return 'One proposal: its details, links and an outline (every section\'s key, page, heading '
            .'and block types). Pass sections: ["key", …] for those sections in full, or sections: '
            .'["*"] for the whole document. Read a section in full before rewriting it.';
    }

    public function permission(): ?string
    {
        return 'proposals.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'proposal' => ['type' => 'string', 'description' => 'Its id, or words from its title.'],
            'sections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Section keys to return in full; ["*"] for all.'],
        ], ['proposal']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $wanted = array_values(array_filter((array) ($arguments['sections'] ?? []), 'is_string'));

        $result = ProposalToolSupport::summary($proposal) + ['outline' => ProposalToolSupport::outline($proposal)];

        if ($wanted !== []) {
            $all = $proposal->normalizedSections();
            $full = in_array('*', $wanted, true)
                ? $all
                : array_values(array_filter($all, fn ($s) => in_array($s['key'], $wanted, true)));

            $missing = array_diff($wanted, ['*'], array_column($all, 'key'));
            if ($missing !== []) {
                throw new ToolException('No section with key '.implode(', ', $missing).'. The keys are: '
                    .implode(', ', array_column($all, 'key')).'.');
            }

            $result['sections'] = $full;
        }

        return $result;
    }
}
