@php
    use App\Support\BrandBrief;

    /*
     * The brand brief as a client with no login fills it.
     *
     * Deliberately the same page as client/brief.blade.php rather than a
     * prettier standalone one: the sections partial is shared, so a question
     * added to BrandBrief appears on both without anybody remembering the
     * second. What differs is only what the absence of a login changes --
     * the studio has to introduce itself, the submit is final, and there is
     * no sidebar to escape to.
     *
     * Standalone chrome rather than x-public-layout: that layout's header
     * carries a nav back to the marketing site and a "Start a project" button,
     * and both are wrong on a page somebody is ten minutes into filling.
     */
    $label = 'block text-sm font-semibold text-white';
    $hint = 'mt-1 text-xs text-brand-100/60 leading-snug';
    $field = 'mt-2 block w-full rounded-lg border-0 bg-white/10 px-3.5 py-2.5 text-sm text-white placeholder-brand-100/40 ring-1 ring-inset ring-white/15 focus:ring-2 focus:ring-inset focus:ring-brand-400';

    $answered = $brief->exists ? $brief->requiredAnswered() : 0;
    $total = $brief->requiredTotal();
    $complete = $answered >= $total;
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brand Brief — {{ $client->name }}</title>

    {{-- A private link. Keeping it out of search results matters more here
         than anywhere else on the site: the answers include pricing position
         and what makes their customers hesitate. --}}
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white">

<div x-data="{ dirty: false, confirming: false }"
     @change="dirty = true" @input="dirty = true"
     x-init="window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } })"
     class="mx-auto max-w-5xl px-4 sm:px-6 py-10 sm:py-14 space-y-6 pb-36">

    <header>
        <x-application-logo class="h-8 w-auto mb-6" />
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
        <h1 class="mt-1.5 text-3xl sm:text-4xl font-extrabold tracking-tight">Before we write for you</h1>
        <p class="mt-3 max-w-2xl text-sm sm:text-base text-brand-100/70 leading-relaxed">
            A few questions about your brand, so every script we write sounds like you rather than like everyone
            else. About ten minutes. You can save and come back to this link — it stays open until you send it in.
        </p>
    </header>

    @if (session('status'))
        <div class="rounded-xl bg-brand-400/15 ring-1 ring-brand-400/25 px-4 py-3 text-sm text-brand-100" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl bg-red-500/10 ring-1 ring-red-400/30 px-4 py-3 text-sm text-red-200">
            Some answers still need attention — they are marked below.
        </div>
    @endif

    <form method="POST" action="{{ route('brief.public.update', $token) }}" class="space-y-4">
        @csrf

        @foreach (BrandBrief::sections() as $key => $section)
            @include('client._brief-section', [
                'key' => $key,
                'section' => $section,
                'questions' => BrandBrief::questionsFor($key),
                'brief' => $brief,
                'options' => $options,
                'label' => $label,
                'hint' => $hint,
                'field' => $field,
            ])
        @endforeach

        {{-- Asked last, because a name field at the top is the one most likely
             to make somebody close the tab -- and because by here they have
             already decided to send it. --}}
        <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-5">
            <label for="submitted_name" class="{{ $label }}">Who is filling this in?</label>
            <p class="{{ $hint }}">So we know who to come back to with questions.</p>
            <input type="text" id="submitted_name" name="submitted_name" class="{{ $field }}"
                   value="{{ old('submitted_name') }}" placeholder="Your name" autocomplete="name">
        </div>

        {{-- Sticky: the count answers "how much is left" without scrolling. --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-white/10 bg-brand-900/95 backdrop-blur">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 py-3.5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-semibold tabular-nums min-w-0">
                    {{ $answered }} of {{ $total }} answered
                    @unless ($complete)
                        <span class="font-normal text-brand-100/60">— the ones marked *</span>
                    @endunless
                </p>

                <div class="flex items-center gap-2.5">
                    <button type="submit" @click="dirty = false"
                            class="rounded-lg bg-white/10 px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-white ring-1 ring-white/15 hover:bg-white/15 transition-colors">
                        Save for later
                    </button>

                    {{-- Opens the confirm rather than submitting, because this
                         one is final and a mis-tap on a phone should not be. --}}
                    <button type="button" @click="confirming = true"
                            @disabled(! $complete)
                            title="{{ $complete ? 'Send this to the studio' : 'Answer the '.($total - $answered).' remaining starred questions first' }}"
                            @class([
                                'rounded-lg px-5 py-2.5 text-xs font-semibold uppercase tracking-widest transition-colors',
                                'bg-brand-400 text-brand-900 hover:bg-brand-300' => $complete,
                                'bg-white/10 text-brand-100/40 cursor-not-allowed' => ! $complete,
                            ])>
                        Send to the studio
                    </button>
                </div>
            </div>
        </div>

        {{-- The consequence stated plainly, and the buttons labelled with what
             they do rather than OK/Cancel. This is the only irreversible thing
             on the page. --}}
        <div x-show="confirming" x-cloak class="fixed inset-0 z-40 flex items-end sm:items-center justify-center p-4 bg-black/60"
             @click.self="confirming = false" @keydown.escape.window="confirming = false">
            <div class="w-full max-w-md rounded-2xl bg-brand-800 ring-1 ring-white/10 p-6">
                <h2 class="text-lg font-bold">Send your brand brief?</h2>
                <p class="mt-2 text-sm text-brand-100/70 leading-relaxed">
                    This link closes once you send it, so you will not be able to change your answers here afterwards.
                    If something needs updating later, message us and we will reopen it.
                </p>
                <div class="mt-5 flex flex-wrap justify-end gap-2.5">
                    <button type="button" @click="confirming = false"
                            class="rounded-lg bg-white/10 px-4 py-2.5 text-xs font-semibold uppercase tracking-widest ring-1 ring-white/15 hover:bg-white/15 transition-colors">
                        Keep editing
                    </button>
                    <button type="submit" formaction="{{ route('brief.public.submit', $token) }}" @click="dirty = false"
                            class="rounded-lg bg-brand-400 px-5 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-300 transition-colors">
                        Send it
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

</body>
</html>
