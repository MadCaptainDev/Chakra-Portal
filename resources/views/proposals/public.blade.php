{{--
    The proposal on its no-login link, p/{token}. The same document partials
    as the admin show page, plus a comment box under every section and a
    general "Ideas / feedback" box at the end.
--}}
@php
    $labels = $proposal->sectionLabels();
    // General comments, plus any left on a section that has since been
    // removed -- still worth showing, just no longer under a heading.
    $general = $threads
        ->filter(fn ($thread, $key) => $key === '' || ! array_key_exists($key, $labels))
        ->flatten(1);
@endphp

<x-public-layout :title="$proposal->title.' — Proposal'" description="A proposal prepared for you by Chakra Productions.">
    @push('styles')
        @vite('resources/css/proposal.css')
        <meta name="robots" content="noindex, nofollow">
    @endpush

    <div class="bg-brand-900 py-8 sm:py-12 px-4">
        <div class="max-w-[794px] mx-auto mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.25em] text-brand-300">Proposal</p>
                <p class="text-lg font-semibold text-white">{{ $proposal->title }}</p>
                <p class="text-sm text-brand-100/70">Read it through, and leave a comment on any section — questions, changes or ideas all welcome.</p>
            </div>
            <a href="{{ route('proposals.public-pdf', $token) }}"
               class="inline-flex items-center justify-center min-h-[44px] px-5 rounded-md bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors shrink-0">
                Download PDF
            </a>
        </div>

        @if (session('status'))
            <div class="max-w-[794px] mx-auto mb-6 rounded-lg bg-emerald-400/15 ring-1 ring-emerald-400/30 px-4 py-3 text-sm text-emerald-100" role="status">
                {{ session('status') }}
            </div>
        @endif

        @include('proposals._document', [
            'proposal' => $proposal,
            'settings' => $settings,
            'mode' => 'public',
            'threads' => $threads,
            'token' => $token,
        ])

        <div class="cp-doc mt-6">
            <section class="cp-sheet cp-ui" id="feedback" style="min-height: 0;"
                     x-data="proposalComment(true)">
                <h2>Ideas / feedback</h2>
                <p class="cp-muted">Anything that is not about one section — a question, an idea, something we missed. It goes straight to the team working on your proposal.</p>

                @if ($general->isNotEmpty())
                    <div class="cp-threads" style="margin-bottom: 12px;">
                        @foreach ($general as $comment)
                            @include('proposals.public._thread', ['comment' => $comment])
                        @endforeach
                    </div>
                @endif

                @include('proposals.public._form', [
                    'formId' => 'general',
                    'sectionKey' => '',
                    'parentId' => null,
                    'alwaysOpen' => true,
                    'placeholder' => 'Share an idea or ask a question…',
                ])
            </section>
        </div>
    </div>
</x-public-layout>
