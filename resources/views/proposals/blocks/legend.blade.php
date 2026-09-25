{{-- "How to read this proposal": a titled key, one column per tag, each tag
     with its explanation directly under it -- so a note can never wrap away
     from the tag it explains. --}}
<div class="cp-legend">
    @if ($block['title'] !== '')
        <div class="cp-legend__title">{{ $block['title'] }}</div>
    @endif
    <div class="cp-legend__items" style="--cols: {{ max(1, min(3, count($block['items']))) }}">
        @foreach ($block['items'] as $item)
            <div class="cp-legend__item">
                <span class="cp-tag cp-tag--{{ $item['tag'] }}">{{ $item['label'] ?: \App\Support\ProposalBlocks::TAGS[$item['tag']] }}</span>
                @if ($item['note'] !== '')
                    <span>{{ $item['note'] }}</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
