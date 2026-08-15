<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Support\PublicUpload;
use App\Support\TimesheetStats;
use App\Support\TimesheetVenture;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        $clients = Client::orderBy('name')->paginate(20);

        return view('clients.index', compact('clients'));
    }

    public function create(): View
    {
        return view('clients.create');
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

    public function show(Client $client): View
    {
        $invoices = $client->invoices()->with('payments')->latest('invoice_date')->get();
        $timesheet = TimesheetStats::forClient($client);
        $ventureLabel = TimesheetVenture::canonicalForClient($client);

        // The login, if one has been issued. Null is the normal case and the
        // panel offers to create one.
        $login = $client->login()->first();

        return view('clients.show', compact('client', 'invoices', 'timesheet', 'ventureLabel', 'login'));
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', compact('client'));
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
