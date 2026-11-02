<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\SaasProduct;
use App\Services\InvoiceDocumentRenderer;
use App\Services\InvoiceWhatsappSender;
use App\Services\Invoices\InvoiceWriter;
use App\Support\InvoiceFilters;
use App\Support\InvoiceQuantityVariable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = InvoiceFilters::fromRequest($request);

        $invoices = $filters->sorted()
            ->with(['client', 'payments', 'saasProduct'])
            ->paginate(20)
            ->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'summary' => $filters->summary(),
            // Only clients that have ever been invoiced -- a picker of every
            // client would mostly offer empty lists.
            'clients' => Client::query()->whereHas('invoices')->orderBy('name')->get(['id', 'name']),
            'client' => $filters->clientId ? Client::find($filters->clientId, ['id', 'name']) : null,
        ]);
    }

    /**
     * The filtered list as CSV -- exactly the invoices the screen shows,
     * every page of them, for the accountant or a client statement.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = InvoiceFilters::fromRequest($request);
        $client = $filters->clientId ? Client::find($filters->clientId) : null;

        $name = 'invoices-'.str($client?->name ?? 'all')->slug().'-'.str($filters->periodLabel())->slug().'.csv';

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice #', 'Client', 'Work', 'Invoice date', 'Due date', 'Status', 'Total', 'Paid', 'Balance due']);

            $filters->sorted()->with(['client', 'payments'])->chunk(200, function ($invoices) use ($out) {
                foreach ($invoices as $invoice) {
                    fputcsv($out, [
                        $invoice->invoice_number ?? 'Pending',
                        $invoice->client?->name,
                        $invoice->saas_product_id
                            ? ($invoice->saas_invoice_type === Invoice::STUDIO_TYPE_AMC ? 'App Studio · AMC' : 'App Studio · Development')
                            : 'Production',
                        $invoice->invoice_date?->format('Y-m-d'),
                        $invoice->due_date?->format('Y-m-d'),
                        str($invoice->displayStatus())->replace('_', ' ')->title(),
                        number_format((float) $invoice->total, 2, '.', ''),
                        number_format($invoice->paidTotal(), 2, '.', ''),
                        number_format($invoice->balanceDue(), 2, '.', ''),
                    ]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
    public function create(): View
    {
        $clients = Client::orderBy('name')->get();
        $saasProducts = SaasProduct::with('client')->orderBy('name')->get();

        return view('invoices.create', compact('clients', 'saasProducts'));
    }

    public function store(InvoiceRequest $request, InvoiceWriter $writer): RedirectResponse
    {
        $invoice = $writer->create($request->validated(), $request->user()->id);

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice created.');
    }
    public function show(Invoice $invoice): View
    {
        $invoice->load('client', 'items', 'payments.recordedBy', 'whatsappLogs.sentBy');
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

    public function update(InvoiceRequest $request, Invoice $invoice, InvoiceWriter $writer): RedirectResponse
    {
        $writer->update($invoice, $request->validated());

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
     * Hand this invoice to whatever WhatsApp number is typed in -- the
     * client's own on file by default, but any number, any time: a template
     * message ("your invoice is ready") whose button links to a no-login PDF.
     *
     * A template rather than free text because this is very often the first
     * contact with that number -- outside the 24-hour reply window, only an
     * approved template can reach them at all (see WhatsappSender's own
     * doc block). Invoice::WHATSAPP_TEMPLATE is the one this feature was
     * built around; it must exist and be Meta-approved before this can send
     * (see SeedInvoiceReadyTemplate).
     */
    public function sendWhatsapp(Request $request, Invoice $invoice, InvoiceWhatsappSender $sender): RedirectResponse
    {
        if (! $invoice->isSendableViaWhatsapp()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'This invoice cannot be sent over WhatsApp until it is approved.');
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
        ], [
            'phone.regex' => 'That doesn\'t look like a phone number.',
        ]);

        try {
            $sender->send($invoice, $validated['phone'], $request->user()->id);
        } catch (RuntimeException $e) {
            // Meta's own reason (template not approved yet, number not
            // reachable, ...) is the useful part -- surfaced as-is rather
            // than a generic "failed to send". The attempt is already
            // logged (see DocumentWhatsappNotifier) even though this send
            // itself failed.
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Sent to {$validated['phone']} on WhatsApp.");
    }

    public function discard(Invoice $invoice): RedirectResponse
    {
        if (! $invoice->isPendingApproval()) {
            return redirect()->route('invoices.show', $invoice)->with('error', 'Only a pending-approval invoice can be discarded.');
        }

        $invoice->delete();

        return redirect()->route('invoices.index', ['status' => 'pending_approval'])->with('status', 'Invoice discarded.');
    }

    public function duplicate(Request $request, Invoice $invoice, InvoiceWriter $writer): RedirectResponse
    {
        $copy = $writer->duplicate($invoice, $request->user()->id);

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

    public function previewQuantityVariables(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'month' => ['required', 'date'],
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $month = Carbon::parse($validated['month'])->startOfMonth();

        return response()->json(InvoiceQuantityVariable::countsFor($client, $month));
    }
}
