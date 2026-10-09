<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProposalController as StaffProposalController;
use App\Http\Controllers\PublicProposalController;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Proposal;
use App\Models\ProposalComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The proposals the studio has sent this client, read inside the portal --
 * the same document and comment threads as the p/{token} link, without
 * needing that link, and still there after it has been revoked.
 *
 * {proposal} is a plain int, not a route model binding: every query goes
 * through sentProposals(), so another client's proposal, or this client's
 * own draft, 404s before anything is loaded -- same reasoning as
 * Client\InvoiceController.
 */
class ProposalController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);

        $proposals = $this->sentProposals($client)
            ->withCount(['comments' => fn (Builder $q) => $q->whereNull('parent_id')])
            ->latest('updated_at')
            ->get();

        return view('client.proposals', [
            'client' => $client,
            'proposals' => $proposals,
        ]);
    }

    public function show(Request $request, int $proposal): View
    {
        $client = $this->client($request);

        $model = $this->sentProposals($client)->with(['client', 'createdBy'])->findOrFail($proposal);

        // The client reading it in the portal is the client reading it.
        $model->markViewed();

        $threads = $model->comments()
            ->topLevel()
            ->with('replies')
            ->oldest()
            ->get()
            ->groupBy(fn (ProposalComment $c) => $c->section_key ?? '');

        return view('client.proposals-show', [
            'client' => $client,
            'proposal' => $model,
            'threads' => $threads,
            'settings' => CompanySetting::current(),
        ]);
    }

    public function comment(Request $request, int $proposal): RedirectResponse
    {
        $client = $this->client($request);

        $model = $this->sentProposals($client)->with('createdBy')->findOrFail($proposal);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'section_key' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        // Signed in, so the name and email are the login's, not typed.
        $comment = PublicProposalController::storeComment($model, $validated + [
            'author_name' => $request->user()->name,
            'author_email' => $request->user()->email,
        ]);

        $anchor = $comment->section_key ? '#section-'.$comment->section_key : '#feedback';

        return redirect()->to(route('client.proposals.show', $model->id).$anchor)
            ->with('status', 'Thank you — your comment has been sent to the team.');
    }

    public function pdf(Request $request, int $proposal): Response
    {
        $model = $this->sentProposals($this->client($request))->findOrFail($proposal);

        return StaffProposalController::renderPdf($model)->stream(StaffProposalController::pdfFilename($model));
    }

    /**
     * This client's proposals, minus drafts: a draft is still being written,
     * and is "sent" only once the studio hands out its link.
     *
     * @return Builder<Proposal>
     */
    private function sentProposals(Client $client): Builder
    {
        return Proposal::query()
            ->where('client_id', $client->id)
            ->where('status', '!=', Proposal::STATUS_DRAFT);
    }
}
