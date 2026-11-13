{{--
    The month, Monday first. Each day: up to three little posters of what was
    uploaded (ringed by where it stands), a count, and a tap opens the day's
    videos underneath. Today glows; weekends are a shade quieter.
--}}
@php
    $start = $month->copy()->startOfMonth()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $end = $month->copy()->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SUNDAY);
    $ring = [\App\Models\ReviewVideo::PENDING => 'ring-amber-300', \App\Models\ReviewVideo::APPROVED => 'ring-emerald-400', \App\Models\ReviewVideo::CHANGES => 'ring-rose-400'];
    $dot = [\App\Models\ReviewVideo::PENDING => 'bg-amber-300', \App\Models\ReviewVideo::APPROVED => 'bg-emerald-400', \App\Models\ReviewVideo::CHANGES => 'bg-rose-400'];
    $all = $monthVideos->flatten(1)->sortByDesc('id')->unique('todo_id');
    $count = fn ($status) => $all->where('status', $status)->count();
    $firstDay = $monthVideos->keys()->contains(today()->toDateString()) ? today()->toDateString() : $monthVideos->keys()->last();
@endphp

<section x-data="{ day: @js($firstDay) }" class="space-y-4">
    {{-- Month header --}}
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('reviews.index', ['view' => 'calendar', 'month' => $month->copy()->subMonth()->format('Y-m')]) }}"
           class="flex h-10 w-10 items-center justify-center rounded-full bg-white/[0.06] text-white ring-1 ring-white/10 transition hover:bg-white/10" aria-label="Previous month">‹</a>
        <h2 class="min-w-[9rem] text-center text-xl font-extrabold tracking-tight text-white">{{ $month->format('F Y') }}</h2>
        <a href="{{ route('reviews.index', ['view' => 'calendar', 'month' => $month->copy()->addMonth()->format('Y-m')]) }}"
           class="flex h-10 w-10 items-center justify-center rounded-full bg-white/[0.06] text-white ring-1 ring-white/10 transition hover:bg-white/10" aria-label="Next month">›</a>
        @unless ($month->isSameMonth(now()))
            <a href="{{ route('reviews.index', ['view' => 'calendar']) }}" class="rounded-full px-3 py-1.5 text-xs text-brand-200 ring-1 ring-brand-300/30 hover:bg-white/5">This month</a>
        @endunless
        <div class="ml-auto flex flex-wrap gap-1.5 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/[0.05] px-3 py-1.5 text-brand-100/80"><span class="h-2 w-2 rounded-full bg-amber-300"></span>{{ $count('pending') }} waiting</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/[0.05] px-3 py-1.5 text-brand-100/80"><span class="h-2 w-2 rounded-full bg-emerald-400"></span>{{ $count('approved') }} approved</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/[0.05] px-3 py-1.5 text-brand-100/80"><span class="h-2 w-2 rounded-full bg-rose-400"></span>{{ $count('changes') }} sent back</span>
        </div>
    </div>

    {{-- The grid --}}
    <div class="overflow-hidden rounded-3xl bg-[#15303f] p-2 ring-1 ring-white/[0.07] sm:p-3">
        <div class="grid grid-cols-7 pb-1.5 text-center text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-100/45">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)
                <span>{{ $d }}</span>
            @endforeach
        </div>
        <div class="grid grid-cols-7 gap-1 sm:gap-1.5">
            @for ($d = $start->copy(); $d->lte($end); $d->addDay())
                @php
                    $key = $d->toDateString();
                    $videos = $monthVideos->get($key, collect());
                    $inMonth = $d->isSameMonth($month);
                @endphp
                <button type="button" @if ($videos->isNotEmpty()) @click="day = @js($key)" @else disabled @endif
                        class="group relative flex min-h-[74px] flex-col rounded-2xl p-1.5 text-left transition sm:min-h-[104px] sm:p-2
                               {{ $inMonth ? ($d->isWeekend() ? 'bg-white/[0.025]' : 'bg-white/[0.045]') : 'bg-transparent opacity-35' }}
                               {{ $videos->isNotEmpty() ? 'hover:-translate-y-0.5 hover:bg-white/[0.08] cursor-pointer' : 'cursor-default' }}"
                        :class="day === @js($key) ? 'ring-2 ring-brand-300 bg-white/[0.09]' : ''">
                    <span class="flex items-center justify-between">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold
                                     {{ $d->isToday() ? 'bg-brand-300 text-brand-900 shadow-[0_0_14px_rgba(138,204,224,.6)]' : 'text-brand-100/70' }}">{{ $d->day }}</span>
                        @if ($videos->count() > 0)
                            <span class="rounded-full bg-white/10 px-1.5 text-[10px] font-semibold text-white">{{ $videos->count() }}</span>
                        @endif
                    </span>

                    @if ($videos->isNotEmpty())
                        {{-- Posters, overlapping like a little hand of cards --}}
                        <span class="mt-auto hidden items-end pt-1 sm:flex">
                            @foreach ($videos->take(3) as $i => $v)
                                <span class="relative h-11 w-8 overflow-hidden rounded-md bg-black/40 ring-2 {{ $ring[$v->status] ?? 'ring-white/20' }} {{ $i ? '-ml-2.5' : '' }}"
                                      style="transform: rotate({{ [-4, 2, 6][$i] }}deg)">
                                    @if ($v->poster_path && ! $v->deleted_file_at)
                                        <img src="{{ route('reviews.poster', $v) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                    @endif
                                </span>
                            @endforeach
                            @if ($videos->count() > 3)
                                <span class="ml-1 text-[10px] text-brand-100/60">+{{ $videos->count() - 3 }}</span>
                            @endif
                        </span>
                        {{-- Phones: just the dots --}}
                        <span class="mt-auto flex flex-wrap gap-0.5 pt-1 sm:hidden">
                            @foreach ($videos->take(6) as $v)
                                <span class="h-1.5 w-1.5 rounded-full {{ $dot[$v->status] ?? 'bg-white/40' }}"></span>
                            @endforeach
                        </span>
                    @endif
                </button>
            @endfor
        </div>
    </div>

    {{-- The chosen day --}}
    @forelse ($monthVideos as $key => $videos)
        <div x-show="day === @js($key)" x-cloak x-transition.opacity>
            <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-brand-100/55">
                {{ \Illuminate\Support\Carbon::parse($key)->isToday() ? 'Today' : \Illuminate\Support\Carbon::parse($key)->format('l, j F') }}
                <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] normal-case tracking-normal text-brand-100/70">{{ $videos->count() }} {{ Str::plural('video', $videos->count()) }}</span>
                @php $uploads = $videos->sum(fn ($v) => count($v->day_versions ?? [1])); @endphp
                @if ($uploads > $videos->count())
                    <span class="text-[11px] normal-case tracking-normal text-brand-100/45">{{ $uploads }} uploads</span>
                @endif
                <span class="h-px flex-1 bg-white/10"></span>
            </p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($videos as $v)
                    @include('reviews._tile', ['v' => $v])
                @endforeach
            </div>
        </div>
    @empty
        <p class="rounded-2xl bg-white/[0.04] px-5 py-8 text-center text-sm text-brand-100/60">Nothing was uploaded for review in {{ $month->format('F') }}. 🌙</p>
    @endforelse
</section>
