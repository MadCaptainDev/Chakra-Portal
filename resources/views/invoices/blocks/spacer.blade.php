@php
    $mm = max(2, min(40, (int) ($block['height_mm'] ?? 6)));
@endphp
<div class="spacer" style="height: {{ $mm }}mm;"></div>
