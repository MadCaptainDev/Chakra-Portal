@php
    use App\Support\BrandBrief;

    /*
     * The brand brief, as the client fills it in.
     *
     * One page, seven collapsible sections, explicit save. Not a wizard: a
     * client who abandons at step three leaves the studio three sections and
     * no sense of the whole, and this is filled once, usually on a phone,
     * where seven steps is seven chances to drop out. Not autosaved either --
     * scripts/edit.blade.php earns its autosave because hours of writing live
     * there; this is one sitting, and "Save and finish later" covers the same
     * ground with none of the JavaScript.
     *
     * Field styling is local. See the note in _brief-section.blade.php for why
     * the shared x-text-input components are left alone.
     */
    $label = 'block text-sm font-semibold text-white';
    $hint = 'mt-1 text-xs text-brand-100/60 leading-snug';
    $field = 'mt-2 block w-full rounded-lg border-0 bg-white/10 px-3.5 py-2.5 text-sm text-white placeholder-brand-100/40 ring-1 ring-inset ring-white/15 focus:ring-2 focus:ring-inset focus:ring-brand-400';

    $answered = $brief->exists ? $brief->requiredAnswered() : 0;
    $total = $brief->requiredTotal();
    $complete = $answered >= $total;

    // The optional questions a writer misses most. Named, never demanded.
    $worthAMinute = collect(BrandBrief::HIGH_VALUE_OPTIONAL)
        ->reject(fn ($key) => $brief->exists && $brief->has($key))
        ->map(fn ($key) => mb_strtolower(rtrim(BrandBrief::QUESTIONS[$key]['label'], '?')));
@endphp

<x-app-layout title="Brand Brief" dark>
    <div x-data="{ dirty: false }"
         @change="dirty = true" @input="dirty = true"
         x-init="window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } })"
         class="space-y-6 pb-32">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-1.5 text-3xl sm:text-4xl font-extrabold tracking-tight">Before we write for you</h1>
            <p class="mt-3 max-w-2xl text-sm sm:text-base text-brand-100/70 leading-relaxed">
                A few questions about your brand, so every script we write sounds like you rather than like
                everyone else. About ten minutes, once — and you can stop halfway and come back.
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

        {{-- One form, two destinations. Both buttons must carry the answers --
             a separate submit form would post an empty payload and fail every
             required question -- so Submit switches the target with
             `formaction` instead. Save is declared first so that a stray Enter
             key in a text field saves a draft rather than declaring the brief
             finished. --}}
        <form method="POST" action="{{ route('client.brief.update') }}" class="space-y-4">
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

            {{-- Sticky, because the count is the answer to "how much is left"
                 and a client should not have to scroll to the end to find it. --}}
            <div class="fixed inset-x-0 bottom-0 z-30 border-t border-white/10 bg-brand-900/95 backdrop-blur">
                <div class="mx-auto max-w-5xl px-4 sm:px-6 py-3.5 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold tabular-nums">
                            {{ $answered }} of {{ $total }} answered
                            @unless ($complete)
                                <span class="font-normal text-brand-100/60">— the ones marked *</span>
                            @endunless
                        </p>
                        @if ($complete && $worthAMinute->isNotEmpty())
                            <p class="mt-0.5 text-xs text-brand-100/60 truncate">
                                Worth a minute if you have one: {{ $worthAMinute->take(2)->implode('; ') }}.
                            </p>
                        @endif
                    </div>

                    <div class="flex items-center gap-2.5">
                        <button type="submit" @click="dirty = false"
                                class="rounded-lg bg-white/10 px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-white ring-1 ring-white/15 hover:bg-white/15 transition-colors">
                            Save for later
                        </button>

                        {{-- Disabled until the required set is in. The title is
                             the whole explanation -- a button that refuses
                             without saying why is a button people click twice.
                             Disabling is a courtesy, not the check: the same
                             required rules run server-side on this route. --}}
                        <button type="submit" formaction="{{ route('client.brief.submit') }}" @click="dirty = false"
                                @disabled(! $complete)
                                title="{{ $complete ? 'Send this to the studio' : 'Answer the '.($total - $answered).' remaining starred questions first' }}"
                                @class([
                                    'rounded-lg px-5 py-2.5 text-xs font-semibold uppercase tracking-widest transition-colors',
                                    'bg-brand-400 text-brand-900 hover:bg-brand-300' => $complete,
                                    'bg-white/10 text-brand-100/40 cursor-not-allowed' => ! $complete,
                                ])>
                            {{ $brief->isSubmitted() ? 'Send changes' : 'Submit' }}
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
