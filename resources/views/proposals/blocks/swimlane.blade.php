<div class="cp-swim-wrap">
    <div class="cp-swim">
        <div></div>
        @foreach ($block['lanes'] as $l => $lane)
            <div class="cp-swim__lane cp-swim__lane--{{ $l }}">{{ $lane }}</div>
        @endforeach

        @foreach ($block['rows'] as $r => $row)
            <div class="cp-swim__num">{{ str_pad($r + 1, 2, '0', STR_PAD_LEFT) }}</div>
            @foreach ($row as $c => $cell)
                @if ($cell['text'] === '')
                    <div class="cp-swim__gap"></div>
                @else
                    <div class="cp-swim__cell {{ $cell['loop'] ? 'cp-swim__cell--loop' : 'cp-swim__cell--'.$c }}">{!! \App\Support\ProposalBlocks::inline($cell['text']) !!}</div>
                @endif
            @endforeach
        @endforeach
    </div>
</div>

@if ($block['legend'])
    <div class="cp-swim-legend">
        <span><i class="cp-swim__cell--0"></i>{{ $block['lanes'][0] ?: 'Customer' }} action</span>
        <span><i class="cp-swim__cell--1"></i>Automated by {{ Str::lower($block['lanes'][1] ?: 'platform') }}</span>
        <span><i class="cp-swim__cell--2"></i>Staff / department</span>
        <span><i class="cp-swim__cell--loop"></i>Review or correction loop</span>
    </div>
@endif
