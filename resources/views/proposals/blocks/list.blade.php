@if ($block['items'])
    <{{ $block['ordered'] ? 'ol' : 'ul' }} @class(['cp-boxed' => $block['boxed']])>
        @foreach ($block['items'] as $item)
            <li>{!! \App\Support\ProposalBlocks::inline($item) !!}</li>
        @endforeach
    </{{ $block['ordered'] ? 'ol' : 'ul' }}>
@endif
