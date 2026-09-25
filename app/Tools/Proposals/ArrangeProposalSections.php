<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

class ArrangeProposalSections extends Tool
{
    public function name(): string
    {
        return 'arrange_proposal_sections';
    }

    public function description(): string
    {
        return 'Reorder or remove sections of a proposal. "remove" lists keys to delete (client comments '
            .'on them are kept as general feedback). "order" lists every remaining key in the new order; '
            .'the cover always stays first. Renumber the headings afterwards with write_proposal_sections '
            .'if the order changed.';
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
            'remove' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Keys of sections to delete.'],
            'order' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Every remaining key, in the order wanted.'],
        ], ['proposal']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $sections = $proposal->normalizedSections();
        $keys = array_column($sections, 'key');

        $remove = array_values(array_filter((array) ($arguments['remove'] ?? []), 'is_string'));
        $unknown = array_diff($remove, $keys);
        if ($unknown !== []) {
            throw new ToolException('No section with key '.implode(', ', $unknown).'. The keys are: '.implode(', ', $keys).'.');
        }

        $sections = array_values(array_filter($sections, fn ($s) => ! in_array($s['key'], $remove, true)));

        if (isset($arguments['order'])) {
            $order = array_values(array_filter((array) $arguments['order'], 'is_string'));
            $remaining = array_column($sections, 'key');
            $cover = array_values(array_filter($sections, fn ($s) => $s['type'] === 'cover'));
            $order = array_values(array_diff($order, array_column($cover, 'key')));
            $needed = array_values(array_diff($remaining, array_column($cover, 'key')));

            if (count($order) !== count($needed) || array_diff($needed, $order) !== [] || count(array_unique($order)) !== count($order)) {
                throw new ToolException('"order" must list every remaining section key exactly once: '.implode(', ', $needed).'.');
            }

            $byKey = array_column($sections, null, 'key');
            $sections = [...$cover, ...array_map(fn ($k) => $byKey[$k], $order)];
        }

        $proposal->sections = $sections;
        $proposal->save();

        return ['saved' => true, 'removed' => $remove, 'outline' => ProposalToolSupport::outline($proposal)];
    }
}
