@php
    // Published is done, scheduled is a promise, and the grid has to be
    // readable at a glance without reading a single word.
    $dot = fn (string $state) => $state === 'published' ? 'bg-emerald-400' : 'bg-brand-300';
    $chip = fn (string $state) => $state === 'published'
        ? 'bg-emerald-400/15 text-emerald-200'
        : 'bg-brand-400/15 text-brand-200';
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

        <div class="flex flex-wrap items-center gap-4 text-xs text-brand-100/70">
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400"></span> Published
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-brand-300"></span> Scheduled
            </span>
            <span class="text-brand-100/50">{{ $published->count() }} out &middot; {{ $scheduled->count() }} to come</span>
        </div>

        {{-- The month itself. Whole weeks, Monday first, so the shape of a
             fortnight with nothing in it is visible without counting. --}}
        <div class="grid grid-cols-7 gap-px rounded-xl overflow-hidden ring-1 ring-white/10 bg-white/10">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                <div class="bg-brand-900 py-2 text-center text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-100/60">
                    {{-- One letter is all that fits on a phone. --}}
                    <span class="sm:hidden">{{ substr($weekday, 0, 1) }}</span>
                    <span class="hidden sm:inline">{{ $weekday }}</span>
                </div>
            @endforeach

            @foreach ($weeks as $week)
                @foreach ($week as $day)
                    <div @class([
                        'bg-brand-900 min-h-[68px] sm:min-h-[104px] p-1 sm:p-1.5 align-top',
                        'opacity-40' => ! $day['inMonth'],
                    ])>
                        <div class="flex items-center justify-between gap-1">
                            <span @class([
                                'inline-flex items-center justify-center min-w-[20px] h-5 px-1 rounded text-[10px] font-bold tabular-nums',
                                'bg-brand-400 text-brand-900' => $day['isToday'],
                                'text-brand-100/50' => ! $day['isToday'],
                            ])>{{ $day['date']->day }}</span>

                            {{-- Phones get counts, not titles: five words in a
                                 44px square is five words nobody can read. --}}
                            @if ($day['pieces']->isNotEmpty())
                                <span class="sm:hidden inline-flex items-center gap-0.5">
                                    @foreach ($day['pieces']->take(3) as $piece)
                                        <span class="w-1.5 h-1.5 rounded-full {{ $dot($piece['state']) }}"></span>
                                    @endforeach
                                </span>
                            @endif
                        </div>

                        @if ($day['pieces']->isNotEmpty())
                            <div class="hidden sm:block mt-1 space-y-1">
                                @foreach ($day['pieces']->take(3) as $piece)
                                    <a href="#day-{{ $day['date']->toDateString() }}"
                                       class="flex items-center gap-1 px-1 py-0.5 rounded text-[10px] leading-tight
                                              {{ $chip($piece['state']) }} hover:brightness-125 transition">
                                        <x-brand-icon :name="head($piece['channels'])['platform']" class="w-3 h-3 shrink-0" />
                                        <span class="truncate">{{ $piece['title'] }}</span>
                                    </a>
                                @endforeach

                                @if ($day['pieces']->count() > 3)
                                    <a href="#day-{{ $day['date']->toDateString() }}"
                                       class="block px-1 text-[10px] font-semibold text-brand-100/60 hover:text-brand-100">
                                        +{{ $day['pieces']->count() - 3 }} more
                                    </a>
                                @endif
                            </div>

                            {{-- The phone's way into the same detail: the whole
                                 square is the tap target, since the dots above
                                 are too small to aim at. --}}
                            <a href="#day-{{ $day['date']->toDateString() }}" class="sm:hidden block mt-0.5 text-[10px] text-brand-100/50">
                                {{ $day['pieces']->count() }}
                            </a>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- Day by day, because a square cannot hold a title, a platform and a
             status, and the client came here to read those. --}}
        @php
            $dated = $weeks->flatten(1)->filter(fn (array $day) => $day['inMonth'] && $day['pieces']->isNotEmpty());
        @endphp

        @forelse ($dated as $day)
            <section id="day-{{ $day['date']->toDateString() }}" class="scroll-mt-20">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">
                    {{ $day['date']->format('l j F') }}
                </p>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($day['pieces'] as $piece)
                        <div class="p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex items-start gap-3">
                                    <x-brand-icon :name="head($piece['channels'])['platform']" class="w-5 h-5 shrink-0 mt-0.5" />
                                    <div class="min-w-0">
                                        <p class="font-semibold truncate">{{ $piece['title'] }}</p>
                                        <p class="mt-1 text-sm text-brand-100/70">
                                            {{-- Every place this one piece goes, on one line,
                                                 rather than the same cut listed once per
                                                 destination as if it were three deliveries. --}}
                                            {{ collect($piece['channels'])->pluck('label')->join(' + ') }}
                                            @if ($piece['note']) &middot; {{ $piece['note'] }} @endif
                                        </p>
                                    </div>
                                </div>
                                <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full ring-1 ring-white/10
                                             text-[10px] font-bold uppercase tracking-wide {{ $chip($piece['state']) }}">
                                    {{ $piece['state'] === 'published' ? 'Published' : 'Scheduled' }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">Nothing on the calendar for {{ $month->format('F') }} yet.</p>
                <p class="mt-1 text-xs text-brand-100/50">Planned work appears here as soon as it has a date.</p>
            </div>
        @endforelse

        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">
                Being worked on right now
            </p>
            {{-- Off the grid on purpose: these have no agreed day, and a square
                 would be claiming a date nobody has chosen. --}}
            @forelse ($inProgress as $piece)
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5 {{ $loop->first ? '' : 'mt-3' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex items-start gap-3">
                            <x-brand-icon :name="head($piece['channels'])['platform']" class="w-5 h-5 shrink-0 mt-0.5" />
                            <div class="min-w-0">
                                <p class="font-semibold truncate">{{ $piece['title'] }}</p>
                                <p class="mt-1 text-sm text-brand-100/70">
                                    {{ collect($piece['channels'])->pluck('label')->join(' + ') }}
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full ring-1 ring-white/10
                                     text-[10px] font-bold uppercase tracking-wide bg-amber-400/15 text-amber-200">
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
    </div>
</x-app-layout>
