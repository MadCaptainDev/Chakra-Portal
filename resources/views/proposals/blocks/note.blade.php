<div class="cp-note">
    <span class="cp-tag cp-tag--{{ $block['tag'] }}">{{ \App\Support\ProposalBlocks::TAGS[$block['tag']] }}</span>
    <span>{!! \App\Support\ProposalBlocks::inline($block['text']) !!}</span>
</div>
