<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\ProposalComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff answering a client's comments on a proposal: reply, resolve, reopen.
 * The client's own comments come in through PublicProposalController.
 */
class ProposalCommentController extends Controller
{
    public function store(Request $request, Proposal $proposal): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
            'section_key' => ['nullable', 'string', 'max:64'],
        ]);

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = $proposal->comments()->topLevel()->whereKey($validated['parent_id'])->firstOrFail();
        }

        $sectionKey = $parent?->section_key
            ?? (array_key_exists((string) ($validated['section_key'] ?? ''), $proposal->sectionLabels()) ? $validated['section_key'] : null);

        $proposal->comments()->create([
            'section_key' => $sectionKey,
            'parent_id' => $parent?->id,
            'user_id' => $request->user()->id,
            'author_name' => $request->user()->name,
            'author_email' => null,
            'body' => $validated['body'],
        ]);

        return back()->with('status', $parent ? 'Reply posted. The client sees it on their link.' : 'Comment posted.');
    }

    public function resolve(Request $request, Proposal $proposal, ProposalComment $comment): RedirectResponse
    {
        abort_unless($comment->proposal_id === $proposal->id, 404);

        $comment->forceFill([
            'resolved_at' => now(),
            'resolved_by_id' => $request->user()->id,
        ])->save();

        return back()->with('status', 'Marked as resolved.');
    }

    public function reopen(Proposal $proposal, ProposalComment $comment): RedirectResponse
    {
        abort_unless($comment->proposal_id === $proposal->id, 404);

        $comment->forceFill(['resolved_at' => null, 'resolved_by_id' => null])->save();

        return back()->with('status', 'Reopened.');
    }
}
