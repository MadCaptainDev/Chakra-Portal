<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $quotation->quotation_number ?? 'DRAFT' }} - {{ $settings->company_name }}</title>
<style>
    @font-face {
        font-family: 'Poppins';
        font-weight: 400;
        font-style: normal;
        src: url({{ \App\Support\Fonts::dataUri('Poppins-Regular.ttf') }}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 400;
        font-style: italic;
        src: url({{ \App\Support\Fonts::dataUri('Poppins-Italic.ttf') }}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 600;
        font-style: normal;
        src: url({{ \App\Support\Fonts::dataUri('Poppins-SemiBold.ttf') }}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 700;
        font-style: normal;
        src: url({{ \App\Support\Fonts::dataUri('Poppins-Bold.ttf') }}) format('truetype');
    }
    @font-face {
        font-family: 'Poppins';
        font-weight: 800;
        font-style: normal;
        src: url({{ \App\Support\Fonts::dataUri('Poppins-ExtraBold.ttf') }}) format('truetype');
    }
    @font-face {
        font-family: 'Caveat';
        font-weight: 600;
        font-style: normal;
        src: url({{ \App\Support\Fonts::dataUri('Caveat-SemiBold.ttf') }}) format('truetype');
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
    /* See invoices/document.blade.php for why this padding must not shrink:
       it reserves the strip the fixed signature + footer bar occupy. */
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
    .doc-heading {
        font-family: 'Poppins', Arial, sans-serif;
        font-weight: 800;
        font-size: 32pt;
        color: #ABDAE7;
        letter-spacing: 1px;
        text-align: right;
        line-height: 1;
    }
    .doc-date {
        text-align: right;
        font-weight: 700;
        font-size: 12pt;
        margin-top: 5mm;
        position: relative;
        z-index: 1;
    }
    .doc-valid {
        text-align: right;
        font-weight: 400;
        font-size: 9.5pt;
        margin-top: 1.5mm;
        position: relative;
        z-index: 1;
        color: #284250;
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
        width: 72mm;
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
    table.item-figures th.rate,
    table.item-figures td.rate {
        text-align: right;
        padding-right: 2mm;
        padding-left: 1mm;
        font-weight: 400;
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

    .points {
        margin-top: 6mm;
        position: relative;
        z-index: 1;
    }
    .points ul { margin: 0; padding: 0; list-style: none; }
    .points li {
        position: relative;
        padding-left: 4mm;
        font-size: 9.5pt;
        font-weight: 400;
        color: #000000;
        line-height: 1.4;
        margin-bottom: 1.2mm;
    }
    .points li:before {
        content: '';
        position: absolute;
        left: 0;
        top: 1.6mm;
        width: 1.6mm;
        height: 1.6mm;
        border-radius: 50%;
        background: #67BCD4;
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
</style>
</head>
<body>
<div class="page">
    <img class="watermark" src="{{ \App\Support\Assets::image('images/chakra-watermark.png') }}" alt="">

    <div class="page-content">
    @php
        // App Studio's own mark for an App Studio quotation, falling back
        // to the ordinary logo if that one has never been uploaded -- same
        // fallback as CompanySetting::logoDataUriFor(Invoice), just without
        // a saas_product_id to key off (a quotation carries none yet).
        $logo = ($quotation->is_app_studio && $settings->app_studio_logo_data_uri)
            ? $settings->app_studio_logo_data_uri
            : $settings->logo_data_uri;
    @endphp
    <table class="header">
        <tr>
            <td class="logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $settings->company_name }}">
                @else
                    <strong>{{ $settings->company_name }}</strong>
                @endif
            </td>
            <td class="header-right">
                <div class="doc-heading">QUOTATION</div>
                <div class="doc-date">{{ $quotation->quotation_date->format('d/m/Y') }}</div>
                @if ($quotation->valid_until)
                    <div class="doc-valid">Valid until {{ $quotation->valid_until->format('d/m/Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <hr class="divider">

    <div class="quotation-to">
        <div class="label">Quoted to :</div>
        <div class="client-name">{{ $quotation->client->name }}</div>
        @if ($quotation->client->address)
            <div class="client-address">{{ $quotation->client->address }}</div>
        @endif
        @if ($quotation->client->phone)
            <div class="client-phone">{{ $quotation->client->phone }}</div>
        @endif
    </div>

    @if ($quotation->intro_text)
        <div class="intro">
            <h3>Dear Client</h3>
            <p>{{ $quotation->intro_text }}</p>
        </div>
    @endif

    <table class="items" width="100%" cellspacing="0" cellpadding="0">
        <thead>
            <tr>
                <th class="desc">Items</th>
                <th class="figures-wrap" width="72mm">
                    <table class="item-figures" width="100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <th class="qty" width="22%">Qty</th>
                            <th class="rate" width="39%">Rate</th>
                            <th class="amount" width="39%">Amount</th>
                        </tr>
                    </table>
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quotation->items as $item)
                <tr>
                    <td class="desc">{{ $item->description }}</td>
                    <td class="figures-wrap">
                        <table class="item-figures" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td class="qty" width="22%">{{ number_format($item->quantity, fmod((float) $item->quantity, 1.0) === 0.0 ? 0 : 2) }}</td>
                                <td class="rate" width="39%">{{ number_format($item->unit_price, fmod((float) $item->unit_price, 1.0) === 0.0 ? 0 : 2) }}</td>
                                <td class="amount" width="39%">{{ number_format($item->line_total, fmod((float) $item->line_total, 1.0) === 0.0 ? 0 : 2) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @endforeach
            @if ($quotation->discount_label)
                <tr class="discount">
                    <td class="desc">{{ $quotation->discount_label }}</td>
                    <td class="figures-wrap">
                        <table class="item-figures" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td class="qty" width="22%"></td>
                                <td class="rate" width="39%"></td>
                                <td class="amount" width="39%">- {{ number_format($quotation->discount_amount, fmod((float) $quotation->discount_amount, 1.0) === 0.0 ? 0 : 2) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="total-box">
        <div class="box">TOTAL :&nbsp;&nbsp;{{ number_format($quotation->total, fmod((float) $quotation->total, 1.0) === 0.0 ? 0 : 2) }}/-</div>
    </div>

    @if ($quotation->notes)
        <div class="points">
            <ul>
                @foreach (preg_split('/\r\n|\r|\n/', $quotation->notes) as $point)
                    @continue(trim($point) === '')
                    <li>{{ trim($point) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="signature">
        <div class="sig-name">{{ $settings->signature_name }}</div>
        <div class="sig-label">Name &amp; Signature</div>
        <div class="sig-title">{{ $settings->signature_title }}</div>
    </div>

    </div>
    <div class="footer-bar">{{ $settings->footer_text }}</div>
</div>
</body>
</html>
