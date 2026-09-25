@if ($block['tone'] === 'line')
    <div class="box keep" style="font-weight: 600; color: #111827; margin: 6pt 0 9pt;">{{ implode(' → ', $block['steps']) }}</div>
@else
    @php
        $cols = $block['columns'] ?: max(1, count($block['steps']));
        $width = floor(100 / $cols);
    @endphp
    <table class="grid keep" style="border-spacing: 4pt;">
        @foreach (array_chunk($block['steps'], $cols, true) as $row)
            <tr>
                @foreach ($row as $i => $step)
                    <td style="width: {{ $width }}%; {{ $block['numbered'] ? 'text-align: left;' : '' }}"
                        class="chip {{ $block['tone'] === 'dark' ? 'dark' : 'light' }}">
                        @if ($block['numbered'])<div style="color: #4FA9C4;">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>@endif
                        {{ $step }}
                    </td>
                @endforeach
                @for ($pad = count($row); $pad < $cols; $pad++)
                    <td style="width: {{ $width }}%;"></td>
                @endfor
            </tr>
        @endforeach
    </table>
@endif
