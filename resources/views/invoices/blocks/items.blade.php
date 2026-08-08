<table class="items" width="100%" cellspacing="0" cellpadding="0">
    <thead>
        <tr>
            <th class="desc">{{ $block['items_label'] ?? 'Items' }}</th>
            <th class="figures-wrap" width="48mm">
                <table class="item-figures" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <th class="qty" width="40%">{{ $block['qty_label'] ?? 'Qty' }}</th>
                        <th class="amount" width="60%">{{ $block['rate_label'] ?? 'Rate' }}</th>
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
                            <td class="qty" width="40%">{{ number_format($item->quantity, fmod((float) $item->quantity, 1.0) === 0.0 ? 0 : 2) }}</td>
                            <td class="amount" width="60%">{{ number_format($item->line_total, fmod((float) $item->line_total, 1.0) === 0.0 ? 0 : 2) }}</td>
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
                            <td class="qty" width="40%"></td>
                            <td class="amount" width="60%">- {{ number_format($invoice->discount_amount, fmod((float) $invoice->discount_amount, 1.0) === 0.0 ? 0 : 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif
    </tbody>
</table>
