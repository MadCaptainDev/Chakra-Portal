{{--
    One proposal inside the client portal: the same document and comment
    threads as the no-login p/{token} page (proposals/public.blade.php), so
    there is still one rendering of the design. $commentUrl and $signedIn
    reach the comment forms through @include's inherited scope -- they post
    here rather than to the token route, and never ask for a name.
--}}
@php
    $labels = $proposal->sectionLabels();
    $general = $threads
        ->filter(fn ($thread, $key) => $key === '' || ! array_key_exists($key, $labels))
        ->flatten(1);

    $commentUrl = route('client.proposals.comment', $proposal->id);
    $signedIn = true;
@endphp

<x-app-layout :title="$proposal->title" dark>
    @push('styles')
        @vite('resources/css/proposal.css')
    @endpush

    <div class="space-y-6">
        <div class="animate-rise-in flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('client.proposals') }}" class="inline-flex items-center gap-1 text-xs text-brand-100/60 hover:text-white">
                    <x-icon name="chevron-right" class="w-3.5 h-3.5 rotate-180" />
                    All proposals
                </a>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">{{ $proposal->title }}</h1>
                    <x-badge :status="$proposal->status" />
                </div>
                <p class="mt-1 text-sm text-brand-100/60">
                    Sent {{ ($proposal->token_issued_at ?? $proposal->updated_at)->format('j M Y') }}
                    @if ($proposal->valid_until) &middot; Valid until {{ $proposal->valid_until->format('j M Y') }} @endif
                    @if ($proposal->createdBy) &middot; Prepared by {{ $proposal->createdBy->name }} @endif
                </p>
                <p class="mt-1 text-sm text-brand-100/70">Read it through, and leave a comment on any section — questions, changes or ideas all welcome.</p>
            </div>
            <x-btn :href="route('client.proposals.pdf', $proposal->id)" variant="secondary" size="sm" class="shrink-0">Download PDF</x-btn>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-emerald-400/15 ring-1 ring-emerald-400/30 px-4 py-3 text-sm text-emerald-100" role="status">
                {{ session('status') }}
            </div>
        @endif

        <div class="-mx-4 sm:mx-0 overflow-x-auto">
            @include('proposals._document', [
                'proposal' => $proposal,
                'settings' => $settings,
                'mode' => 'public',
                'threads' => $threads,
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
    </div>
</x-app-layout>
