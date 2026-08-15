<x-app-layout title="Work delivered" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Work Delivered</h1>
            <p class="mt-2 text-sm text-brand-100/70">Everything published for you, newest first.</p>
        </div>

        <div class="grid grid-cols-2 gap-3.5">
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ number_format($total) }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Pieces published</p>
            </div>
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $thisMonth }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">This month</p>
            </div>
        </div>

        @forelse ($months as $month)
            <section>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">{{ $month['label'] }}</p>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($month['items'] as $item)
                        <div class="flex items-start gap-3.5 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-400/15 text-brand-300">
                                <x-icon name="sparkles" class="w-4 h-4" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="font-medium truncate">{{ $item->title }}</p>
                                <p class="mt-0.5 text-xs text-brand-100/60">
                                    {{ $item->published_date->format('D j M') }}
                                    @if ($item->post_type) &middot; {{ $item->post_type }} @endif
                                </p>
                            </div>

                            @if ($item->notion_url)
                                <a href="{{ $item->notion_url }}" target="_blank" rel="noopener noreferrer"
                                   class="shrink-0 inline-flex items-center min-h-[36px] px-3 rounded-md border border-white/20
                                          text-[10px] font-semibold uppercase tracking-wider hover:bg-white/10 transition-colors">
                                    Open
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-14 text-center">
                <p class="text-sm text-brand-100/70">Nothing published yet.</p>
                <p class="mt-1 text-xs text-brand-100/50">Work appears here once it goes live.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
