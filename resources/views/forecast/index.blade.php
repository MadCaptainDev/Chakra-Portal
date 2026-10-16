{{--
    Book shoots: the clients about to run out of reels with no shoot booked
    in time, soonest first -- what to do, not a table to read. The numbers
    come from ContentForecast; clients that are covered or have no reel
    target sit folded away at the bottom.
--}}
@php
    $when = fn ($d) => $d === null ? '—' : ($d->isToday() ? 'today' : ($d->isTomorrow() ? 'tomorrow' : $d->format('D j M')));
    $canBook = Route::has('shoots.create') && auth()->user()->can('shoots.create');
@endphp
<x-app-layout title="Book shoots">
    <x-slot name="header">
        <x-page-header title="Book shoots"
                       subtitle="Clients running out of reels with no shoot booked before then. Book one and they drop off this list." />
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-8">
        @foreach ([
            ['rows' => $now, 'title' => 'Book now', 'hint' => 'Runs out within a week', 'tone' => 'red'],
            ['rows' => $soon, 'title' => 'Book soon', 'hint' => 'Runs out within 3 weeks', 'tone' => 'amber'],
        ] as $group)
            <section>
                <h2 class="mb-3 flex items-baseline gap-2 text-lg font-bold text-white">
                    {{ $group['title'] }}
                    <span class="rounded-full px-2.5 py-0.5 text-[13px] {{ $group['tone'] === 'red' ? 'bg-red-500/20 text-red-200' : 'bg-amber-400/15 text-amber-200' }}">{{ $group['rows']->count() }}</span>
                    <span class="text-xs font-normal text-brand-100/50">{{ $group['hint'] }}</span>
                </h2>
                <div class="space-y-2.5">
                    @forelse ($group['rows'] as $row)
                        @php $late = $row['book_by_date']?->lt(today()); @endphp
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl border p-4 {{ $group['tone'] === 'red' ? 'border-red-400/30 bg-red-500/[0.06]' : 'border-amber-400/25 bg-amber-400/[0.04]' }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('forecast.show', $row['client']) }}" class="text-[15px] font-semibold text-white hover:text-brand-300">{{ $row['client']->name }}</a>
                                <p class="mt-1 text-sm text-brand-100/75">
                                    <span class="font-semibold text-white">{{ $row['remaining'] }} {{ Str::plural('reel', $row['remaining']) }} left</span>
                                    · {{ $row['remaining'] > 0 ? 'runs out '.$when($row['depletion_date']) : 'already out' }}
                                    · <span class="{{ $late ? 'font-semibold text-red-300' : '' }}">{{ $late ? 'should have been booked by '.$when($row['book_by_date']) : 'book by '.$when($row['book_by_date']) }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-brand-100/50">
                                    {{ $row['next_shoot_date'] ? 'Next shoot '.$when($row['next_shoot_date']).' — too late' : 'No shoot booked' }}
                                    · {{ number_format($row['weekly_cadence'], 1) }} reels a week
                                </p>
                            </div>
                            @if ($canBook)
                                <a href="{{ route('shoots.create', ['client_id' => $row['client']->id]) }}"
                                   class="inline-flex h-10 items-center rounded-[10px] bg-brand-400 px-4 text-sm font-bold text-brand-900 hover:bg-brand-300">Book shoot</a>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-2xl border border-dashed border-white/15 px-4 py-5 text-center text-sm text-brand-100/50">Nobody here.</p>
                    @endforelse
                </div>
            </section>
        @endforeach

        <section class="space-y-2.5">
            <details class="rounded-2xl border border-white/10 bg-white/[0.03]">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-emerald-200">✓ {{ $covered->count() }} {{ Str::plural('client', $covered->count()) }} covered <span class="font-normal text-brand-100/50">— enough reels, or a shoot booked in time</span></summary>
                <div class="divide-y divide-white/5 border-t border-white/10">
                    @foreach ($covered as $row)
                        <a href="{{ route('forecast.show', $row['client']) }}" class="flex flex-wrap justify-between gap-2 px-4 py-2.5 text-sm hover:bg-white/[0.03]">
                            <span class="font-medium text-white">{{ $row['client']->name }}</span>
                            <span class="text-brand-100/60">{{ $row['remaining'] }} left · {{ $row['next_shoot_date'] ? 'shoot '.$when($row['next_shoot_date']) : 'lasts till '.$when($row['depletion_date']) }}</span>
                        </a>
                    @endforeach
                </div>
            </details>
            @if ($unknown->isNotEmpty())
                <details class="rounded-2xl border border-white/10 bg-white/[0.03]">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-amber-200">{{ $unknown->count() }} {{ Str::plural('client', $unknown->count()) }} can't be counted <span class="font-normal text-brand-100/50">— not linked to Notion, or no reel target</span></summary>
                    <div class="divide-y divide-white/5 border-t border-white/10">
                        @foreach ($unknown as $row)
                            <div class="flex justify-between gap-2 px-4 py-2.5 text-sm">
                                <span class="font-medium text-white">{{ $row['client']->name }}
                                    <span class="block text-xs font-normal text-brand-100/50">{{ $row['linked'] ? 'No monthly reel target set' : 'Notion venture not set — their videos are not counted' }}</span>
                                </span>
                                @can('clients.edit')
                                    <a href="{{ route('clients.edit', $row['client']) }}" class="shrink-0 text-brand-300 hover:text-white">{{ $row['linked'] ? 'Set reel target' : 'Set Notion venture' }}</a>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </section>
    </div>
</x-app-layout>
