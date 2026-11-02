@php
    // Whole numbers without decimals, anything else to two places.
    $fmt = fn ($n) => number_format((float) $n, fmod((float) $n, 1.0) === 0.0 ? 0 : 2);
@endphp
<table class="items" width="100%" cellspacing="0" cellpadding="0">
    <thead>
        <tr>
            <th class="desc">{{ $block['items_label'] ?? 'Items' }}</th>
            <th class="figures-wrap" width="72mm">
                <table class="item-figures" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <th class="qty" width="22%">{{ $block['qty_label'] ?? 'Qty' }}</th>
                        <th class="rate" width="36%">{{ $block['rate_label'] ?? 'Rate' }}</th>
                        <th class="amount" width="42%">{{ $block['amount_label'] ?? 'Amount' }}</th>
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
                            <td class="qty" width="22%">{{ $fmt($item->quantity) }}</td>
                            <td class="rate" width="36%">{{ $fmt($item->unit_price) }}</td>
                            <td class="amount" width="42%">{{ $fmt($item->line_total) }}</td>
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
                            <td class="rate" width="36%"></td>
                            <td class="amount" width="42%">- {{ $fmt($invoice->discount_amount) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif
    </tbody>
</table>
