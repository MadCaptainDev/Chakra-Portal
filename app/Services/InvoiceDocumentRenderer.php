<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceTemplate;
use App\Support\Assets;
use App\Support\Fonts;
use Illuminate\Support\Str;

class InvoiceDocumentRenderer
{
    /**
     * Render a full HTML invoice document (preview + PDF source).
     *
     * @param  array{mode?: string, blocks?: array|null, html?: string|null, custom_css?: string|null}|null  $draft
     *         Optional unsaved editor draft; when null, uses the active template.
     */
    public function render(Invoice $invoice, ?CompanySetting $settings = null, ?array $draft = null): string
    {
        $invoice->loadMissing('client', 'items');
        $settings ??= CompanySetting::current();

        $template = $draft ?? [
            'mode' => InvoiceTemplate::active()->mode,
            'blocks' => InvoiceTemplate::active()->blocks,
            'html' => InvoiceTemplate::active()->html,
            'custom_css' => InvoiceTemplate::active()->custom_css,
        ];

        $mode = $template['mode'] ?? 'blocks';
        $customCss = (string) ($template['custom_css'] ?? '');

        if ($mode === 'html' && filled($template['html'] ?? null)) {
            $body = $this->replacePlaceholders((string) $template['html'], $invoice, $settings);
        } else {
            $blocks = $template['blocks'] ?? InvoiceTemplate::defaultBlocks();
            $body = $this->renderBlocks(is_array($blocks) ? $blocks : [], $invoice, $settings);
        }

        return $this->wrapDocument($invoice, $settings, $body, $customCss);
    }

    /**
     * Build the page body HTML from an ordered block list.
     *
     * @param  list<array{id?: string, type: string, enabled?: bool, settings?: array<string, mixed>}>  $blocks
     */
    public function renderBlocks(array $blocks, Invoice $invoice, CompanySetting $settings): string
    {
        $flow = '';
        $fixed = '';

        foreach ($blocks as $block) {
            if (($block['enabled'] ?? true) === false) {
                continue;
            }

            $type = $block['type'] ?? '';
            $settingsForBlock = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $html = $this->renderBlock($type, $settingsForBlock, $invoice, $settings);

            if (in_array($type, ['watermark', 'signature', 'footer'], true)) {
                $fixed .= $html;
            } else {
                $flow .= $html;
            }
        }

        return $fixed.'<div class="page-content">'.$flow.'</div>';
    }

    /**
     * @param  array<string, mixed>  $blockSettings
     */
    private function renderBlock(string $type, array $blockSettings, Invoice $invoice, CompanySetting $settings): string
    {
        $view = "invoices.blocks.{$type}";

        if (! view()->exists($view)) {
            return '';
        }

        return view($view, [
            'invoice' => $invoice,
            'settings' => $settings,
            'block' => $blockSettings,
        ])->render();
    }

    private function wrapDocument(Invoice $invoice, CompanySetting $settings, string $body, string $customCss): string
    {
        $title = e(($invoice->invoice_number ?? 'DRAFT').' - '.$settings->company_name);
        $baseCss = $this->baseCss();
        $extra = $customCss !== '' ? "\n".strip_tags($customCss) : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>
{$baseCss}
{$extra}
</style>
</head>
<body>
<div class="page">
{$body}
</div>
</body>
</html>
HTML;
    }

    private function baseCss(): string
    {
        $poppinsRegular = Fonts::dataUri('Poppins-Regular.ttf');
        $poppinsItalic = Fonts::dataUri('Poppins-Italic.ttf');
        $poppinsSemiBold = Fonts::dataUri('Poppins-SemiBold.ttf');
        $poppinsBold = Fonts::dataUri('Poppins-Bold.ttf');
        $poppinsExtraBold = Fonts::dataUri('Poppins-ExtraBold.ttf');
        $caveat = Fonts::dataUri('Caveat-SemiBold.ttf');

        return <<<CSS
    @font-face {
        font-family: 'Poppins';
        font-weight: 400;
        font-style: normal;
        src: url({$poppinsRegular}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 400;
        font-style: italic;
        src: url({$poppinsItalic}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 600;
        font-style: normal;
        src: url({$poppinsSemiBold}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 700;
        font-style: normal;
        src: url({$poppinsBold}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 800;
        font-style: normal;
        src: url({$poppinsExtraBold}) format('truetype');
    }
    @font-face {
        font-family: 'Caveat';
        font-weight: 600;
        font-style: normal;
        src: url({$caveat}) format('truetype');
    }

    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    html, body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        font-family: 'Poppins', Arial, sans-serif;
        color: #000000;
    }
    .page {
        position: relative;
        width: 210mm;
        margin: 0 auto;
        background: #ffffff;
    }
    .page-content {
        padding: 16mm 14mm 40mm;
    }
    .watermark {
        position: absolute;
        top: 20mm;
        left: 150mm;
        width: 46mm;
        height: 79mm;
        opacity: 1;
        z-index: 0;
    }
    table.header {
        width: 100%;
        border-collapse: collapse;
        position: relative;
        z-index: 1;
    }
    table.header td { vertical-align: top; padding: 0; }
    .header-right { text-align: right; }
    .logo img { height: 18mm; }
    .invoice-heading {
        font-family: 'Poppins', Arial, sans-serif;
        font-weight: 800;
        font-size: 32pt;
        color: #ABDAE7;
        letter-spacing: 1px;
        text-align: right;
        line-height: 1;
    }
    .invoice-date {
        text-align: right;
        font-weight: 700;
        font-size: 12pt;
        margin-top: 5mm;
        position: relative;
        z-index: 1;
    }
    hr.divider {
        border: none;
        border-top: 1.5px solid #132A38;
        margin: 5mm 0 7mm;
        position: relative;
        z-index: 1;
    }
    .quotation-to { position: relative; z-index: 1; }
    .quotation-to .label { font-weight: 700; font-size: 12.5pt; margin-bottom: 2mm; }
    .quotation-to .client-name { font-weight: 600; font-size: 11pt; margin-bottom: 1mm; }
    .quotation-to .client-address { color: #000000; font-size: 9.5pt; font-weight: 400; }
    .quotation-to .client-phone { color: #000000; font-size: 9.5pt; font-weight: 400; margin-top: 0.5mm; }

    .intro { margin-top: 7mm; position: relative; z-index: 1; }
    .intro h3 { margin: 0 0 2mm; font-size: 13pt; font-weight: 700; }
    .intro p { margin: 0; font-size: 9.5pt; font-weight: 400; color: #000000; line-height: 1.3; }

    .custom-text { margin-top: 5mm; position: relative; z-index: 1; font-size: 9.5pt; line-height: 1.4; }
    .spacer { height: 6mm; }

    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6mm;
        border: 1.5px solid #132A38;
        position: relative;
        z-index: 1;
    }
    table.items thead th.desc,
    table.items tbody td.desc {
        text-align: left;
        padding: 2.2mm 5mm;
        font-size: 10.5pt;
        font-weight: 600;
        line-height: 1.2;
        border-bottom: 1px solid #eee;
    }
    table.items thead th.desc {
        background: #67BCD4;
        color: #fff;
        font-size: 11pt;
        border-bottom: none;
    }
    table.items thead th.figures-wrap,
    table.items tbody td.figures-wrap {
        padding: 0;
        vertical-align: middle;
        width: 48mm;
        border-bottom: 1px solid #eee;
    }
    table.item-figures {
        width: 100%;
        border-collapse: collapse;
        border: none;
    }
    table.item-figures th,
    table.item-figures td {
        border: none;
        padding: 2.2mm 2mm;
        font-size: 10.5pt;
        font-weight: 600;
        line-height: 1.2;
    }
    table.item-figures thead th,
    table.item-figures th {
        background: #67BCD4;
        color: #fff;
        font-size: 11pt;
        font-weight: 600;
    }
    table.item-figures th.qty,
    table.item-figures td.qty {
        text-align: right;
        padding-right: 1mm;
        padding-left: 2mm;
    }
    table.item-figures th.amount,
    table.item-figures td.amount {
        text-align: right;
        padding-right: 5mm;
        padding-left: 1mm;
        font-weight: 700;
    }
    table.items tbody tr.discount td { font-style: italic; font-weight: 700; border-top: 1px solid #132A38; }

    .total-box {
        margin-top: 0;
        text-align: right;
    }
    .total-box .box {
        display: inline-block;
        background: #67BCD4;
        border: 1.5px solid #132A38;
        border-top: none;
        color: #000000;
        font-weight: 700;
        padding: 2.5mm 6mm;
        font-size: 12pt;
        line-height: 1.2;
    }

    .signature {
        position: fixed;
        bottom: 23mm;
        left: 0;
        width: 196mm;
        text-align: right;
    }
    .signature .sig-name {
        font-family: 'Caveat', cursive;
        font-weight: 600;
        font-size: 20pt;
        color: #000000;
        border-bottom: 1px solid #132A38;
        display: inline-block;
        padding: 0 4mm 1mm;
    }
    .signature .sig-label { font-weight: 600; font-size: 9pt; margin-top: 1mm; color: #284250; }
    .signature .sig-title { font-size: 9pt; color: #284250; font-weight: 400; }

    .footer-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 210mm;
        background: #67BCD4;
        color: #fff;
        text-align: center;
        font-weight: 700;
        font-size: 11pt;
        padding: 5mm 0;
    }
CSS;
    }

    /**
     * Safe {{placeholder}} replacement for HTML mode. PHP / Blade are never evaluated.
     */
    public function replacePlaceholders(string $html, Invoice $invoice, CompanySetting $settings): string
    {
        // Strip any accidental PHP / Blade tags for safety.
        $html = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $html) ?? $html;
        $html = preg_replace('/@\w+(\s*\([^)]*\))?/', '', $html) ?? $html;

        $map = [
            'company_name' => e($settings->company_name),
            'logo' => $settings->logoDataUriFor($invoice)
                ? '<img src="'.e($settings->logoDataUriFor($invoice)).'" alt="'.e($settings->company_name).'">'
                : '<strong>'.e($settings->company_name).'</strong>',
            'watermark' => '<img class="watermark" src="'.e(Assets::image('images/chakra-watermark.png')).'" alt="">',
            'invoice_number' => e($invoice->invoice_number ?? 'DRAFT'),
            'invoice_date' => e($invoice->invoice_date?->format('d/m/Y') ?? ''),
            'client_name' => e($invoice->client->name ?? ''),
            'client_address' => e($invoice->client->address ?? ''),
            'client_phone' => e($invoice->client->phone ?? ''),
            'intro_text' => e($invoice->intro_text ?? ''),
            'total' => e($this->formatMoney($invoice->total)),
            'subtotal' => e($this->formatMoney($invoice->subtotal)),
            'signature_name' => e($settings->signature_name),
            'signature_title' => e($settings->signature_title),
            'footer_text' => e($settings->footer_text),
            'items_table' => $this->renderBlock('items', [
                'items_label' => 'Items',
                'qty_label' => 'Qty',
                'rate_label' => 'Rate',
            ], $invoice, $settings),
        ];

        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($map) {
            $key = Str::lower($m[1]);

            return $map[$key] ?? $m[0];
        }, $html);
    }

    /**
     * Generate starter HTML from the current block layout (for HTML editor).
     *
     * @param  list<array{id?: string, type: string, enabled?: bool, settings?: array<string, mixed>}>  $blocks
     */
    public function blocksToEditableHtml(array $blocks, Invoice $invoice, CompanySetting $settings): string
    {
        return $this->renderBlocks($blocks, $invoice, $settings);
    }

    private function formatMoney(mixed $amount): string
    {
        $value = (float) $amount;

        return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    }
}
