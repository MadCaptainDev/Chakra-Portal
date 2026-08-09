<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
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
        Client::create($request->validated());

        return redirect()->route('clients.index')->with('status', 'Client created.');
    }

    /**
     * Create a client from the invoice form's modal. Answers with JSON
     * instead of a redirect so the half-filled invoice behind the modal
     * survives - sending the user off to the clients page loses the draft.
     */
    public function quickStore(ClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return response()->json($this->quickPayload($client), 201);
    }

    public function quickUpdate(ClientRequest $request, Client $client): JsonResponse
    {
        $client->update($request->validated());

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

        return view('clients.show', compact('client', 'invoices', 'timesheet', 'ventureLabel'));
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', compact('client'));
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        return redirect()->route('clients.index')->with('status', 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        try {
            $client->delete();
        } catch (QueryException) {
            return redirect()->route('clients.index')
                ->with('error', 'This client has invoices and cannot be deleted.');
        }

        return redirect()->route('clients.index')->with('status', 'Client deleted.');
    }
}
