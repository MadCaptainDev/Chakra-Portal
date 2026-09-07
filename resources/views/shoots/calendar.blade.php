@php
    use App\Models\Shoot;
@endphp

<x-app-layout title="Shoot calendar">
    <x-slot name="header">
        <x-page-header title="Shoot calendar" subtitle="Every shoot this month, by date.">
            <x-slot name="actions">
                <x-btn :href="route('shoots.index')" variant="secondary">List view</x-btn>
                @can('shoots.create')
                    <x-btn :href="route('shoots.create')">Plan a shoot</x-btn>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ open: null }">
        <x-month-nav route="shoots.calendar" :month="$month"
                     :subtitle="$shootsThisMonth.' shoot(s)'" />

        {{-- What each pill's colour means -- same convention as the routine
             calendar this mirrors. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-brand-100/60">
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-white/5 ring-1 ring-white/15"></span> Planned
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-brand-400/15 ring-1 ring-brand-400/30"></span> Confirmed
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-green-400/10 ring-1 ring-green-400/30"></span> Completed
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-white/10 ring-1 ring-white/15"></span> Cancelled
            </span>
        </div>

        <div class="-mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 overflow-x-auto pb-2">
            <div class="min-w-[640px]">
                <div class="grid grid-cols-7 gap-1 mb-1">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)
                        <div class="text-center text-[11px] font-semibold text-brand-100/60 uppercase tracking-wide py-1">{{ $label }}</div>
                    @endforeach
                </div>

                <div class="space-y-1">
                    @foreach ($weeks as $week)
                        <div class="grid grid-cols-7 gap-1">
                            @foreach ($week as $day)
                                <div class="min-h-[92px] rounded-xl p-1.5 text-left transition
                                    {{ $day['inMonth'] ? 'bg-brand-900/40 ring-1 ring-white/10 shadow-sm' : 'bg-brand-900/40 ring-1 ring-white/[0.06]' }}
                                    {{ $day['isToday'] ? 'ring-2 ring-brand-400 shadow-md' : '' }}">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-semibold {{ $day['inMonth'] ? 'text-brand-100/80' : 'text-brand-100/40' }}">
                                            {{ $day['date']->format('j') }}
                                        </span>
                                        @if ($day['shoots']->count() > 0)
                                            <span class="text-[10px] font-semibold text-brand-300">{{ $day['shoots']->count() }}</span>
                                        @endif
                                    </div>

                                    @foreach ($day['shoots']->take(3) as $shoot)
                                        <button type="button" @click="open = open === {{ $shoot->id }} ? null : {{ $shoot->id }}"
                                                class="mb-0.5 w-full text-left px-1 py-0.5 rounded text-[10px] leading-tight truncate
                                                {{ $shoot->status === Shoot::STATUS_COMPLETED ? 'bg-green-400/10 text-green-200'
                                                    : ($shoot->status === Shoot::STATUS_CANCELLED ? 'bg-white/10 text-brand-100/60 line-through'
                                                    : ($shoot->status === Shoot::STATUS_CONFIRMED ? 'bg-brand-400/15 text-brand-200' : 'bg-white/5 text-brand-100/80')) }}"
                                                title="{{ $shoot->title }}">
                                            {{ $shoot->title }}
                                        </button>
                                        <div x-show="open === {{ $shoot->id }}" x-cloak class="mb-1 p-1.5 rounded bg-white/5 ring-1 ring-white/10 text-[10px] space-y-1">
                                            <p class="text-white font-semibold truncate">{{ $shoot->title }}</p>
                                            @if ($shoot->client)
                                                <p class="truncate">{{ $shoot->client->name }}</p>
                                            @endif
                                            <p class="text-brand-100/60">{{ $shoot->starts_at->format('g:ia') }}@if ($shoot->location) · {{ $shoot->location }} @endif</p>
                                            <a href="{{ route('shoots.show', $shoot) }}" class="text-brand-300 font-semibold">Open →</a>
                                        </div>
                                    @endforeach

                                    @if ($day['shoots']->count() > 3)
                                        <p class="text-[10px] text-brand-100/60 px-1">+{{ $day['shoots']->count() - 3 }} more</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
