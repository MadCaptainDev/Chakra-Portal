@props([
    'tabs' => [],
    'model' => 'tab',
])

{{-- A segmented switch driven by an Alpine property the caller owns, so the
     lists it switches between are all rendered and swapping is instant. The
     counts sit in the tab: a tab that says 0 saves the tap that finds out. --}}
<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 p-1 rounded-xl bg-gray-100 ring-1 ring-gray-900/5']) }}
     role="tablist">
    @foreach ($tabs as $key => $tab)
        <button type="button" role="tab"
                @click="{{ $model }} = '{{ $key }}'"
                :aria-selected="{{ $model }} === '{{ $key }}'"
                :class="{{ $model }} === '{{ $key }}'
                    ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-900/5'
                    : 'text-gray-500 hover:text-gray-800'"
                class="inline-flex items-center gap-1.5 px-3 py-2 min-h-[40px] rounded-lg
                       text-sm font-semibold transition duration-150">
            {{ $tab['label'] }}

            @if (($tab['count'] ?? null) !== null)
                <span :class="{{ $model }} === '{{ $key }}' ? 'bg-brand-100 text-brand-800' : 'bg-gray-200 text-gray-600'"
                      class="inline-flex items-center justify-center min-w-[20px] px-1 h-5 rounded-full
                             text-[11px] font-bold tabular-nums transition-colors">
                    {{ $tab['count'] }}
                </span>
            @endif
        </button>
    @endforeach
</div>
