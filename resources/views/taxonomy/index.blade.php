@php
    use App\Models\TaxonomyTerm;

    $inactive = $terms->where('is_active', false)->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Master data">
            <x-slot name="actions">
                <a href="{{ route('portfolio.index') }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Back to portfolio
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-4" x-data="{ adding: false }">
        {{-- The lists. Horizontally scrollable on a phone rather than wrapping
             into a block that pushes the content off screen. --}}
        <div class="-mx-4 sm:mx-0 px-4 sm:px-0 overflow-x-auto">
            <div class="flex gap-2 min-w-max sm:min-w-0 sm:flex-wrap" role="tablist" aria-label="Master lists">
                @foreach (TaxonomyTerm::TYPES as $value => $info)
                    <a href="{{ route('taxonomy.index', ['type' => $value]) }}"
                       role="tab" @if ($type === $value) aria-selected="true" @endif
                       class="shrink-0 inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg text-sm font-semibold transition
                              {{ $type === $value
                                    ? 'bg-brand-500 text-white shadow-sm'
                                    : 'bg-white text-gray-600 ring-1 ring-gray-900/10 hover:bg-gray-50 hover:text-gray-900' }}">
                        {{ $info['plural'] }}
                        <span class="text-xs {{ $type === $value ? 'text-white/70' : 'text-gray-400' }}">
                            {{ $counts[$value] ?? 0 }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-gray-500">{{ $meta['hint'] }}</p>
            <button type="button" @click="adding = ! adding"
                    class="shrink-0 min-h-[44px] text-sm font-semibold text-brand-500 hover:text-brand-600">
                <span x-show="! adding">+ New</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6">
                <form method="POST" action="{{ route('taxonomy.store') }}">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    @include('taxonomy._fields', ['term' => null, 'meta' => $meta])
                </form>
            </x-card>
        </div>

        @if ($terms->isEmpty())
            <x-empty-state message="No {{ Str::lower($meta['plural']) }} yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">
                    Add the first one &rarr;
                </button>
            </x-empty-state>
        @else
            @if ($inactive > 0)
                <p class="text-xs text-gray-500">
                    {{ $inactive }} retired {{ Str::lower($inactive === 1 ? $meta['label'] : $meta['plural']) }} —
                    hidden from the pickers, still readable on the work already using
                    {{ $inactive === 1 ? 'it' : 'them' }}.
                </p>
            @endif

            <x-card class="divide-y divide-gray-200">
                @foreach ($terms as $term)
                    @php $used = $term->usageCount(); @endphp
                    <div class="p-3 sm:p-4" x-data="{ editing: false }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-semibold {{ $term->is_active ? 'text-gray-900' : 'text-gray-400' }}">
                                        {{ $term->name }}
                                    </p>
                                    @unless ($term->is_active)
                                        <x-badge status="retired" color="bg-gray-100 text-gray-600">Retired</x-badge>
                                    @endunless
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ $used }} {{ Str::plural('record', $used) }}
                                    &middot; order {{ $term->sort_order }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" @click="editing = ! editing"
                                        class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">
                                    <span x-show="! editing">Edit</span>
                                    <span x-show="editing" x-cloak>Cancel</span>
                                </button>
                                <form method="POST" action="{{ route('taxonomy.destroy', $term) }}"
                                      onsubmit="return confirm('{{ $used > 0
                                            ? 'Delete “'.$term->name.'”? '.$used.' '.Str::plural('record', $used).' will no longer name one. The work itself is kept. Retiring it instead keeps the label.'
                                            : 'Delete “'.$term->name.'”?' }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">Delete</button>
                                </form>
                            </div>
                        </div>

                        <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-gray-200">
                            <form method="POST" action="{{ route('taxonomy.update', $term) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="type" value="{{ $term->type }}">
                                @include('taxonomy._fields', ['term' => $term, 'meta' => $meta])
                            </form>
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
