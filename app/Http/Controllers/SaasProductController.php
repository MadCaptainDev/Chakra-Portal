<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\RecurringInvoice;
use App\Models\SaasBackup;
use App\Models\SaasProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin side of the backup + AMC licensing platform -- onboarding a new
 * piece of Chakra App Studio software, issuing the token its own server
 * authenticates with, and the one manual lever this whole feature exists
 * for: suspending a client who has stopped paying (see
 * routes/saas-api.php + AuthenticateSaasProduct for the client-facing half).
 */
class SaasProductController extends Controller
{
    public function index(): View
    {
        return view('saas-products.index', [
            'products' => SaasProduct::query()
                ->withCount('backups')
                ->with('client')
                ->orderBy('name')
                ->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'backup_retention_count' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $product = SaasProduct::create($validated);

        // Issued immediately rather than as a separate step -- the token is
        // useless without a product to belong to, and this is the one and
        // only moment its plaintext exists outside the client's own config.
        $plain = $product->issueToken();

        return redirect()->route('saas-products.show', $product)
            ->with('status', "\"{$product->name}\" created. Copy its token now — it will not be shown again.")
            ->with('saas_token_plain', $plain);
    }

    public function show(SaasProduct $saasProduct): View
    {
        return view('saas-products.show', [
            'product' => $saasProduct->load(['client', 'suspendedBy', 'recurringInvoice']),
            'backups' => $saasProduct->backups()->newestFirst()->get(),
            'totalBytes' => (int) $saasProduct->backups()->sum('size_bytes'),
        ]);
    }

    public function destroy(SaasProduct $saasProduct): RedirectResponse
    {
        $name = $saasProduct->name;

        // Backup rows cascade on delete (see the migration), but the files
        // themselves are on disk and nobody else will ever clean those up.
        $saasProduct->backups()->each(fn (SaasBackup $backup) => Storage::disk('local')->delete($backup->disk_path));

        $saasProduct->delete();

        return redirect()->route('saas-products.index')->with('status', "\"{$name}\" and its backup history were deleted.");
    }

    /**
     * The lever the whole AMC model is built around. Its own token still
     * authenticates (see AuthenticateSaasProduct) -- suspension is read at
     * license-check time, not enforced by locking the product out here.
     */
    public function suspend(Request $request, SaasProduct $saasProduct): RedirectResponse
    {
        $saasProduct->suspend($request->user());

        return back()->with('status', "\"{$saasProduct->name}\" is suspended. Its own software will see this on its next license check.");
    }

    public function reinstate(SaasProduct $saasProduct): RedirectResponse
    {
        $saasProduct->reinstate();

        return back()->with('status', "\"{$saasProduct->name}\" is reinstated.");
    }

    /**
     * Sets a product up for yearly AMC billing -- a single-item recurring
     * invoice schedule tied back to this product, so every invoice it
     * generates carries saas_product_id and, once paid, extends
     * amc_paid_until automatically (see Invoice::recalculateStatus()).
     * One-time: refuses if a schedule is already linked, since a second one
     * would double-bill and double-extend.
     */
    public function setupAmc(Request $request, SaasProduct $saasProduct): RedirectResponse
    {
        abort_if($saasProduct->recurring_invoice_id, 422, 'AMC billing is already set up for this product.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'next_run_on' => ['required', 'date', 'after_or_equal:today'],
            'due_days' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated, $saasProduct, $request) {
            $nextRunOn = Carbon::parse($validated['next_run_on']);

            $schedule = RecurringInvoice::create([
                'client_id' => $saasProduct->client_id,
                'saas_product_id' => $saasProduct->id,
                'label' => "AMC — {$saasProduct->name}",
                'frequency' => RecurringInvoice::FREQUENCY_YEARLY,
                'day_of_month' => $nextRunOn->day,
                'due_days' => $validated['due_days'] ?? null,
                'next_run_on' => $nextRunOn,
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            $schedule->items()->create([
                'description' => 'Annual Maintenance Contract — '.$saasProduct->name,
                'quantity' => 1,
                'unit_price' => $validated['amount'],
                'sort_order' => 0,
            ]);

            $saasProduct->update(['recurring_invoice_id' => $schedule->id]);
        });

        return back()->with('status', 'AMC billing set up. The first invoice is generated automatically once it falls due.');
    }

    /**
     * The admin's own way to fetch a backup, distinct from
     * Api\SaasBackupController::download() -- that one authenticates a
     * SaasProduct's bearer token, this one authenticates the signed-in
     * user's session, and the two must never be conflated.
     */
    public function downloadBackup(SaasProduct $saasProduct, SaasBackup $backup): StreamedResponse
    {
        abort_unless($backup->saas_product_id === $saasProduct->id, 404);
        abort_unless(Storage::disk('local')->exists($backup->disk_path), 404);

        return Storage::disk('local')->download($backup->disk_path, $backup->original_filename ?: 'backup.bin');
    }
}
