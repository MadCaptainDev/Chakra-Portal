{{--
    My Shoots (ShootRunController::index), for everyone on a shoot's crew.
    Built for a phone on location: the shoot to get ready for comes first,
    with its scripts readable right here, then the rest of the fortnight,
    then past shoots month by month (shoots._crew-history).
--}}
@php
    $time = fn ($shoot) => $shoot->starts_at->hour === 0 && $shoot->starts_at->minute === 0
        ? null
        : $shoot->starts_at->format('g:i A');
    $day = fn ($d) => $d->isToday() ? 'Today' : ($d->isTomorrow() ? 'Tomorrow' : $d->format('l'));
    $state = fn ($shoot) => match (true) {
        $shoot->isInProgress() => ['Rolling', 'bg-red-500/15 text-red-300'],
        $shoot->hasWrapped() => ['Wrapped', 'bg-emerald-500/15 text-emerald-300'],
        $shoot->hasReached() => ['Reached', 'bg-amber-500/15 text-amber-200'],
        $shoot->hasDeparted() => ['On the way', 'bg-sky-500/15 text-sky-200'],
        default => ['Not started', 'bg-white/10 text-brand-100/70'],
    };
    $cta = fn ($shoot) => match (true) {
        $shoot->isInProgress() => 'Continue shooting',
        $shoot->hasReached() => 'Start the shoot',
        $shoot->hasDeparted() => "I've reached",
        default => 'Open shoot',
    };
    $statusTone = fn (string $s) => match ($s) {
        'Approved' => 'bg-emerald-400/15 text-emerald-200',
        'Ready' => 'bg-sky-400/15 text-sky-200',
        default => 'bg-white/10 text-brand-100/70',
    };
    $rest = $live->merge($today)->merge($upcoming)->reject(fn ($s) => $next && $s->is($next));
@endphp

<x-app-layout title="My Shoots" dark>
    <div class="mx-auto max-w-2xl space-y-7 pb-16">
        <header>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">My Shoots</h1>
            <p class="mt-1 text-sm text-brand-100/65">Your next shoot and its scripts, ready before you leave.</p>
        </header>

        @if ($next)
            @php
                [$pill, $pillClass] = $state($next);
                $list = $scripts[$next->id] ?? [];
                $toShoot = collect($list)->whereNull('shot')->values();
                $shotList = collect($list)->whereNotNull('shot')->values();
            @endphp
            {{-- ═══ The shoot to get ready for ═══ --}}
            <section class="overflow-hidden rounded-3xl border {{ $next->isInProgress() ? 'border-red-400/40' : 'border-brand-400/30' }} bg-[#15303f]">
                <div class="space-y-4 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3.5">
                            <div class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-2xl {{ $next->starts_at->isToday() ? 'bg-brand-400 text-brand-900' : 'bg-white/10 text-white' }}">
                                <span class="text-[10px] font-bold uppercase">{{ $next->starts_at->format('M') }}</span>
                                <span class="text-xl font-extrabold leading-none">{{ $next->starts_at->format('j') }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-300">{{ $next->isInProgress() ? 'Rolling now' : 'Next shoot · '.$day($next->starts_at) }}</p>
                                <h2 class="text-xl font-bold leading-snug text-white">{{ trim($next->title) }}</h2>
                                <p class="text-sm text-brand-100/70">{{ $next->client?->name ?? 'No client' }}</p>
                            </div>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $pillClass }}">
                            @if ($next->isInProgress())<span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-400"></span>@endif
                            {{ $pill }}
                        </span>
                    </div>

                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded-xl bg-white/[0.05] px-3 py-2.5">
                            <dt class="text-[11px] text-brand-100/50">When</dt>
                            <dd class="font-semibold text-white">{{ $day($next->starts_at) }}{{ $time($next) ? ', '.$time($next) : '' }}</dd>
                        </div>
                        <div class="rounded-xl bg-white/[0.05] px-3 py-2.5">
                            <dt class="text-[11px] text-brand-100/50">Where</dt>
                            <dd class="truncate font-semibold text-white">
                                @if ($next->location)
                                    <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($next->location) }}" target="_blank" rel="noopener" class="text-brand-300 hover:text-white">{{ $next->location }} ↗</a>
                                @else
                                    <span class="text-brand-100/50">Not set</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($next->crew->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($next->crew as $c)
                                @continue(! $c->user)
                                <span class="inline-flex items-center gap-2 rounded-full bg-white/[0.05] py-1 pl-1 pr-3 text-[13px] text-white">
                                    <x-avatar :name="$c->user->name" :src="$c->user->avatarUrl()" size="sm" />
                                    {{ $c->user->name }}@if ($c->role)<span class="text-brand-100/50">· {{ $c->role }}</span>@endif
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if ($next->notes)
                        <p class="rounded-xl bg-amber-400/[0.08] px-3.5 py-2.5 text-sm text-amber-100/90">{{ $next->notes }}</p>
                    @endif

                    <a href="{{ route('my.shoots.run', $next) }}"
                       class="flex h-14 w-full items-center justify-center rounded-2xl text-base font-bold transition active:scale-[0.99] {{ $next->isInProgress() ? 'bg-red-500 text-white hover:bg-red-400' : 'bg-brand-400 text-brand-900 hover:bg-brand-300' }}">
                        {{ $cta($next) }}{{ $next->videos_count ? ' · '.$next->videos_count.' '.Str::plural('video', $next->videos_count).' so far' : '' }}
                    </a>
                </div>

                {{-- Scripts for this shoot, readable here --}}
                <div class="border-t border-white/[0.07] bg-[#11283a] p-5" x-data="{ open: null, done: false }">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h3 class="font-bold text-white">Scripts to shoot</h3>
                        <span class="text-sm text-brand-100/60">{{ $toShoot->count() }} ready{{ $shotList->isNotEmpty() ? ' · '.$shotList->count().' already shot' : '' }}</span>
                    </div>
                    @forelse ($toShoot as $sc)
                        <div class="mb-2 overflow-hidden rounded-2xl bg-white/[0.05] ring-1 ring-white/[0.07]">
                            <button type="button" @click="open = open === {{ $sc['id'] }} ? null : {{ $sc['id'] }}"
                                    class="flex min-h-[56px] w-full items-center gap-3 px-4 py-3 text-left" :aria-expanded="open === {{ $sc['id'] }}">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-400/15 text-sm font-bold text-brand-300">{{ $loop->iteration }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold leading-snug text-white">{{ $sc['title'] }}</span>
                                    <span class="text-xs text-brand-100/55">{{ count($sc['sections']) }} {{ Str::plural('part', count($sc['sections'])) }}</span>
                                </span>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusTone($sc['status']) }}">{{ $sc['status'] }}</span>
                                <span class="text-brand-100/50 transition" :class="open === {{ $sc['id'] }} && 'rotate-180'">⌄</span>
                            </button>
                            <div x-show="open === {{ $sc['id'] }}" x-cloak class="space-y-3 border-t border-white/[0.06] px-4 py-4 text-[15px] leading-relaxed text-brand-50">
                                @foreach ($sc['sections'] as $part)
                                    <div>
                                        @if (filled($part['heading']))<p class="mb-1 text-xs font-semibold uppercase tracking-wide text-brand-300">{{ $part['heading'] }}</p>@endif
                                        <div class="[&_p]:mb-2">{!! $part['body'] !!}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="rounded-2xl border border-dashed border-white/15 px-4 py-5 text-center text-sm text-brand-100/60">
                            {{ $next->client_id ? 'No scripts waiting for '.$next->client?->name.'.' : 'This shoot has no client, so no scripts.' }}
                        </p>
                    @endforelse
                    @if ($shotList->isNotEmpty())
                        <button type="button" @click="done = !done" class="mt-1 text-[13px] font-semibold text-brand-300" x-text="done ? 'Hide shot scripts' : 'Show {{ $shotList->count() }} already shot'"></button>
                        <div x-show="done" x-cloak class="mt-2 space-y-1">
                            @foreach ($shotList as $sc)
                                <p class="flex items-center gap-2 text-sm text-brand-100/55"><span class="text-emerald-400">✓</span><span class="truncate">{{ $sc['title'] }}</span><span class="text-xs">{{ $sc['shot'] === 'here' ? 'this shoot' : 'earlier' }}</span></p>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @else
            <section class="rounded-3xl border border-dashed border-white/15 px-6 py-10 text-center">
                <p class="text-lg font-semibold text-white">No shoots for you in the next two weeks</p>
                <p class="mt-1 text-sm text-brand-100/60">When you're added to a shoot's crew it shows here, with its scripts.</p>
            </section>
        @endif

        {{-- ═══ The rest of the fortnight ═══ --}}
        @if ($rest->isNotEmpty())
            <section>
                <h2 class="mb-2.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">Also coming up</h2>
                <div class="space-y-2.5">
                    @foreach ($rest as $shoot)
                        @php
                            [$pill, $pillClass] = $state($shoot);
                            $ready = collect($scripts[$shoot->id] ?? [])->whereNull('shot')->count();
                        @endphp
                        <a href="{{ route('my.shoots.run', $shoot) }}" class="flex items-center gap-3.5 rounded-2xl bg-white/[0.05] p-3.5 ring-1 ring-white/[0.07] transition hover:bg-white/[0.08] active:scale-[0.99]">
                            <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl {{ $shoot->starts_at->isToday() ? 'bg-brand-400/20 text-white' : 'bg-white/[0.07] text-white' }}">
                                <span class="text-[10px] font-bold uppercase text-brand-100/60">{{ $shoot->starts_at->format('D') }}</span>
                                <span class="text-lg font-extrabold leading-none">{{ $shoot->starts_at->format('j') }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-white">{{ trim($shoot->title) }}</p>
                                <p class="truncate text-[13px] text-brand-100/60">{{ $shoot->client?->name ?? 'No client' }}{{ $time($shoot) ? ' · '.$time($shoot) : '' }}{{ $shoot->location ? ' · '.$shoot->location : '' }}</p>
                                <p class="text-xs {{ $ready ? 'text-sky-300' : 'text-brand-100/45' }}">{{ $ready ? $ready.' '.Str::plural('script', $ready).' ready' : 'No scripts yet' }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $pillClass }}">{{ $pill }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section>
            <h2 class="mb-2.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{{ $person ? 'Your past shoots' : 'Past shoots' }}</h2>
            @include('shoots._crew-history', ['shoots' => $past, 'person' => $person])
        </section>
    </div>
</x-app-layout>
