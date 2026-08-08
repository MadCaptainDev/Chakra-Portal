@php
    $content = $block['content'] ?? '';
@endphp
@if ($content !== '')
    <div class="custom-text">{!! nl2br(e($content)) !!}</div>
@endif
