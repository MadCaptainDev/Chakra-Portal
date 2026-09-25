@foreach (\App\Support\ProposalBlocks::paragraphs($block['text']) as $paragraph)
    <p @class(['cp-muted' => $block['tone'] === 'muted', 'cp-fine' => $block['tone'] === 'fine'])>{!! \App\Support\ProposalBlocks::inline($paragraph) !!}</p>
@endforeach
