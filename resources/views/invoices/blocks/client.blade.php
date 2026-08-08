<div class="quotation-to">
    <div class="label">{{ $block['label'] ?? 'Quotation to :' }}</div>
    <div class="client-name">{{ $invoice->client->name }}</div>
    @if ($invoice->client->address)
        <div class="client-address">{{ $invoice->client->address }}</div>
    @endif
    @if ($invoice->client->phone)
        <div class="client-phone">{{ $invoice->client->phone }}</div>
    @endif
</div>
