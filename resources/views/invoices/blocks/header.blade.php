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
            <div class="invoice-heading">{{ $block['title'] ?? 'INVOICE' }}</div>
            <div class="invoice-date">{{ $invoice->invoice_date->format('d/m/Y') }}</div>
        </td>
    </tr>
</table>
