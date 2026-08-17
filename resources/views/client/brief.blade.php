@php
    /*
     * The brand brief, as a signed-in client fills it.
     *
     * The wizard itself is shared with the public link -- see
     * brief/_wizard.blade.php. This page is only the chrome around it, which
     * is the part that differs: a portal client has a sidebar to go back to,
     * and may revise after submitting.
     */
@endphp

<x-app-layout title="Brand Brief" dark>
    <div class="space-y-6 pb-10">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-1.5 text-3xl sm:text-4xl font-extrabold tracking-tight">Brand brief</h1>
            <p class="mt-3 max-w-2xl text-sm sm:text-base text-brand-100/70 leading-relaxed">
                Help us understand your brand better so we can create content and marketing strategies that are right
                for your business. It takes about ten minutes, and you can stop and come back any time.
            </p>
        </div>

        @if ($brief->isSubmitted())
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 flex items-start gap-3">
                <x-icon name="check-circle" class="w-5 h-5 shrink-0 mt-0.5 text-brand-300" />
                <div class="text-sm text-brand-100/80">
                    Sent to us on {{ $brief->submitted_at?->format('j M Y') }}. Anything below can still be changed —
                    if your brand moves, we would rather know than keep writing from an old answer.
                </div>
            </div>
        @endif

        @include('brief._wizard', [
            'brief' => $brief,
            'saveUrl' => route('client.brief.update'),
            'submitUrl' => route('client.brief.submit'),
            'exitUrl' => route('client.dashboard'),
            'showName' => false,
            'submitLabel' => $brief->isSubmitted() ? 'Send changes' : 'Submit onboarding',
            'confirm' => $brief->isSubmitted()
                ? 'Send your updated answers to the studio?'
                : 'Send your brand brief to the studio?',
        ])
    </div>
</x-app-layout>
