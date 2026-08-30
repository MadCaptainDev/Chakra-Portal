@props([
    'shoots',
    'title' => 'Upcoming shoots',
    'empty' => 'Nothing scheduled.',
    'allHref' => null,
])

@if ($shoots->isNotEmpty() || $allHref)
    <section>
        <div class="flex items-baseline justify-between gap-4 mb-3.5">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{{ $title }}</p>
            @if ($allHref)
                <a href="{{ $allHref }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All shoots →</a>
            @endif
        </div>

        @if ($shoots->isEmpty())
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-8 text-center">
                <p class="text-sm text-brand-100/70">{{ $empty }}</p>
            </div>
        @else
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                @foreach ($shoots as $shoot)
                    <a href="{{ route('shoots.show', $shoot) }}"
                       class="flex items-center justify-between gap-3 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }} group hover:bg-white/[0.03] transition-colors">
                        <span class="min-w-0">
                            <p class="text-sm font-semibold truncate group-hover:text-brand-200">{{ $shoot->title }}</p>
                            <p class="mt-0.5 text-xs text-brand-100/60 truncate">
                                {{ $shoot->client?->name ?? 'No client' }}@if ($shoot->location) · {{ $shoot->location }}@endif
                            </p>
                        </span>
                        <span class="shrink-0 text-xs tabular-nums text-brand-100/70">{{ $shoot->starts_at->format('j M, g:i A') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endif
