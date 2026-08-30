<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\SaasProduct;
use App\Services\InvoiceDocumentRenderer;
use App\Services\WhatsappSender;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use ZipArchive;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();
        $month = $this->resolveMonth($request->query('month'));

        $listed = $this->listedInvoicesQuery($request, $month);

        $monthTotal = (float) (clone $listed)->sum('total');

        $invoices = $listed
            ->with(['client', 'payments', 'saasProduct'])
            ->latest('invoice_date')
            ->paginate(20)
            ->withQueryString();

        return view('invoices.index', compact('invoices', 'search', 'status', 'type', 'month', 'monthTotal'));
    }

    /**
     * The invoices the index actually lists for this month and filter set.
     * Sum and table share this so the total is "what you see", not a
     * separate unpaid+paid-only figure.
     */
    private function listedInvoicesQuery(Request $request, Carbon $month)
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();

        return Invoice::query()
            ->whereDate('invoice_date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('invoice_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->when($search, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when($status === 'partial', fn ($query) => $query->partiallyPaid())
            // "overdue" and "partial" are derived, not stored statuses.
            ->when($status && ! in_array($status, ['overdue', 'partial'], true),
                fn ($query) => $query->where('status', $status))
            // The one filter Chakra App Studio actually asked for: pull its
            // invoices apart from Chakra Production's on the same screen
            // everyone already uses, rather than a second one. "studio" is
            // all App Studio work; amc/development narrow to just one kind
            // of it, since not every App Studio invoice is AMC.
            ->when($type === 'studio', fn ($query) => $query->whereNotNull('saas_product_id'))
            ->when($type === 'production', fn ($query) => $query->whereNull('saas_product_id'))
            ->when($type === 'amc', fn ($query) => $query->where('saas_invoice_type', Invoice::STUDIO_TYPE_AMC))
            ->when($type === 'development', fn ($query) => $query->where('saas_invoice_type', Invoice::STUDIO_TYPE_DEVELOPMENT));
    }

    private function resolveMonth(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfMonth();
        }

        try {
            return Carbon::parse(strlen($value) === 7 ? $value.'-01' : $value)->startOfMonth();
        } catch (Throwable) {
            return now()->startOfMonth();
        }
    }

    public function create(): View
    {
        $clients = Client::orderBy('name')->get();
        $saasProducts = SaasProduct::with('client')->orderBy('name')->get();

        return view('invoices.create', compact('clients', 'saasProducts'));
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $invoice = DB::transaction(function () use ($request) {
            $settings = CompanySetting::current();

            $invoice = Invoice::create([
                'invoice_number' => Invoice::nextInvoiceNumber($settings->invoice_prefix),
                'client_id' => $request->validated('client_id'),
                'saas_product_id' => $request->validated('saas_product_id'),
                'saas_invoice_type' => $request->validated('saas_product_id') ? $request->validated('saas_invoice_type') : null,
                'invoice_date' => $request->validated('invoice_date'),
                'due_date' => $request->validated('due_date'),
                'intro_text' => $request->validated('intro_text'),
                'discount_label' => $request->validated('discount_label'),
                'discount_amount' => $request->validated('discount_amount'),
                'status' => Invoice::STATUS_UNPAID,
                'created_by' => $request->user()->id,
            ]);

            $this->syncItems($invoice, $request->validated('items'));

            $invoice->load('items');
            $invoice->recalculateTotals();
            $invoice->save();

            return $invoice;
        });

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice created.');
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load('client', 'items', 'payments.recordedBy');
        $settings = CompanySetting::current();

        return view('invoices.show', compact('invoice', 'settings'));
    }

    public function edit(Invoice $invoice): View
    {
        $invoice->load('items');
        $clients = Client::orderBy('name')->get();
        $saasProducts = SaasProduct::with('client')->orderBy('name')->get();

        return view('invoices.edit', compact('invoice', 'clients', 'saasProducts'));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        DB::transaction(function () use ($request, $invoice) {
            $invoice->update([
                'client_id' => $request->validated('client_id'),
                'saas_product_id' => $request->validated('saas_product_id'),
                'saas_invoice_type' => $request->validated('saas_product_id') ? $request->validated('saas_invoice_type') : null,
                'invoice_date' => $request->validated('invoice_date'),
                'due_date' => $request->validated('due_date'),
                'intro_text' => $request->validated('intro_text'),
                'discount_label' => $request->validated('discount_label'),
                'discount_amount' => $request->validated('discount_amount'),
            ]);

            $invoice->items()->delete();
            $this->syncItems($invoice, $request->validated('items'));

            $invoice->load('items');
            $invoice->recalculateTotals();
            $invoice->save();
        });

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice updated.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        // payments.invoice_id is cascadeOnDelete, so deleting an invoice takes
        // its payment records with it. Clients and users are already protected
        // from destructive deletes; the accounting record should be too.
        if ($invoice->payments()->exists()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'This invoice has payments recorded against it. Remove those first if you really need to delete it.');
        }

        $invoice->delete();

        return redirect()->route('invoices.index')->with('status', 'Invoice deleted.');
    }

    public function approve(Invoice $invoice): RedirectResponse
    {
        if (! $invoice->isPendingApproval()) {
            return redirect()->route('invoices.show', $invoice)->with('error', 'This invoice is not pending approval.');
        }

        $invoice->approve();

        return redirect()->route('invoices.show', $invoice)->with('status', "Approved as {$invoice->invoice_number}.");
    }

    /**
     * Hand this invoice to its client on WhatsApp: a template message ("your
     * invoice is ready") whose button links to a no-login PDF.
     *
     * A template rather than free text because this is very often the first
     * contact with that number -- outside the 24-hour reply window, only an
     * approved template can reach them at all (see WhatsappSender's own
     * doc block). Invoice::WHATSAPP_TEMPLATE is the one this feature was
     * built around; it must exist and be Meta-approved before this can send
     * (see SeedInvoiceReadyTemplate).
     */
    public function sendWhatsapp(Invoice $invoice): RedirectResponse
    {
        $invoice->loadMissing('client');

        if (! $invoice->isSendableViaWhatsapp()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'This invoice cannot be sent over WhatsApp -- it needs to be approved, recurring, and its client needs a phone number on file.');
        }

        try {
            WhatsappSender::make()->sendTemplate(
                to: $invoice->client->phone,
                template: Invoice::WHATSAPP_TEMPLATE,
                bodyParameters: [
                    $invoice->client->name,
                    $invoice->invoice_number,
                    number_format((float) $invoice->total, 2),
                ],
                // The button's dynamic segment is just the token -- the
                // template's own static base URL supplies the rest (see
                // SeedInvoiceReadyTemplate), so this must not be the full
                // invoice->publicUrl().
                buttonUrlParameter: $invoice->ensurePublicToken(),
            );
        } catch (RuntimeException $e) {
            // Meta's own reason (template not approved yet, number not
            // reachable, ...) is the useful part -- surfaced as-is rather
            // than a generic "failed to send".
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        $invoice->update(['whatsapp_sent_at' => now()]);

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Sent to {$invoice->client->name} on WhatsApp.");
    }

    public function discard(Invoice $invoice): RedirectResponse
    {
        if (! $invoice->isPendingApproval()) {
            return redirect()->route('invoices.show', $invoice)->with('error', 'Only a pending-approval invoice can be discarded.');
        }

        $invoice->delete();

        return redirect()->route('invoices.index', ['status' => 'pending_approval'])->with('status', 'Invoice discarded.');
    }

    public function duplicate(Request $request, Invoice $invoice): RedirectResponse
    {
        $invoice->load('items');

        $copy = DB::transaction(function () use ($request, $invoice) {
            $settings = CompanySetting::current();

            $copy = Invoice::create([
                'invoice_number' => Invoice::nextInvoiceNumber($settings->invoice_prefix),
                'client_id' => $invoice->client_id,
                'saas_product_id' => $invoice->saas_product_id,
                'saas_invoice_type' => $invoice->saas_invoice_type,
                'invoice_date' => now()->format('Y-m-d'),
                'due_date' => null,
                'intro_text' => $invoice->intro_text,
                'discount_label' => $invoice->discount_label,
                'discount_amount' => $invoice->discount_amount,
                'status' => Invoice::STATUS_UNPAID,
                'created_by' => $request->user()->id,
            ]);

            foreach ($invoice->items as $item) {
                $copy->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'sort_order' => $item->sort_order,
                ]);
            }

            $copy->load('items');
            $copy->recalculateTotals();
            $copy->save();

            return $copy;
        });

        return redirect()->route('invoices.edit', $copy)->with('status', "Duplicated as {$copy->invoice_number}. Adjust and save.");
    }

    public function pdf(Invoice $invoice, InvoiceDocumentRenderer $renderer): Response
    {
        $invoice->load('client', 'items');
        $html = $renderer->render($invoice, CompanySetting::current());

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        return $pdf->download(($invoice->invoice_number ?? 'DRAFT').'.pdf');
    }

    public function downloadPdfs(Request $request, InvoiceDocumentRenderer $renderer): Response|BinaryFileResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'distinct', 'exists:invoices,id'],
        ])['ids'];

        $invoices = Invoice::query()
            ->with(['client', 'items'])
            ->whereIn('id', $ids)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            abort(404);
        }

        if ($invoices->count() === 1) {
            return $this->pdf($invoices->first(), $renderer);
        }

        $settings = CompanySetting::current();
        $zipPath = tempnam(sys_get_temp_dir(), 'invzip_');
        if ($zipPath === false) {
            abort(500, 'Unable to create temporary file for zip download.');
        }

        // ZipArchive must create the archive file itself.
        @unlink($zipPath);
        $zipPath .= '.zip';

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to create zip archive.');
        }

        $usedNames = [];
        foreach ($invoices as $invoice) {
            $html = $renderer->render($invoice, $settings);
            $pdfBinary = Pdf::loadHTML($html)->setPaper('a4')->output();
            $baseName = preg_replace('/[^\w.\-]+/', '_', (string) ($invoice->invoice_number ?? 'DRAFT-'.$invoice->id)) ?: 'invoice-'.$invoice->id;
            $filename = $baseName.'.pdf';
            $suffix = 1;
            while (isset($usedNames[$filename])) {
                $filename = $baseName.'-'.$suffix.'.pdf';
                $suffix++;
            }
            $usedNames[$filename] = true;
            $zip->addFromString($filename, $pdfBinary);
        }

        $zip->close();

        $downloadName = 'invoices-'.now()->format('Y-m-d').'.zip';

        return response()->download($zipPath, $downloadName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function preview(Invoice $invoice, InvoiceDocumentRenderer $renderer): HttpResponse
    {
        $invoice->load('client', 'items');
        $html = $renderer->render($invoice, CompanySetting::current());

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param  array<int, array{description: string, quantity: float, unit_price: float}>  $items
     */
    private function syncItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $index => $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['quantity'] * $item['unit_price'],
                'sort_order' => $index,
            ]);
        }
    }
}
