<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceTemplate;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class InvoiceTemplateController extends Controller
{
    public function edit(InvoiceDocumentRenderer $renderer): View
    {
        $template = InvoiceTemplate::active();
        $settings = CompanySetting::current();
        $sample = $this->sampleInvoice();

        return view('invoice-templates.edit', [
            'template' => $template,
            'catalog' => InvoiceTemplate::blockCatalog(),
            'sampleInvoiceId' => $sample?->id,
            'placeholders' => [
                'company_name', 'logo', 'watermark', 'invoice_number', 'invoice_date',
                'client_name', 'client_address', 'client_phone', 'intro_text',
                'items_table', 'total', 'subtotal', 'signature_name', 'signature_title', 'footer_text',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', 'in:blocks,html'],
            'blocks' => ['nullable', 'string'],
            'html' => ['nullable', 'string'],
            'custom_css' => ['nullable', 'string'],
        ]);

        $blocks = null;
        if (! empty($data['blocks'])) {
            $decoded = json_decode($data['blocks'], true);
            if (! is_array($decoded)) {
                return back()->withErrors(['blocks' => 'Invalid blocks JSON.'])->withInput();
            }
            $blocks = $this->sanitizeBlocks($decoded);
        }

        $html = $data['html'] ?? null;
        if (is_string($html)) {
            // Never allow PHP execution from the HTML editor.
            $html = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $html);
        }

        $template = InvoiceTemplate::active();
        $template->update([
            'name' => $data['name'],
            'mode' => $data['mode'],
            'blocks' => $blocks ?? $template->blocks ?? InvoiceTemplate::defaultBlocks(),
            'html' => $html,
            'custom_css' => $data['custom_css'] ?? null,
            'is_active' => true,
        ]);

        return redirect()
            ->route('invoice-template.edit')
            ->with('status', 'Invoice PDF template saved.');
    }

    /**
     * Live preview from the editor (unsaved draft).
     */
    public function preview(Request $request, InvoiceDocumentRenderer $renderer): Response
    {
        $data = $request->validate([
            'mode' => ['required', 'in:blocks,html'],
            'blocks' => ['nullable'],
            'html' => ['nullable', 'string'],
            'custom_css' => ['nullable', 'string'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ]);

        $blocks = $data['blocks'] ?? null;
        if (is_string($blocks)) {
            $blocks = json_decode($blocks, true);
        }
        if (! is_array($blocks)) {
            $blocks = InvoiceTemplate::defaultBlocks();
        } else {
            $blocks = $this->sanitizeBlocks($blocks);
        }

        $invoice = ! empty($data['invoice_id'])
            ? Invoice::with('client', 'items')->findOrFail($data['invoice_id'])
            : ($this->sampleInvoice() ?? $this->syntheticInvoice());

        $html = $renderer->render($invoice, CompanySetting::current(), [
            'mode' => $data['mode'],
            'blocks' => $blocks,
            'html' => $data['html'] ?? null,
            'custom_css' => $data['custom_css'] ?? null,
        ]);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Generate HTML body from current blocks (for switching into HTML mode).
     */
    public function generateHtml(Request $request, InvoiceDocumentRenderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'blocks' => ['nullable'],
        ]);

        $blocks = $data['blocks'] ?? null;
        if (is_string($blocks)) {
            $blocks = json_decode($blocks, true);
        }
        if (! is_array($blocks)) {
            $blocks = InvoiceTemplate::defaultBlocks();
        } else {
            $blocks = $this->sanitizeBlocks($blocks);
        }

        $invoice = $this->sampleInvoice() ?? $this->syntheticInvoice();
        $html = $renderer->blocksToEditableHtml($blocks, $invoice, CompanySetting::current());

        return response()->json(['html' => $html]);
    }

    public function reset(): RedirectResponse
    {
        $template = InvoiceTemplate::active();
        $template->update([
            'name' => 'Classic',
            'mode' => 'blocks',
            'blocks' => InvoiceTemplate::defaultBlocks(),
            'html' => null,
            'custom_css' => null,
        ]);

        return redirect()
            ->route('invoice-template.edit')
            ->with('status', 'Template reset to the classic layout.');
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array{id: string, type: string, enabled: bool, settings: array<string, mixed>}>
     */
    private function sanitizeBlocks(array $blocks): array
    {
        $allowed = collect(InvoiceTemplate::blockCatalog())->pluck('type')->all();
        $clean = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, $allowed, true)) {
                continue;
            }

            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $safeSettings = [];
            foreach ($settings as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                if (is_string($value) || is_numeric($value) || is_bool($value) || is_null($value)) {
                    $safeSettings[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
                }
            }

            $clean[] = [
                'id' => (string) ($block['id'] ?? uniqid('b_', true)),
                'type' => $type,
                'enabled' => (bool) ($block['enabled'] ?? true),
                'settings' => $safeSettings,
            ];
        }

        return $clean;
    }

    private function sampleInvoice(): ?Invoice
    {
        return Invoice::query()
            ->with('client', 'items')
            ->whereHas('items')
            ->latest('id')
            ->first();
    }

    /**
     * Fallback when the DB has no invoices yet — enough shape for a preview.
     */
    private function syntheticInvoice(): Invoice
    {
        $invoice = new Invoice([
            'invoice_number' => 'CP-PREVIEW',
            'invoice_date' => now(),
            'intro_text' => 'Professional services rendered as per our agreement.',
            'subtotal' => 15000,
            'total' => 15000,
            'discount_label' => null,
            'discount_amount' => null,
        ]);

        $client = new \App\Models\Client([
            'name' => 'Sample Client',
            'address' => '123 Example Street',
            'phone' => '9000000000',
        ]);
        $invoice->setRelation('client', $client);

        $items = collect([
            new \App\Models\InvoiceItem(['description' => 'Reels', 'quantity' => 1, 'unit_price' => 5000, 'line_total' => 5000]),
            new \App\Models\InvoiceItem(['description' => 'SMM', 'quantity' => 1, 'unit_price' => 5000, 'line_total' => 5000]),
            new \App\Models\InvoiceItem(['description' => 'Posts', 'quantity' => 1, 'unit_price' => 5000, 'line_total' => 5000]),
        ]);
        $invoice->setRelation('items', $items);

        return $invoice;
    }
}
