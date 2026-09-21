<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAdvance;
use App\Models\TaxonomyTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the studio has paid on its clients' behalf, and what is still owed
 * back.
 *
 * The register is the point, not the data entry: the question this screen
 * exists to answer is "how much am I currently out of pocket for this
 * client", which until now lived only in the owner's memory.
 *
 * Recovery is recorded by hand rather than driven off an invoice. That was a
 * deliberate choice -- some advances are never billed, some are settled in
 * cash, and a link that is right only most of the time is worse here than no
 * link at all.
 */
class ClientAdvanceController extends Controller
{
    /** Where receipts live. Not public/uploads -- see receipt(). */
    private const RECEIPT_DISK = 'local';

    private const RECEIPT_DIR = 'client-advances';

    public function index(Request $request): View
    {
        $filters = [
            'client' => $request->string('client')->toString(),
            'category' => $request->string('category')->toString(),
            // Outstanding by default: that is the question being asked.
            'state' => $request->string('state')->toString() ?: 'outstanding',
        ];

        $advances = ClientAdvance::query()
            ->with(['client', 'categoryTerm'])
            ->when($filters['client'] !== '', fn ($q) => $q->where('client_id', $filters['client']))
            ->when($filters['category'] !== '', fn ($q) => $q->where('category_id', $filters['category']))
            ->when($filters['state'] === 'outstanding', fn ($q) => $q->outstanding())
            ->when($filters['state'] === 'recovered', fn ($q) => $q->recovered())
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();

        /*
         * The per-client summary is computed over EVERY advance, not the
         * filtered list. A total that renumbered itself as you filtered would
         * be describing your filter rather than what you are owed -- the same
         * rule ScriptController::index() applies to its status counters.
         */
        $all = ClientAdvance::query()->with('client')->get();

        $byClient = $all
            ->groupBy('client_id')
            ->map(fn ($rows) => [
                'client' => $rows->first()->client,
                'count' => $rows->count(),
                'advanced' => (float) $rows->sum('amount'),
                'billable' => $rows->sum(fn (ClientAdvance $a) => $a->billable()),
                'recovered' => $rows->where('recovered_at', '!=', null)
                    ->sum(fn (ClientAdvance $a) => $a->billable()),
                'outstanding' => $rows->whereNull('recovered_at')
                    ->sum(fn (ClientAdvance $a) => $a->billable()),
            ])
            ->sortByDesc('outstanding')
            ->values();

        return view('client-advances.index', [
            'advances' => $advances,
            'byClient' => $byClient,
            'filters' => $filters,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'categories' => TaxonomyTerm::options(TaxonomyTerm::TYPE_CLIENT_COST),
            'totalAdvanced' => (float) $all->sum('amount'),
            'totalOutstanding' => $all->whereNull('recovered_at')
                ->sum(fn (ClientAdvance $a) => $a->billable()),
            'totalRecovered' => $all->where('recovered_at', '!=', null)
                ->sum(fn (ClientAdvance $a) => $a->billable()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('client-advances.create', $this->formData(
            new ClientAdvance([
                'client_id' => $request->query('client'),
                'spent_on' => today()->toDateString(),
            ])
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $advance = new ClientAdvance($this->validated($request));
        $advance->created_by_id = $request->user()->id;
        $advance->receipt_path = $this->storeReceipt($request);
        $advance->save();

        return redirect()->route('client-advances.index')
            ->with('status', 'Logged against '.$advance->client->name.'.');
    }

    public function edit(ClientAdvance $clientAdvance): View
    {
        return view('client-advances.edit', $this->formData($clientAdvance));
    }

    public function update(Request $request, ClientAdvance $clientAdvance): RedirectResponse
    {
        $clientAdvance->fill($this->validated($request));

        if ($path = $this->storeReceipt($request)) {
            // The old file goes only once the new one is safely written.
            $this->forgetReceipt($clientAdvance->receipt_path);
            $clientAdvance->receipt_path = $path;
        }

        $clientAdvance->save();

        return redirect()->route('client-advances.index')->with('status', 'Saved.');
    }

    /**
     * Say the money came back.
     *
     * Its own action rather than a field on the edit form: this is the one
     * claim on the record that changes what the studio is owed, and it should
     * take a deliberate press rather than ride along with a typo correction.
     */
    public function markRecovered(Request $request, ClientAdvance $clientAdvance): RedirectResponse
    {
        $validated = $request->validate([
            'recovered_note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($clientAdvance->isRecovered()) {
            return back()->with('status', 'That one was already marked recovered.');
        }

        $clientAdvance->forceFill([
            'recovered_at' => now(),
            'recovered_note' => $validated['recovered_note'] ?? null,
        ])->save();

        return back()->with('status', 'Marked recovered.');
    }

    /** Undo, for the press that was meant for the row below. */
    public function undoRecovered(ClientAdvance $clientAdvance): RedirectResponse
    {
        $clientAdvance->forceFill(['recovered_at' => null, 'recovered_note' => null])->save();

        return back()->with('status', 'Put back to outstanding.');
    }

    /**
     * Hand back the receipt.
     *
     * Streamed through here rather than served from public/uploads like the
     * rest of this app's uploads: a receipt names what a client's campaign
     * cost, and the public-uploads convention is for files a browser has to
     * reach directly. This one does not -- it is read by PHP, so it needs no
     * storage:link either, which matters because symlink() is disabled on
     * this host.
     */
    public function receipt(ClientAdvance $clientAdvance): StreamedResponse
    {
        abort_if($clientAdvance->receipt_path === null, 404);
        abort_unless(Storage::disk(self::RECEIPT_DISK)->exists($clientAdvance->receipt_path), 404);

        return Storage::disk(self::RECEIPT_DISK)->download(
            $clientAdvance->receipt_path,
            'receipt-'.$clientAdvance->id.'.'.pathinfo($clientAdvance->receipt_path, PATHINFO_EXTENSION),
        );
    }

    public function destroy(ClientAdvance $clientAdvance): RedirectResponse
    {
        $this->forgetReceipt($clientAdvance->receipt_path);
        $clientAdvance->delete();

        return redirect()->route('client-advances.index')->with('status', 'Deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'category_id' => [
                'nullable',
                Rule::exists('taxonomy_terms', 'id')->where('type', TaxonomyTerm::TYPE_CLIENT_COST),
            ],
            'name' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            // Left blank means "bill it at cost" -- see ClientAdvance::billable().
            'billable_amount' => ['nullable', 'numeric', 'min:0'],
            'spent_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function storeReceipt(Request $request): ?string
    {
        $request->validate([
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        return $request->hasFile('receipt')
            ? $request->file('receipt')->store(self::RECEIPT_DIR, self::RECEIPT_DISK)
            : null;
    }

    private function forgetReceipt(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::RECEIPT_DISK)->delete($path);
        }
    }

    /** @return array<string, mixed> */
    private function formData(ClientAdvance $advance): array
    {
        return [
            'advance' => $advance,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'categories' => TaxonomyTerm::options(TaxonomyTerm::TYPE_CLIENT_COST, $advance->category_id),
        ];
    }
}
