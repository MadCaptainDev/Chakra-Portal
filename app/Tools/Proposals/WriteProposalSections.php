<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

class WriteProposalSections extends Tool
{
    public function name(): string
    {
        return 'write_proposal_sections';
    }

    public function description(): string
    {
        return 'Write sections of a proposal. Each section passed replaces the one with the same key, '
            .'or is added if the key is new (after the section named in "after", else at the end). '
            .'replace_all: true makes the sections passed the whole document instead. Shapes are in '
            .'proposal_guide. Returns the new outline and "problems" -- anything dropped, cut short or '
            .'defaulted; fix those and write again.';
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
            'sections' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Sections in the shape proposal_guide describes, each with its key.'],
            'after' => ['type' => 'string', 'description' => 'Key of the section new ones go after. "start" for the very beginning (after the cover).'],
            'replace_all' => ['type' => 'boolean', 'description' => 'Replace the whole document with these sections.'],
        ], ['proposal', 'sections']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $input = array_values((array) ($arguments['sections'] ?? []));

        if ($input === []) {
            throw new ToolException('Pass at least one section.');
        }

        $current = $proposal->normalizedSections();
        $byKey = array_column($current, null, 'key');

        ['sections' => $written, 'problems' => $problems] = ProposalToolSupport::prepare($input, $byKey);

        if (count(array_filter($written, fn ($s) => $s['type'] === 'cover')) > 1) {
            throw new ToolException('A proposal has one cover.');
        }

        if (! empty($arguments['replace_all'])) {
            $sections = $written;
        } else {
            $sections = $current;
            $new = [];

            foreach ($written as $section) {
                $at = array_search($section['key'], array_column($sections, 'key'), true);
                if ($at !== false) {
                    $sections[$at] = $section;
                } elseif ($section['type'] === 'cover') {
                    // One cover, always first: a "new" cover replaces the old.
                    $sections = array_values(array_filter($sections, fn ($s) => $s['type'] !== 'cover'));
                    array_unshift($sections, $section);
                } else {
                    $new[] = $section;
                }
            }

            if ($new !== []) {
                $after = $arguments['after'] ?? null;
                $keys = array_column($sections, 'key');

                if ($after === 'start') {
                    $position = ($sections[0]['type'] ?? null) === 'cover' ? 1 : 0;
                } elseif (is_string($after) && $after !== '') {
                    $index = array_search($after, $keys, true);
                    if ($index === false) {
                        throw new ToolException('There is no section "'.$after.'" to add after. The keys are: '.implode(', ', $keys).'.');
                    }
                    $position = $index + 1;
                } else {
                    $position = count($sections);
                }

                array_splice($sections, $position, 0, $new);
            }
        }

        $cover = array_values(array_filter($sections, fn ($s) => $s['type'] === 'cover'));
        $sections = [...$cover, ...array_values(array_filter($sections, fn ($s) => $s['type'] !== 'cover'))];

        $proposal->sections = $sections;
        $proposal->save();

        return [
            'saved' => true,
            'written' => array_column($written, 'key'),
            'problems' => $problems,
            'outline' => ProposalToolSupport::outline($proposal),
        ] + ProposalToolSupport::urls($proposal);
    }
}
