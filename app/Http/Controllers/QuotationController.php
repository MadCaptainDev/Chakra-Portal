<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuotationRequest;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Quotation;
use App\Services\DocumentWhatsappNotifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();
        $month = $this->resolveMonth($request->query('month'));

        $listed = Quotation::query()
            ->whereDate('quotation_date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('quotation_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->when($search, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('quotation_number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($status === 'expired', function ($query) {
                $query->where('status', Quotation::STATUS_DRAFT)
                    ->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<', now()->toDateString());
            })
            ->when($status === 'converted', fn ($query) => $query->whereNotNull('converted_invoice_id'))
            // "expired" and "converted" are both derived, never stored.
            ->when($status && ! in_array($status, ['expired', 'converted'], true),
                fn ($query) => $query->where('status', $status))
            // Chakra Production vs Chakra App Studio -- just the label,
            // no AMC/development split, since a quotation carries no real
            // SaasProduct yet (see Quotation::is_app_studio).
            ->when($type === 'studio', fn ($query) => $query->where('is_app_studio', true))
            ->when($type === 'production', fn ($query) => $query->where('is_app_studio', false));

        $monthTotal = (float) (clone $listed)->sum('total');

        $quotations = $listed
            ->with(['client', 'convertedInvoice'])
            ->latest('quotation_date')
            ->paginate(20)
            ->withQueryString();

        return view('quotations.index', compact('quotations', 'search', 'status', 'type', 'month', 'monthTotal'));
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

        return view('quotations.create', compact('clients'));
    }

    public function store(QuotationRequest $request): RedirectResponse
    {
        $quotation = DB::transaction(function () use ($request) {
            $settings = CompanySetting::current();

            $quotation = Quotation::create([
                'quotation_number' => Quotation::nextQuotationNumber($settings->quotation_prefix),
                'client_id' => $request->validated('client_id'),
                'is_app_studio' => $request->boolean('is_app_studio'),
                'quotation_date' => $request->validated('quotation_date'),
                'valid_until' => $request->validated('valid_until'),
                'intro_text' => $request->validated('intro_text'),
                'notes' => $request->validated('notes'),
                'discount_label' => $request->validated('discount_label'),
                'discount_amount' => $request->validated('discount_amount'),
                'status' => Quotation::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);

            $this->syncItems($quotation, $request->validated('items'));

            $quotation->load('items');
            $quotation->recalculateTotals();
            $quotation->save();

            return $quotation;
        });

        return redirect()->route('quotations.show', $quotation)->with('status', 'Quotation created.');
    }

    public function show(Quotation $quotation): View
    {
        $quotation->load('client', 'items', 'convertedInvoice', 'whatsappLogs.sentBy');
        $settings = CompanySetting::current();

        return view('quotations.show', compact('quotation', 'settings'));
    }

    public function edit(Quotation $quotation): View
    {
        $quotation->load('items');
        $clients = Client::orderBy('name')->get();

        return view('quotations.edit', compact('quotation', 'clients'));
    }

    public function update(QuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        DB::transaction(function () use ($request, $quotation) {
            $quotation->update([
                'client_id' => $request->validated('client_id'),
                'is_app_studio' => $request->boolean('is_app_studio'),
                'quotation_date' => $request->validated('quotation_date'),
                'valid_until' => $request->validated('valid_until'),
                'intro_text' => $request->validated('intro_text'),
                'notes' => $request->validated('notes'),
                'discount_label' => $request->validated('discount_label'),
                'discount_amount' => $request->validated('discount_amount'),
            ]);

            $quotation->items()->delete();
            $this->syncItems($quotation, $request->validated('items'));

            $quotation->load('items');
            $quotation->recalculateTotals();
            $quotation->save();
        });

        return redirect()->route('quotations.show', $quotation)->with('status', 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        if ($quotation->isConverted()) {
            return redirect()->route('quotations.show', $quotation)
                ->with('error', 'This quotation was already converted to an invoice and cannot be deleted.');
        }

        $quotation->delete();

        return redirect()->route('quotations.index')->with('status', 'Quotation deleted.');
    }

    public function accept(Quotation $quotation): RedirectResponse
    {
        if (! $quotation->canAccept()) {
            return redirect()->route('quotations.show', $quotation)->with('error', 'This quotation has already been decided.');
        }

        $quotation->accept();

        return redirect()->route('quotations.show', $quotation)->with('status', 'Marked as accepted.');
    }

    public function reject(Quotation $quotation): RedirectResponse
    {
        if (! $quotation->canReject()) {
            return redirect()->route('quotations.show', $quotation)->with('error', 'This quotation has already been decided.');
        }

        $quotation->reject();

        return redirect()->route('quotations.show', $quotation)->with('status', 'Marked as rejected.');
    }

    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        if (! $quotation->canConvert()) {
            return redirect()->route('quotations.show', $quotation)
                ->with('error', 'Only an accepted, not-yet-converted quotation can become an invoice.');
        }

        $invoice = $quotation->convertToInvoice($request->user()->id);

        return redirect()->route('invoices.show', $invoice)->with('status', "Converted to invoice {$invoice->invoice_number}.");
    }

    /**
     * Hand this quotation to whatever WhatsApp number is typed in -- same
     * shape as InvoiceController::sendWhatsapp(), a template message whose
     * button links to a no-login PDF. Quotation::WHATSAPP_TEMPLATE must
     * exist and be Meta-approved before this can send (see
     * `app:seed-quotation-ready-template`).
     */
    public function sendWhatsapp(Request $request, Quotation $quotation, DocumentWhatsappNotifier $notifier): RedirectResponse
    {
        $quotation->loadMissing('client');

        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
        ], [
            'phone.regex' => 'That doesn\'t look like a phone number.',
        ]);

        try {
            $notifier->send(
                document: $quotation,
                phone: $validated['phone'],
                template: Quotation::WHATSAPP_TEMPLATE,
                bodyParameters: [
                    $quotation->client->name,
                    $quotation->quotation_number ?? '',
                    number_format((float) $quotation->total, 2),
                ],
                buttonUrlParameter: $quotation->ensurePublicToken(),
                sentByUserId: $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return redirect()->route('quotations.show', $quotation)->with('error', $e->getMessage());
        }

        $quotation->update(['whatsapp_sent_at' => now()]);

        return redirect()->route('quotations.show', $quotation)
            ->with('status', "Sent to {$validated['phone']} on WhatsApp.");
    }

    public function pdf(Quotation $quotation): Response
    {
        $quotation->load('client', 'items');
        $settings = CompanySetting::current();
        $html = view('quotations.document', compact('quotation', 'settings'))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        return $pdf->download(($quotation->quotation_number ?? 'DRAFT').'.pdf');
    }

    public function preview(Quotation $quotation): HttpResponse
    {
        $quotation->load('client', 'items');
        $settings = CompanySetting::current();
        $html = view('quotations.document', compact('quotation', 'settings'))->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param  list<array{description: string, quantity: mixed, unit_price: mixed}>  $items
     */
    private function syncItems(Quotation $quotation, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];

            $quotation->items()->create([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($quantity * $unitPrice, 2),
                'sort_order' => $index,
            ]);
        }
    }
}
