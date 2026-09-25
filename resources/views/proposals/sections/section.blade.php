@php
    $data = $section['data'];

    // The "How to read" key is not something to give feedback on: a
    // headless section holding nothing but a legend gets no Comment box.
    $commentable = $data['number'] !== '' || $data['title'] !== ''
        || collect($data['blocks'])->contains(fn ($block) => $block['type'] !== 'legend');
@endphp

<section class="cp-section" id="section-{{ $section['key'] }}">
    <div class="cp-section__head">
        {{-- No number and no title is a headless section -- the design's
             "How to read" legend sits above 01 that way. --}}
        @if ($data['number'] !== '' || $data['title'] !== '')
            <h2>@if ($data['number'] !== '')<span>{{ $data['number'] }}</span>@endif{{ $data['title'] }}</h2>
        @endif

        @if ($mode === 'admin' && $openCount > 0)
            <a href="#{{ $openAnchor }}" class="cp-ui cp-comment-btn cp-comment-btn--count"
               title="Open comments on this section">
                {{ $openCount }} open
            </a>
        @endif
    </div>

    @foreach ($data['blocks'] as $block)
        @include('proposals.blocks.'.$block['type'], ['block' => $block])
    @endforeach

    @if ($mode === 'public' && $commentable)
        @include('proposals.public._comments', [
            'sectionKey' => $section['key'],
            'thread' => $thread,
            'token' => $token,
            'label' => trim($data['number'].' '.$data['title']) ?: 'this part',
        ])
    @endif
</section>
