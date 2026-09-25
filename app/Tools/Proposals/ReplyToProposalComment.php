<?php

namespace App\Tools\Proposals;

use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

class ReplyToProposalComment extends Tool
{
    public function name(): string
    {
        return 'reply_to_proposal_comment';
    }

    public function description(): string
    {
        return 'Post a comment on a proposal as the caller. With comment_id it is a reply in that '
            .'thread, which the CLIENT SEES on their link -- write it to them. Without, it starts a new '
            .'thread (on a section key, or general). resolve: true also marks the thread resolved. '
            .'Only post what the person has approved.';
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
            'body' => ['type' => 'string', 'description' => 'The text, up to 5000 characters.'],
            'comment_id' => ['type' => 'integer', 'description' => 'The thread to reply in (from list_proposal_comments).'],
            'section_key' => ['type' => 'string', 'description' => 'For a new thread: the section it is about.'],
            'resolve' => ['type' => 'boolean', 'description' => 'Mark the thread resolved as well.'],
        ], ['proposal', 'body']);
    }

    public function handle(array $arguments, User $user): array
    {
        $proposal = ProposalToolSupport::find($arguments['proposal'] ?? null);
        $body = trim((string) ($arguments['body'] ?? ''));

        if ($body === '' || mb_strlen($body) > 5000) {
            throw new ToolException('The comment must be between 1 and 5000 characters.');
        }

        $parent = null;
        if (isset($arguments['comment_id'])) {
            $parent = $proposal->comments()->topLevel()->whereKey((int) $arguments['comment_id'])->first()
                ?? throw new ToolException('Proposal #'.$proposal->id.' has no comment thread #'.$arguments['comment_id'].'.');
        }

        $sectionKey = $parent?->section_key;
        if (! $parent && isset($arguments['section_key'])) {
            if (! array_key_exists($arguments['section_key'], $proposal->sectionLabels())) {
                throw new ToolException('There is no section "'.$arguments['section_key'].'". The keys are: '
                    .implode(', ', array_keys($proposal->sectionLabels())).'.');
            }
            $sectionKey = $arguments['section_key'];
        }

        $comment = $proposal->comments()->create([
            'section_key' => $sectionKey,
            'parent_id' => $parent?->id,
            'user_id' => $user->id,
            'author_name' => $user->name,
            'author_email' => null,
            'body' => $body,
        ]);

        $thread = $parent ?? $comment;
        if (! empty($arguments['resolve'])) {
            $thread->forceFill(['resolved_at' => now(), 'resolved_by_id' => $user->id])->save();
        }

        return [
            'posted' => true,
            'comment_id' => $comment->id,
            'thread_id' => $thread->id,
            'resolved' => $thread->fresh()->isResolved(),
            'visible_to_client' => $proposal->public_token !== null,
        ];
    }
}
