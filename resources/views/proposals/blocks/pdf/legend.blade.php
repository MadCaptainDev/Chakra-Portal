<div class="box keep" style="font-size: 8.5pt; margin-bottom: 14pt; line-height: 2;">
    @if ($block['title'] !== '')<strong>{{ $block['title'] }}</strong>&nbsp;&nbsp;@endif
    @foreach ($block['items'] as $item)
        <span class="tag tag-{{ $item['tag'] }}">{{ $item['label'] ?: \App\Support\ProposalBlocks::TAGS[$item['tag']] }}</span>
        @if ($item['note'] !== '')&nbsp;{{ $item['note'] }}&nbsp;&nbsp;&nbsp;@endif
    @endforeach
</div>
