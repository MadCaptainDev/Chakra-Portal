@props([
    'tabs' => [],
    'model' => 'tab',
])

{{-- A segmented switch driven by an Alpine property the caller owns, so the
     lists it switches between are all rendered and swapping is instant. The
     counts sit in the tab: a tab that says 0 saves the tap that finds out.

     On the dark plane the track is a recessed well and the selected tab is
     the raised glass panel -- the inverse of the light version, where the
     track was grey and the selected tab was white. --}}
<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 p-1 rounded-xl bg-white/[0.04] ring-1 ring-white/10']) }}
     role="tablist">
    @foreach ($tabs as $key => $tab)
        <button type="button" role="tab"
                @click="{{ $model }} = '{{ $key }}'"
                :aria-selected="{{ $model }} === '{{ $key }}'"
                :class="{{ $model }} === '{{ $key }}'
                    ? 'bg-white/[0.14] text-white ring-1 ring-white/15'
                    : 'text-brand-100/60 hover:text-white'"
                class="inline-flex items-center gap-1.5 px-3 py-2 min-h-[40px] rounded-lg
                       text-sm font-semibold transition duration-150">
            {{ $tab['label'] }}

            @if (($tab['count'] ?? null) !== null)
                <span :class="{{ $model }} === '{{ $key }}' ? 'bg-brand-400/20 text-brand-200' : 'bg-white/10 text-brand-100/60'"
                      class="inline-flex items-center justify-center min-w-[20px] px-1 h-5 rounded-full
                             text-[11px] font-bold tabular-nums transition-colors">
                    {{ $tab['count'] }}
                </span>
            @endif
        </button>
    @endforeach
</div>
