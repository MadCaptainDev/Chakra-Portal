<div class="cp-grid cp-grid--{{ $block['variant'] }} @if ($block['columns'] === 1) cp-grid--c1 @endif" style="--cols: {{ $block['columns'] }}">
    @foreach ($block['items'] as $item)
        <div class="cp-card">
            @if ($block['variant'] === 'stat')
                <div class="cp-stat__value">{{ $item['label'] }}</div>
                <div class="cp-stat__text">{!! \App\Support\ProposalBlocks::inline($item['text']) !!}</div>
            @else
                @if ($block['variant'] === 'labelled' && $item['label'] !== '')
                    <div class="cp-card__label">{{ $item['label'] }}</div>
                @endif
                <div class="cp-card__text">{!! \App\Support\ProposalBlocks::inline($block['variant'] === 'plain' && $item['label'] !== '' ? $item['label'].' — '.$item['text'] : $item['text']) !!}</div>
            @endif
        </div>
    @endforeach
</div>
