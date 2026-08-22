<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $invoice->invoice_number ?? 'DRAFT' }} - {{ $settings->company_name }}</title>
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
        /* White, not a grey "desk" colour: the sheet is the whole page here, so
           any colour outside .page shows up as a band under the footer. */
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
    /* Bottom padding reserves the strip taken by the two fixed elements at the
       foot of the page: the footer bar (~17.7mm) and the signature block above
       it (ends ~38mm up). Do not raise this casually -- every extra mm here
       pushes a long invoice onto a second page. Do NOT put a height/min-height
       on .page either; in dompdf that rounds over the A4 box and emits a blank
       second page. */
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

    /* No gap: the total box hangs directly off the bottom edge of the items
       table and drops its own top border so the two read as one shape. */
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

    /* Anchored to the foot of the page rather than trailing the items, so the
       signature always sits just above the footer bar no matter how many line
       items there are. `right` is unreliable in dompdf, hence left + width +
       text-align. Taken out of the flow, so .page-content's bottom padding
       reserves the strip it occupies. */
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

    /* Pinned to the foot of the paper. dompdf resolves position:fixed against
       the page box, so this sits flush on the bottom edge (and repeats if the
       invoice ever runs to a second page) instead of trailing the content and
       leaving dead space beneath it. */
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
    <table class="header">
        <tr>
            <td class="logo">
                @if ($settings->logo_data_uri)
                    <img src="{{ $settings->logo_data_uri }}" alt="{{ $settings->company_name }}">
                @else
                    <strong>{{ $settings->company_name }}</strong>
                @endif
            </td>
            <td class="header-right">
                <div class="invoice-heading">INVOICE</div>
                <div class="invoice-date">{{ $invoice->invoice_date->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <hr class="divider">

    <div class="quotation-to">
        <div class="label">Quotation to :</div>
        <div class="client-name">{{ $invoice->client->name }}</div>
        @if ($invoice->client->address)
            <div class="client-address">{{ $invoice->client->address }}</div>
        @endif
        @if ($invoice->client->phone)
            <div class="client-phone">{{ $invoice->client->phone }}</div>
        @endif
    </div>

    @if ($invoice->intro_text)
        <div class="intro">
            <h3>Dear Client</h3>
            <p>{{ $invoice->intro_text }}</p>
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
            @foreach ($invoice->items as $item)
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
            @if ($invoice->discount_label)
                <tr class="discount">
                    <td class="desc">{{ $invoice->discount_label }}</td>
                    <td class="figures-wrap">
                        <table class="item-figures" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td class="qty" width="22%"></td>
                                <td class="rate" width="39%"></td>
                                <td class="amount" width="39%">- {{ number_format($invoice->discount_amount, fmod((float) $invoice->discount_amount, 1.0) === 0.0 ? 0 : 2) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="total-box">
        <div class="box">TOTAL :&nbsp;&nbsp;{{ number_format($invoice->total, fmod((float) $invoice->total, 1.0) === 0.0 ? 0 : 2) }}/-</div>
    </div>

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
