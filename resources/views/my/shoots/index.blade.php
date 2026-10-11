{{--
    The Shoot Updater's front door (ShootRunController::index): the shoots
    this person is on, live first, then today, then the next fortnight, and
    under them their past shoots month by month (shoots._crew-history). One
    big tap per shoot into the runner -- built for a phone held in one hand
    on location, not for a desk.
--}}
@php
    $time = fn ($shoot) => $shoot->starts_at->hour === 0 && $shoot->starts_at->minute === 0
        ? $shoot->starts_at->format('D j M')
        : $shoot->starts_at->format('D j M, g:i A');
@endphp

<x-app-layout title="My Shoots" dark>
    <div class="max-w-xl mx-auto space-y-7">
        <div class="animate-rise-in">
            <h1 class="text-3xl font-extrabold tracking-tight">My Shoots</h1>
            <p class="mt-1 text-sm text-brand-100/70">Open a shoot to mark that you've reached, start it, and log each video with a photo.</p>
        </div>

        @foreach ([
            ['Live now', $live, 'Nothing rolling right now.'],
            ['Today', $today, 'No shoots today.'],
            ['Coming up', $upcoming, 'Nothing in the next two weeks.'],
        ] as [$heading, $group, $empty])
            @continue($heading === 'Live now' && $group->isEmpty())

            <section>
                <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{{ $heading }}</p>

                @forelse ($group as $shoot)
                    @php
                        [$pill, $pillClass] = match (true) {
                            $shoot->isInProgress() => ['Rolling', 'bg-red-500/15 text-red-300'],
                            $shoot->hasWrapped() => ['Wrapped', 'bg-emerald-500/15 text-emerald-300'],
                            $shoot->hasReached() => ['Reached', 'bg-amber-500/15 text-amber-200'],
                            $shoot->hasDeparted() => ['On the way', 'bg-sky-500/15 text-sky-200'],
                            default => ['Not started', 'bg-white/10 text-brand-100/70'],
                        };
                    @endphp
                    <a href="{{ route('my.shoots.run', $shoot) }}"
                       class="mb-3 block rounded-2xl bg-white/5 ring-1 ring-white/10 p-4 active:scale-[0.99] hover:bg-white/[0.07] transition">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-white text-base leading-snug">{{ $shoot->title }}</p>
                                <p class="mt-0.5 text-sm text-brand-100/70 truncate">
                                    {{ $shoot->client?->name ?? 'No client' }}@if ($shoot->location) · {{ $shoot->location }}@endif
                                </p>
                            </div>
                            <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $pillClass }}">
                                @if ($shoot->isInProgress())<span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-400"></span>@endif
                                {{ $pill }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-brand-100/60">
                            {{ $time($shoot) }}
                            @if ($shoot->videos_count) · {{ $shoot->videos_count }} {{ Str::plural('video', $shoot->videos_count) }} logged @endif
                        </p>
                        @if ($shoot->crew->isNotEmpty())
                            <p class="mt-1 text-xs text-brand-100/50">
                                {{ $shoot->crew->map(fn ($c) => $c->user ? $c->user->name.($c->role ? ' ('.$c->role.')' : '') : null)->filter()->implode(', ') }}
                            </p>
                        @endif
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-white/15 px-6 py-6 text-center text-sm text-brand-100/60">{{ $empty }}</div>
                @endforelse
            </section>
        @endforeach

        <section>
            <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{{ $person ? 'Your past shoots' : 'Past shoots' }}</p>
            @include('shoots._crew-history', ['shoots' => $past, 'person' => $person])
        </section>
    </div>
</x-app-layout>
