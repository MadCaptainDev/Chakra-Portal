<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();

        $invoices = Invoice::query()
            ->with('client')
            ->when($search, function ($query, $search) {
                $query->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            })
            ->latest('invoice_date')
            ->paginate(20)
            ->withQueryString();

        return view('invoices.index', compact('invoices', 'search'));
    }

    public function create(): View
    {
        $clients = Client::orderBy('name')->get();

        return view('invoices.create', compact('clients'));
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $invoice = DB::transaction(function () use ($request) {
            $settings = CompanySetting::current();

            $invoice = Invoice::create([
                'invoice_number' => Invoice::nextInvoiceNumber($settings->invoice_prefix),
                'client_id' => $request->validated('client_id'),
                'invoice_date' => $request->validated('invoice_date'),
                'intro_text' => $request->validated('intro_text'),
                'discount_label' => $request->validated('discount_label'),
                'discount_amount' => $request->validated('discount_amount'),
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
        $invoice->load('client', 'items');
        $settings = CompanySetting::current();

        return view('invoices.show', compact('invoice', 'settings'));
    }

    public function edit(Invoice $invoice): View
    {
        $invoice->load('items');
        $clients = Client::orderBy('name')->get();

        return view('invoices.edit', compact('invoice', 'clients'));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        DB::transaction(function () use ($request, $invoice) {
            $invoice->update([
                'client_id' => $request->validated('client_id'),
                'invoice_date' => $request->validated('invoice_date'),
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
        $invoice->delete();

        return redirect()->route('invoices.index')->with('status', 'Invoice deleted.');
    }

    public function pdf(Invoice $invoice): Response
    {
        $invoice->load('client', 'items');
        $settings = CompanySetting::current();

        $pdf = Pdf::loadView('invoices.document', [
            'invoice' => $invoice,
            'settings' => $settings,
        ])->setPaper('a4');

        return $pdf->download("{$invoice->invoice_number}.pdf");
    }

    public function preview(Invoice $invoice): View
    {
        $invoice->load('client', 'items');
        $settings = CompanySetting::current();

        return view('invoices.document', compact('invoice', 'settings'));
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
