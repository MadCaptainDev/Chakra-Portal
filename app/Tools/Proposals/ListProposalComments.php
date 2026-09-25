<?php

namespace App\Tools\Proposals;

use App\Models\ProposalComment;
use App\Models\User;
use App\Tools\Tool;

class ListProposalComments extends Tool
{
    public function name(): string
    {
        return 'list_proposal_comments';
    }

    public function description(): string
    {
        return 'The comments on a proposal, each thread with its section, who wrote it (the client or '
            .'staff), when, the replies and whether it is resolved. Open threads only unless '
            .'include_resolved. Use this to work through client feedback.';
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
            'include_resolved' => ['type' => 'boolean'],
        ], ['proposal']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $labels = $proposal->sectionLabels();

        $threads = $proposal->comments()
            ->topLevel()
            ->with(['replies.user', 'user', 'resolvedBy'])
            ->when(empty($arguments['include_resolved']), fn ($q) => $q->whereNull('resolved_at'))
            ->oldest()
            ->get();

        $line = fn (ProposalComment $c) => [
            'id' => $c->id,
            'by' => $c->isFromStaff() ? ($c->user?->name ?? $c->author_name).' (staff)' : $c->author_name.' (client)',
            'at' => $c->created_at?->toDateTimeString(),
            'body' => $c->body,
        ];

        return [
            'proposal' => ['id' => $proposal->id, 'title' => $proposal->title],
            'threads' => $threads->map(fn (ProposalComment $c) => $line($c) + [
                'section_key' => $c->section_key,
                'section' => $c->section_key !== null && isset($labels[$c->section_key]) ? $labels[$c->section_key] : 'General feedback',
                'resolved' => $c->isResolved(),
                'replies' => $c->replies->map($line)->values()->all(),
            ])->values()->all(),
        ];
    }
}
