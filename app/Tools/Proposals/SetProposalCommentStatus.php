<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

class SetProposalCommentStatus extends Tool
{
    public function name(): string
    {
        return 'set_proposal_comment_status';
    }

    public function description(): string
    {
        return 'Mark a proposal comment thread resolved (the feedback has been dealt with) or reopen it.';
    }

    public function permission(): ?string
    {
        return 'proposals.comment';
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
            'comment_id' => ['type' => 'integer'],
            'resolved' => ['type' => 'boolean'],
        ], ['proposal', 'comment_id', 'resolved']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $comment = $proposal->comments()->topLevel()->whereKey((int) ($arguments['comment_id'] ?? 0))->first()
            ?? throw new ToolException('Proposal #'.$proposal->id.' has no comment thread #'.($arguments['comment_id'] ?? '?').'.');

        $resolved = (bool) ($arguments['resolved'] ?? true);
        $comment->forceFill($resolved
            ? ['resolved_at' => now(), 'resolved_by_id' => $user->id]
            : ['resolved_at' => null, 'resolved_by_id' => null])->save();

        return ['comment_id' => $comment->id, 'resolved' => $resolved];
    }
}
