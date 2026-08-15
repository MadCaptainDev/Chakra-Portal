{{-- Date, time, location, status. No crew, no kit, no notes -- the controller
     does not even select those columns, so nothing can arrive on this screen
     by being added to the table later. --}}

<x-app-layout title="Shoots" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Shoots</h1>
            <p class="mt-2 text-sm text-brand-100/70">What is booked, and what has already happened.</p>
        </div>

        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">Coming up</p>

            @forelse ($upcoming as $shoot)
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5 {{ $loop->first ? '' : 'mt-3' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-lg">{{ $shoot->title }}</p>
                            <p class="mt-1 text-sm text-brand-100/70">
                                {{ $shoot->starts_at?->format('l j F, H:i') }}
                                @if ($shoot->ends_at) &ndash; {{ $shoot->ends_at->format('H:i') }} @endif
                            </p>
                            @if ($shoot->location)
                                <p class="mt-1 flex items-center gap-1.5 text-xs text-brand-100/60">
                                    <x-icon name="map-pin" class="w-3.5 h-3.5 shrink-0" />
                                    {{ $shoot->location }}
                                </p>
                            @endif
                        </div>

                        <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full bg-white/10 ring-1 ring-white/20
                                     text-[10px] font-bold uppercase tracking-wide">
                            {{ $shoot->statusLabel() }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                    <p class="text-sm text-brand-100/70">Nothing scheduled yet.</p>
                    <p class="mt-1 text-xs text-brand-100/50">Booked shoots will show up here with the date and location.</p>
                </div>
            @endforelse
        </section>

        @if ($past->isNotEmpty())
            <section>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">Recently</p>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($past as $shoot)
                        <div class="flex items-center justify-between gap-3 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <div class="min-w-0">
                                <p class="font-medium truncate">{{ $shoot->title }}</p>
                                <p class="mt-0.5 text-xs text-brand-100/60">
                                    {{ $shoot->starts_at?->format('j M Y') }}
                                    @if ($shoot->location) &middot; {{ $shoot->location }} @endif
                                </p>
                            </div>
                            <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide text-brand-100/50">
                                {{ $shoot->statusLabel() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
