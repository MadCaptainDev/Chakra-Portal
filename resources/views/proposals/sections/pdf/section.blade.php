<div @class(['section', 'newpage' => $breakBefore])>
    @if ($data['number'] !== '' || $data['title'] !== '')
        <h2>@if ($data['number'] !== '')<span class="n">{{ $data['number'] }}</span>@endif{{ $data['title'] }}</h2>
    @endif

    @foreach ($data['blocks'] as $block)
        {{-- A PDF-specific partial only where the web one leans on flex or
             grid; the plain ones (paragraph, list, table, ...) are shared. --}}
        @include(view()->exists('proposals.blocks.pdf.'.$block['type'])
            ? 'proposals.blocks.pdf.'.$block['type']
            : 'proposals.blocks.'.$block['type'], ['block' => $block])
    @endforeach
</div>
