@props([
    'month',
    'contentAccounts',
    'contentAccount',
    'contentPipeline',
    'routeName' => 'dashboard',
])

@if ($contentAccounts->isNotEmpty() && $contentPipeline)
    <section>
        <div class="flex flex-wrap items-baseline justify-between gap-4 mb-4">
            <x-section-label dark>Content pipeline</x-section-label>
            <a href="{{ route('content-dashboard.show', [$contentAccount, 'month' => $month->format('Y-m')]) }}"
               class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-white">
                Full account view →
            </a>
        </div>

        <x-card tone="dark" class="p-5 sm:p-6 space-y-5">
            <form method="GET" action="{{ route($routeName) }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[14rem] flex-1">
                    <label for="content-account" class="block text-[11px] font-semibold uppercase tracking-wider text-brand-100/50 mb-1.5">
                        Venture / Instagram account
                    </label>
                    <select id="content-account" name="account"
                            onchange="this.form.submit()"
                            class="w-full rounded-md border-white/15 bg-white/5 text-sm text-white">
                        @foreach ($contentAccounts as $account)
                            <option value="{{ $account->id }}" @selected($contentAccount?->id === $account->id)>
                                {{ $account->client?->name }} — {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-brand-100/60 pb-2">{{ $month->format('F Y') }}</p>
            </form>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                @foreach ($contentPipeline['sections'] as $key => $section)
                    <div class="rounded-lg bg-white/[0.03] ring-1 ring-white/10 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">{{ $section['label'] }}</p>
                        <p @class([
                            'mt-1 text-2xl font-bold tabular-nums',
                            'text-green-400' => $key === 'published' && $section['count'] > 0,
                            'text-amber-300' => $key !== 'published' && $section['count'] > 0,
                            'text-white' => $section['count'] === 0,
                        ])>{{ $section['count'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                @foreach ($contentPipeline['sections'] as $key => $section)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">{{ $section['label'] }}</p>
                        @forelse ($section['items'] as $item)
                            <a href="{{ route('content-dashboard.show', [$contentAccount, 'month' => $month->format('Y-m'), 'status' => $key === 'published' ? 'published' : 'in_progress']) }}"
                               class="flex items-start justify-between gap-2 py-2 border-b border-white/5 last:border-0 group">
                                <span class="min-w-0">
                                    <span class="block text-sm text-white truncate group-hover:text-brand-200">{{ $item->title ?: 'Untitled' }}</span>
                                    <span class="block text-[11px] text-brand-100/40 truncate">{{ $item->status }} · {{ ucfirst($item->source) }}</span>
                                </span>
                                <span class="shrink-0 text-[11px] tabular-nums text-brand-100/50">
                                    {{ $item->published_date?->format('j M') }}
                                </span>
                            </a>
                        @empty
                            <p class="text-sm text-brand-100/50">Nothing in this stage.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </x-card>
    </section>
@endif
