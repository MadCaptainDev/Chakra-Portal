<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProposalRequest;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Proposal;
use App\Support\ProposalBlocks;
use App\Support\PublicUpload;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proposals: the designed document a prospect reads, the section editor that
 * writes it, and the share link that lets the client comment without a login.
 * The client's side is PublicProposalController; replies and resolving are
 * ProposalCommentController.
 */
class ProposalController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $proposals = Proposal::query()
            ->with('client')
            ->withCount(['comments as open_comments_count' => fn ($q) => $q->whereNull('resolved_at')->whereNull('user_id')])
            ->when($search, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(array_key_exists($status, Proposal::STATUSES), fn ($query) => $query->where('status', $status))
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('proposals.index', compact('proposals', 'search', 'status'));
    }

    public function create(): View
    {
        $proposal = new Proposal([
            'status' => Proposal::STATUS_DRAFT,
            'sections' => [
                ['key' => 'cover', 'type' => 'cover', 'data' => ['date_label' => now()->format('F Y')]],
                ['key' => ProposalBlocks::newKey(), 'type' => 'section', 'data' => [
                    'number' => '01',
                    'title' => 'Executive Summary',
                    'new_page' => true,
                    'blocks' => [['type' => 'paragraph', 'text' => '']],
                ]],
            ],
        ]);

        return view('proposals.create', [
            'proposal' => $proposal,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProposalRequest $request): RedirectResponse
    {
        $proposal = new Proposal([
            'title' => $request->validated('title'),
            'client_id' => $request->validated('client_id'),
            'valid_until' => $request->validated('valid_until'),
            'status' => Proposal::STATUS_DRAFT,
            'created_by_id' => $request->user()->id,
        ]);
        $proposal->sections = $this->sectionsWithLogo($request, ProposalBlocks::fromForm($request->sectionsForm()));
        $proposal->save();

        return redirect()->route('proposals.show', $proposal)->with('status', 'Proposal created.');
    }

    public function show(Proposal $proposal): View
    {
        $proposal->load('client', 'createdBy');

        $comments = $proposal->comments()
            ->topLevel()
            ->with(['replies.user', 'user', 'resolvedBy'])
            ->latest()
            ->get();

        return view('proposals.show', [
            'proposal' => $proposal,
            'comments' => $comments,
            'labels' => $proposal->sectionLabels(),
            'settings' => CompanySetting::current(),
        ]);
    }

    public function edit(Proposal $proposal): View
    {
        return view('proposals.edit', [
            'proposal' => $proposal,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ProposalRequest $request, Proposal $proposal): RedirectResponse
    {
        $previousLogo = $proposal->cover()['client_logo'] ?? null;

        $proposal->fill([
            'title' => $request->validated('title'),
            'client_id' => $request->validated('client_id'),
            'valid_until' => $request->validated('valid_until'),
        ]);
        $proposal->sections = $this->sectionsWithLogo($request, ProposalBlocks::fromForm($request->sectionsForm()));
        $proposal->save();

        // Only once the new path is saved, and only an upload this proposal
        // no longer uses -- a duplicate may still point at the same file.
        $currentLogo = $proposal->cover()['client_logo'] ?? null;
        if ($previousLogo !== $currentLogo && ! $this->logoInUse($previousLogo)) {
            PublicUpload::delete($previousLogo);
        }

        return redirect()->route('proposals.show', $proposal)->with('status', 'Proposal saved.');
    }

    public function destroy(Proposal $proposal): RedirectResponse
    {
        $logo = $proposal->cover()['client_logo'] ?? null;
        $proposal->delete();

        if (! $this->logoInUse($logo)) {
            PublicUpload::delete($logo);
        }

        return redirect()->route('proposals.index')->with('status', 'Proposal deleted.');
    }

    /**
     * A copy to rewrite for the next client: same sections, same design, a
     * fresh draft with no link and none of the old client's comments.
     */
    public function duplicate(Request $request, Proposal $proposal): RedirectResponse
    {
        $copy = $proposal->replicate(['public_token', 'token_issued_at', 'first_viewed_at']);
        $copy->forceFill([
            'title' => $proposal->title.' (copy)',
            'status' => Proposal::STATUS_DRAFT,
            'created_by_id' => $request->user()->id,
        ])->save();

        return redirect()->route('proposals.edit', $copy)->with('status', 'Copy created. Change the client details and save.');
    }

    public function updateStatus(Request $request, Proposal $proposal): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Proposal::STATUSES))],
        ]);

        $proposal->forceFill(['status' => $validated['status']])->save();

        return back()->with('status', 'Status set to '.Proposal::STATUSES[$validated['status']].'.');
    }

    public function issueLink(Proposal $proposal): RedirectResponse
    {
        $replacing = $proposal->public_token !== null;
        $proposal->issuePublicToken();

        return back()->with('status', $replacing
            ? 'New link created. The previous one stops working immediately.'
            : 'Link created. Anyone with it can read this proposal and leave comments.');
    }

    public function revokeLink(Proposal $proposal): RedirectResponse
    {
        $proposal->revokePublicToken();

        return back()->with('status', 'Link closed. Comments already left are kept.');
    }

    public function pdf(Proposal $proposal): Response
    {
        return self::renderPdf($proposal)->download(self::pdfFilename($proposal));
    }

    /**
     * Shared with PublicProposalController so both links print the same
     * document.
     */
    public static function renderPdf(Proposal $proposal): \Barryvdh\DomPDF\PDF
    {
        $proposal->loadMissing('client');
        $html = view('proposals.document', [
            'proposal' => $proposal,
            'settings' => CompanySetting::current(),
        ])->render();

        // Poppins has no arrow glyph -- the browser quietly falls back to a
        // system font, dompdf draws an empty box. The flows and timelines
        // lean on "→", so it is set in DejaVu Sans (bundled with dompdf) at
        // normal weight -- dompdf cannot map 600 onto DejaVu's bold file.
        // Body only, so a title containing one is not mangled in <title>.
        [$head, $body] = explode('<body>', $html, 2) + [1 => ''];
        $html = $head.'<body>'.str_replace('→', '<span style="font-family: \'DejaVu Sans\'; font-weight: normal;">→</span>', $body);

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        /*
         * The running footer -- "Chakra App Studio · Proposal for X" and
         * "NN / TT" over a hairline, as on every page of the design. Drawn by
         * a page script after layout rather than in the HTML, because only
         * then are the page count and each page's number known, and it lands
         * at the same spot however the content broke. Skipped on the cover.
         */
        $footer = $proposal->footerText();
        $hasCover = $proposal->cover() !== null;

        $pdf->render();
        $pdf->getDomPDF()->getCanvas()->page_script(
            function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($footer, $hasCover) {
                if ($hasCover && $page === 1) {
                    return;
                }

                $font = $metrics->getFont('Poppins') ?? $metrics->getFont('helvetica');
                $size = 8;
                $grey = [0.42, 0.45, 0.50];
                $left = 32.4; // 0.45in, the page's side margin
                $right = $canvas->get_width() - 32.4;
                $y = $canvas->get_height() - 34;

                $canvas->line($left, $y - 8, $right, $y - 8, [0.898, 0.906, 0.922], 0.75);
                $canvas->text($left, $y, $footer, $font, $size, $grey);

                $number = str_pad((string) $page, 2, '0', STR_PAD_LEFT).' / '.str_pad((string) $pages, 2, '0', STR_PAD_LEFT);
                $canvas->text($right - $metrics->getTextWidth($number, $font, $size), $y, $number, $font, $size, $grey);
            }
        );

        return $pdf;
    }

    public static function pdfFilename(Proposal $proposal): string
    {
        return (str($proposal->title)->slug()->value() ?: 'proposal').'.pdf';
    }

    /**
     * Apply the cover-logo upload or removal to the cover section. With
     * neither, the path the editor posted back (normalised to proposal
     * art or an earlier proposal upload) is kept as it is.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function sectionsWithLogo(ProposalRequest $request, array $sections): array
    {
        $upload = $request->hasFile('cover_logo')
            ? PublicUpload::store($request->file('cover_logo'), 'proposals')
            : null;

        foreach ($sections as &$section) {
            if ($section['type'] !== 'cover') {
                continue;
            }

            if ($upload !== null) {
                $section['data']['client_logo'] = $upload;
            } elseif ($request->boolean('remove_cover_logo')) {
                $section['data']['client_logo'] = null;
            }
        }

        return $sections;
    }

    /**
     * Whether any proposal still points at an uploaded logo -- a duplicate
     * shares its original's file, so deleting one must not break the other.
     * Checked in PHP rather than with a LIKE on the JSON: the cast escapes
     * slashes, and a backslash is LIKE's own escape character in MySQL.
     */
    private function logoInUse(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        return Proposal::query()->get(['id', 'sections'])
            ->contains(fn (Proposal $p) => ($p->cover()['client_logo'] ?? null) === $path);
    }
}
