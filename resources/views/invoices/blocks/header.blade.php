<table class="header">
    <tr>
        <td class="logo">
            @php $logo = $settings->logoDataUriFor($invoice); @endphp
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $settings->company_name }}">
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
