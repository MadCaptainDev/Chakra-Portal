<div class="total-box">
    <div class="box">{{ $block['label'] ?? 'TOTAL :' }}&nbsp;&nbsp;{{ number_format($invoice->total, fmod((float) $invoice->total, 1.0) === 0.0 ? 0 : 2) }}/-</div>
</div>
