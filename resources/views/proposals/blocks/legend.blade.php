<div class="cp-legend">
    @if ($block['title'] !== '')
        <strong>{{ $block['title'] }}</strong>
    @endif
    @foreach ($block['items'] as $item)
        <span class="cp-tag cp-tag--{{ $item['tag'] }}">{{ $item['label'] ?: \App\Support\ProposalBlocks::TAGS[$item['tag']] }}</span>
        @if ($item['note'] !== '')
            <span>{{ $item['note'] }}</span>
        @endif
    @endforeach
</div>
