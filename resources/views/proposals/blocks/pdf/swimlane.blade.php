@php
    $laneColours = ['#3D8CA6', '#4B5563', '#132A38'];
    $cellStyles = [
        'background: #F2F9FC; color: #284250; border: 1px solid #ABDAE7;',
        'background: #FFFFFF; color: #1F2937; border: 1px solid #D1D5DB;',
        'background: #132A38; color: #FFFFFF; border: 1px solid #132A38;',
    ];
    $loopStyle = 'background: #FFFBEB; color: #92400E; border: 1px dashed #F59E0B;';
@endphp
<table class="grid" style="border-spacing: 4pt; font-size: 8pt;">
    <tr>
        <td style="width: 5%;"></td>
        @foreach ($block['lanes'] as $l => $lane)
            <td style="width: 31%; font-size: 7.5pt; letter-spacing: 1pt; text-transform: uppercase; font-weight: 600; color: {{ $laneColours[$l] }};">{{ $lane }}</td>
        @endforeach
    </tr>
    @foreach ($block['rows'] as $r => $row)
        <tr style="page-break-inside: avoid;">
            <td style="font-size: 7.5pt; font-weight: 600; color: #9CA3AF; padding-top: 4pt;">{{ str_pad($r + 1, 2, '0', STR_PAD_LEFT) }}</td>
            @foreach ($row as $c => $cell)
                @if ($cell['text'] === '')
                    <td style="text-align: center; color: #E5E7EB;">|</td>
                @else
                    <td style="border-radius: 5pt; padding: 4pt 6pt; line-height: 1.1; {{ $cell['loop'] ? $loopStyle : $cellStyles[$c] }}">{!! \App\Support\ProposalBlocks::inline($cell['text']) !!}</td>
                @endif
            @endforeach
        </tr>
    @endforeach
</table>

@if ($block['legend'])
    <p style="font-size: 8.5pt; color: #4B5563; margin-top: 6pt;">
        <span style="{{ $cellStyles[0] }} padding: 0 5pt; border-radius: 2pt;">&nbsp;</span> {{ $block['lanes'][0] ?: 'Customer' }} action &nbsp;&nbsp;
        <span style="{{ $cellStyles[1] }} padding: 0 5pt; border-radius: 2pt;">&nbsp;</span> Automated by {{ Str::lower($block['lanes'][1] ?: 'platform') }} &nbsp;&nbsp;
        <span style="{{ $cellStyles[2] }} padding: 0 5pt; border-radius: 2pt;">&nbsp;</span> Staff / department &nbsp;&nbsp;
        <span style="{{ $loopStyle }} padding: 0 5pt; border-radius: 2pt;">&nbsp;</span> Review or correction loop
    </p>
@endif
