@php
    $statusColor = fn (string $status) => match ($status) {
        'Published' => 'bg-emerald-400/15 text-emerald-200',
        'Scheduled' => 'bg-brand-400/15 text-brand-200',
        default => 'bg-amber-400/15 text-amber-200',
    };
@endphp

<x-app-layout title="Content Calendar" dark>
    <div class="space-y-6">

        <div class="animate-rise-in flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
                <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Content Calendar</h1>
                <p class="mt-2 text-sm text-brand-100/70">What's already out, what's about to go out, and what's still being made.</p>
            </div>
            <x-month-nav route="client.content-calendar" :month="$month" class="max-w-xs" />
        </div>

        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">
                Scheduled to publish this month
            </p>
            @forelse ($scheduled as $item)
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5 {{ $loop->first ? '' : 'mt-3' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex items-start gap-3">
                            <span class="text-xl leading-none">{{ $item->sourceIcon() }}</span>
                            <div class="min-w-0">
                                <p class="font-semibold text-lg truncate">{{ $item->title ?: $item->sourceLabel() }}</p>
                                <p class="mt-1 text-sm text-brand-100/70">
                                    {{ $item->sourceLabel() }}
                                    @if ($item->published_date) &middot; going out {{ $item->published_date->format('j M') }} @endif
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full ring-1 ring-white/10
                                     text-[10px] font-bold uppercase tracking-wide {{ $statusColor($item->status) }}">
                            Scheduled
                        </span>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                    <p class="text-sm text-brand-100/70">Nothing scheduled to go out this month yet.</p>
                </div>
            @endforelse
        </section>

        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">
                Being worked on right now
            </p>
            @forelse ($inProgress as $item)
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5 {{ $loop->first ? '' : 'mt-3' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex items-start gap-3">
                            <span class="text-xl leading-none">{{ $item->sourceIcon() }}</span>
                            <div class="min-w-0">
                                <p class="font-semibold text-lg truncate">{{ $item->title ?: $item->sourceLabel() }}</p>
                                <p class="mt-1 text-sm text-brand-100/70">{{ $item->sourceLabel() }}</p>
                            </div>
                        </div>
                        <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full ring-1 ring-white/10
                                     text-[10px] font-bold uppercase tracking-wide {{ $statusColor($item->status) }}">
                            In progress
                        </span>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                    <p class="text-sm text-brand-100/70">Nothing currently in progress.</p>
                </div>
            @endforelse
        </section>

        @if ($published->isNotEmpty())
            <section>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">Published this month</p>
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($published as $item)
                        <div class="flex items-center gap-3 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <span class="text-lg leading-none shrink-0">{{ $item->sourceIcon() }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium truncate">{{ $item->title ?: $item->sourceLabel() }}</p>
                                <p class="mt-0.5 text-xs text-brand-100/60">
                                    {{ $item->sourceLabel() }} &middot; {{ $item->published_date?->format('j M Y') }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
