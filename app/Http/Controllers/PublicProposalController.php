<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Notifications\ProposalCommented;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A proposal read by a client who has no login, through p/{token}.
 *
 * Same rules as PublicBriefController: the token IS the authorisation, there
 * is no id in the path to tamper with, and an unknown or revoked token is a
 * plain 404 -- "wrong" and "closed" are the same answer to a stranger.
 */
class PublicProposalController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $proposal = $this->resolve($token);

        // Staff opening their own link to check it must not flip the proposal
        // to "viewed" -- that status means the client has seen it.
        if (! $request->user()?->can('proposals.view')) {
            $proposal->markViewed();
        }

        $threads = $proposal->comments()
            ->topLevel()
            ->with('replies')
            ->oldest()
            ->get()
            ->groupBy(fn (ProposalComment $c) => $c->section_key ?? '');

        return view('proposals.public', [
            'proposal' => $proposal,
            'threads' => $threads,
            'token' => $token,
            'settings' => CompanySetting::current(),
        ]);
    }

    public function comment(Request $request, string $token): RedirectResponse
    {
        $proposal = $this->resolve($token);

        // A bot filling every field it finds. Answered like a success so it
        // learns nothing, and nothing is stored.
        if (filled($request->input('website'))) {
            return redirect()->to(route('proposals.public', $token).'#feedback')
                ->with('status', 'Thank you — your comment has been sent to the team.');
        }

        $validated = $request->validate([
            'author_name' => ['required', 'string', 'max:120'],
            'author_email' => ['nullable', 'email', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'section_key' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $sectionKey = array_key_exists((string) ($validated['section_key'] ?? ''), $proposal->sectionLabels())
            ? $validated['section_key']
            : null;

        // A reply may only hang off a top-level comment on THIS proposal, and
        // lands in that comment's section whatever the form claimed.
        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = $proposal->comments()->topLevel()->whereKey($validated['parent_id'])->first();
            abort_if($parent === null, 404);
            $sectionKey = $parent->section_key;
        }

        $comment = $proposal->comments()->create([
            'section_key' => $sectionKey,
            'parent_id' => $parent?->id,
            'author_name' => $validated['author_name'],
            'author_email' => $validated['author_email'] ?? null,
            'body' => $validated['body'],
        ]);

        // A reply reopens a thread the studio had marked done -- the client
        // has said something new on it.
        $parent?->forceFill(['resolved_at' => null, 'resolved_by_id' => null])->save();

        if ($proposal->createdBy) {
            try {
                $proposal->createdBy->notify(new ProposalCommented($comment));
            } catch (Throwable $e) {
                Log::error('Proposal comment push failed.', ['comment_id' => $comment->id, 'error' => $e->getMessage()]);
            }
        }

        $anchor = $sectionKey ? '#section-'.$sectionKey : '#feedback';

        return redirect()->to(route('proposals.public', $token).$anchor)
            ->with('status', 'Thank you — your comment has been sent to the team.');
    }

    public function pdf(string $token): Response
    {
        $proposal = $this->resolve($token);

        return ProposalController::renderPdf($proposal)->stream(ProposalController::pdfFilename($proposal));
    }

    private function resolve(string $token): Proposal
    {
        $proposal = Proposal::with(['client', 'createdBy'])->where('public_token', $token)->first();

        abort_if($proposal === null, 404);

        return $proposal;
    }
}
