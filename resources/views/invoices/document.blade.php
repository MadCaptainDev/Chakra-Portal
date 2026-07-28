<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $invoice->invoice_number }} - {{ $settings->company_name }}</title>
<style>
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    html, body {
        margin: 0;
        padding: 0;
        background: #e8e8e8;
        font-family: Arial, Helvetica, sans-serif;
        color: #222;
    }
    .page {
        position: relative;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        background: #ffffff;
        padding: 16mm 14mm;
        overflow: hidden;
    }
    .watermark {
        position: absolute;
        top: 18mm;
        right: 8mm;
        width: 60mm;
        height: 60mm;
        opacity: 0.08;
        z-index: 0;
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        position: relative;
        z-index: 1;
    }
    .logo img { height: 20mm; }
    .invoice-heading {
        font-size: 34pt;
        font-weight: 800;
        color: #A9DCE9;
        letter-spacing: 2px;
        text-align: right;
    }
    .invoice-date {
        text-align: right;
        font-weight: bold;
        margin-top: 4mm;
    }
    hr.divider {
        border: none;
        border-top: 2px solid #222;
        margin: 6mm 0 8mm;
        position: relative;
        z-index: 1;
    }
    .quotation-to { position: relative; z-index: 1; }
    .quotation-to .label { font-weight: bold; margin-bottom: 2mm; }
    .quotation-to .client-name { font-weight: bold; }
    .quotation-to .client-address { color: #444; font-size: 10pt; }

    .intro { margin-top: 8mm; position: relative; z-index: 1; }
    .intro h3 { margin: 0 0 2mm; }
    .intro p { margin: 0; font-size: 10pt; color: #333; line-height: 1.5; }

    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8mm;
        border: 1px solid #222;
        position: relative;
        z-index: 1;
    }
    table.items thead th {
        background: #5EB3C7;
        color: #fff;
        text-align: left;
        padding: 3mm 4mm;
        font-size: 10pt;
    }
    table.items thead th.amount { text-align: right; }
    table.items tbody td {
        padding: 3mm 4mm;
        font-size: 10pt;
        border-bottom: 1px solid #eee;
    }
    table.items tbody td.amount { text-align: right; font-weight: bold; }
    table.items tbody tr.discount td { font-style: italic; border-top: 1px solid #222; }

    .total-box {
        margin-top: 4mm;
        display: flex;
        justify-content: flex-end;
    }
    .total-box .box {
        background: #5EB3C7;
        border: 1px solid #222;
        color: #10333c;
        font-weight: bold;
        padding: 3mm 6mm;
        font-size: 12pt;
    }

    .signature { margin-top: 30mm; text-align: right; position: relative; z-index: 1; }
    .signature .sig-name {
        font-family: 'Brush Script MT', cursive;
        font-style: italic;
        font-size: 16pt;
        border-bottom: 1px solid #222;
        display: inline-block;
        padding: 0 4mm 1mm;
    }
    .signature .sig-label { font-weight: bold; font-size: 9pt; margin-top: 1mm; }
    .signature .sig-title { font-size: 9pt; color: #555; }

    .footer-bar {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        background: #5EB3C7;
        color: #fff;
        text-align: center;
        font-weight: bold;
        padding: 5mm 0;
    }
</style>
</head>
<body>
<div class="page">
    <svg class="watermark" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
        <circle cx="60" cy="55" r="38" fill="#999" />
        <circle cx="140" cy="55" r="38" fill="#999" />
        <rect x="40" y="60" width="120" height="70" rx="6" fill="#999" />
        <polygon points="160,80 195,65 195,110 160,95" fill="#999" />
        <rect x="30" y="130" width="10" height="55" fill="#999" />
        <rect x="150" y="130" width="10" height="55" fill="#999" />
        <rect x="10" y="180" width="180" height="8" fill="#999" />
    </svg>

    <div class="header">
        <div class="logo">
            @if ($settings->logo_data_uri)
                <img src="{{ $settings->logo_data_uri }}" alt="{{ $settings->company_name }}">
            @else
                <strong>{{ $settings->company_name }}</strong>
            @endif
        </div>
        <div>
            <div class="invoice-heading">INVOICE</div>
            <div class="invoice-date">{{ $invoice->invoice_date->format('d/m/Y') }}</div>
        </div>
    </div>

    <hr class="divider">

    <div class="quotation-to">
        <div class="label">Quotation to :</div>
        <div class="client-name">{{ $invoice->client->name }}</div>
        @if ($invoice->client->address)
            <div class="client-address">{{ $invoice->client->address }}</div>
        @endif
    </div>

    @if ($invoice->intro_text)
        <div class="intro">
            <h3>Dear Client</h3>
            <p>{{ $invoice->intro_text }}</p>
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align:right;">Qty</th>
                <th style="text-align:right;">Unit Price</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td style="text-align:right;">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                    <td style="text-align:right;">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="amount">{{ number_format($item->line_total, 2) }}</td>
                </tr>
            @endforeach
            @if ($invoice->discount_label)
                <tr class="discount">
                    <td colspan="3">{{ $invoice->discount_label }}</td>
                    <td class="amount">- {{ number_format($invoice->discount_amount, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="total-box">
        <div class="box">TOTAL : {{ number_format($invoice->total, 2) }}/-</div>
    </div>

    <div class="signature">
        <div class="sig-name">{{ $settings->signature_name }}</div>
        <div class="sig-label">Name &amp; Signature</div>
        <div class="sig-title">{{ $settings->signature_title }}</div>
    </div>

    <div class="footer-bar">{{ $settings->footer_text }}</div>
</div>
</body>
</html>
