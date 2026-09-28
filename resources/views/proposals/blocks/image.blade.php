@if ($block['path'] !== '')
    <figure class="cp-picture cp-picture--{{ $block['size'] }}">
        <img src="{{ asset($block['path']) }}" alt="{{ $block['caption'] }}" loading="lazy">
        @if ($block['caption'] !== '')
            <figcaption>{!! \App\Support\ProposalBlocks::inline($block['caption']) !!}</figcaption>
        @endif
    </figure>
@endif
