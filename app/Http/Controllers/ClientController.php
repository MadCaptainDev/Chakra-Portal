<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\CompetitorSetting;
use App\Models\TaxonomyTerm;
use App\Support\PublicUpload;
use App\Support\TimesheetStats;
use App\Support\TimesheetVenture;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        // The brief comes along so the list can show who still owes one
        // without a query per row.
        $clients = Client::with('brief.answers')->orderBy('name')->paginate(20);

        return view('clients.index', compact('clients'));
    }

    public function create(): View
    {
        return view('clients.create', ['industries' => TaxonomyTerm::options(TaxonomyTerm::TYPE_INDUSTRY)]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::create($this->withoutLogoFields($request));
        $this->applyLogo($request, $client);

        return redirect()->route('clients.index')->with('status', 'Client created.');
    }

    /**
     * Create a client from the invoice form's modal. Answers with JSON
     * instead of a redirect so the half-filled invoice behind the modal
     * survives - sending the user off to the clients page loses the draft.
     */
    public function quickStore(ClientRequest $request): JsonResponse
    {
        $client = Client::create($this->withoutLogoFields($request));

        return response()->json($this->quickPayload($client), 201);
    }

    public function quickUpdate(ClientRequest $request, Client $client): JsonResponse
    {
        $client->update($this->withoutLogoFields($request));

        return response()->json($this->quickPayload($client));
    }

    /**
     * @return array<string, mixed>
     */
    private function quickPayload(Client $client): array
    {
        return $client->only(['id', 'name', 'address', 'email', 'phone', 'notion_venture']);
    }

    public function show(Request $request, Client $client): View
    {
        /*
         * What the client told us about their brand. Loaded, never created --
         * a staff member opening this record must not start a brief on the
         * client's behalf, or "not started" stops meaning anything.
         */
        $client->load('brief.answers');

        /*
         * The money block is admin-only, even though the screen is not any
         * more. Someone given the Clients module to keep records and logins
         * tidy has not thereby been handed what every client has paid and
         * owes. Withheld here rather than hidden in the view, so the rows are
         * never loaded for a request that may not see them.
         */
        $invoices = $request->user()->isAdmin()
            ? $client->invoices()->with('payments')->latest('invoice_date')->get()
            : collect();

        $timesheet = TimesheetStats::forClient($client);
        $ventureLabel = TimesheetVenture::canonicalForClient($client);

        // The login, if one has been issued. Null is the normal case and the
        // panel offers to create one.
        $login = $client->login()->first();

        /*
         * Loaded only for somebody allowed to see them. Not a display concern:
         * an @can in the view would still have pulled the ciphertext into
         * memory for a request that had no business holding it.
         */
        $credentials = $request->user()->can('clients.credentials')
            ? $client->credentials()->with(['views.user' => fn ($q) => $q->select('id', 'name')])->get()
            : collect();

        /*
         * Competitor analysis is its own module. Loaded only when the viewer
         * can see it — same pattern as credentials: an @can in the view must
         * not be what decides whether the rows were queried.
         */
        $competitors = $request->user()->can('competitors.view')
            ? $client->competitorAccounts()->withCount('reels')->get()
            : collect();

        $competitorSettings = $request->user()->can('competitors.view')
            ? CompetitorSetting::current()
            : null;

        return view('clients.show', compact(
            'client', 'invoices', 'timesheet', 'ventureLabel', 'login', 'credentials',
            'competitors', 'competitorSettings',
        ));
    }

    public function edit(Client $client): View
    {
        // keep: a sector the studio has since retired stays in this client's
        // own picker, so editing their phone number does not drop it.
        return view('clients.edit', [
            'client' => $client,
            'industries' => TaxonomyTerm::options(TaxonomyTerm::TYPE_INDUSTRY, $client->industry_id),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($this->withoutLogoFields($request));
        $this->applyLogo($request, $client);

        return redirect()->route('clients.index')->with('status', 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $logo = $client->logo_path;

        try {
            $client->delete();
        } catch (QueryException) {
            return redirect()->route('clients.index')
                ->with('error', 'This client has invoices and cannot be deleted.');
        }

        // Only once the row is actually gone -- a client that could not be
        // deleted must keep its logo.
        PublicUpload::delete($logo);

        return redirect()->route('clients.index')->with('status', 'Client deleted.');
    }

    /**
     * The validated fields minus the two the logo owns, so neither the
     * UploadedFile nor the remove checkbox reaches a mass assignment.
     *
     * @return array<string, mixed>
     */
    private function withoutLogoFields(ClientRequest $request): array
    {
        return collect($request->validated())->except(['logo', 'remove_logo'])->all();
    }

    /**
     * Store, replace or clear the client's logo. The previous file is removed
     * only after the new path is saved, so a failed write never leaves the row
     * pointing at a file that is gone.
     */
    private function applyLogo(ClientRequest $request, Client $client): void
    {
        if ($request->boolean('remove_logo') && $client->logo_path) {
            $previous = $client->logo_path;
            $client->update(['logo_path' => null]);
            PublicUpload::delete($previous);

            return;
        }

        if ($request->hasFile('logo')) {
            $previous = $client->logo_path;
            $client->update(['logo_path' => PublicUpload::store($request->file('logo'), 'clients')]);
            PublicUpload::delete($previous);
        }
    }
}
