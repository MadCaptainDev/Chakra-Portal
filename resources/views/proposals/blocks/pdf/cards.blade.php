@php
    $cols = max(1, $block['columns']);
    $width = floor(100 / $cols);
@endphp
<table class="grid keep" style="width: 100%;">
    @foreach (array_chunk($block['items'], $cols) as $row)
        <tr>
            @foreach ($row as $item)
                <td style="width: {{ $width }}%;" class="box">
                    @if ($block['variant'] === 'stat')
                        <div style="font-size: 20pt; font-weight: 700; color: #3D8CA6; line-height: 1.3;">{{ $item['label'] }}</div>
                        <div style="font-size: 9pt;">{!! \App\Support\ProposalBlocks::inline($item['text']) !!}</div>
                    @elseif ($block['variant'] === 'labelled')
                        @if ($item['label'] !== '')<div class="label">{{ $item['label'] }}</div>@endif
                        <div style="color: #111827; font-weight: 600;">{!! \App\Support\ProposalBlocks::inline($item['text']) !!}</div>
                    @else
                        <div style="font-size: 9pt;">{!! \App\Support\ProposalBlocks::inline($item['label'] !== '' ? $item['label'].' — '.$item['text'] : $item['text']) !!}</div>
                    @endif
                </td>
            @endforeach
            @for ($pad = count($row); $pad < $cols; $pad++)
                <td style="width: {{ $width }}%;"></td>
            @endfor
        </tr>
    @endforeach
</table>
