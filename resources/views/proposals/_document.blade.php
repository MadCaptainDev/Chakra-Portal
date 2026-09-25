{{--
    The proposal as a reader sees it -- shared by the admin show page and the
    client's p/{token} page, so there is one rendering of the design.

    $mode: 'admin' shows each section's open-comment count, linking to the
    sidebar; 'public' shows the comment threads and a Comment box under each
    section. Anything interactive is .cp-ui, which never prints.
--}}
@php
    $mode ??= 'admin';
    $threads ??= collect();
    $commentCounts ??= [];
    $commentAnchors ??= [];
    $token ??= null;

    $sheets = \App\Support\ProposalBlocks::sheets($proposal->normalizedSections());
    $sheetCount = count($sheets);
    $footer = $proposal->footerText();
@endphp

<div class="cp-doc">
    @foreach ($sheets as $index => $sheet)
        @if ($sheet['cover'])
            @include('proposals.sections.cover', [
                'section' => $sheet['sections'][0],
                'proposal' => $proposal,
                'settings' => $settings,
            ])
        @else
            <article class="cp-sheet">
                <div class="cp-sheet__body">
                    @foreach ($sheet['sections'] as $section)
                        @include('proposals.sections.section', [
                            'section' => $section,
                            'mode' => $mode,
                            'thread' => $threads->get($section['key'], collect()),
                            'openCount' => $commentCounts[$section['key']] ?? 0,
                            'openAnchor' => $commentAnchors[$section['key']] ?? null,
                            'token' => $token,
                        ])
                    @endforeach
                </div>
                <footer class="cp-sheet__foot">
                    <span>{{ $footer }}</span>
                    <span>{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }} / {{ str_pad($sheetCount, 2, '0', STR_PAD_LEFT) }}</span>
                </footer>
            </article>
        @endif
    @endforeach
</div>
