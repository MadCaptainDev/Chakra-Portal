@if ($invoice->intro_text)
    <div class="intro">
        <h3>{{ $block['heading'] ?? 'Dear Client' }}</h3>
        <p>{{ $invoice->intro_text }}</p>
    </div>
@endif
