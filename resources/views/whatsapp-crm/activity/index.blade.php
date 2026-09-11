@php
    // Same tone-by-category idea used across the app's other activity
    // lists: a colour is a category, not a judgement -- there is no "bad"
    // category here, just different ones.
    $categoryColor = [
        'Campaign' => 'bg-brand-400/15 text-brand-200',
        'Shoot reminder' => 'bg-teal-400/15 text-teal-200',
        'Missing content alert' => 'bg-amber-400/15 text-amber-200',
        'Content depletion warning' => 'bg-red-400/15 text-red-200',
        'Invoice ready' => 'bg-emerald-400/15 text-emerald-200',
        'Quotation ready' => 'bg-emerald-400/15 text-emerald-200',
        'Monthly report ready' => 'bg-sky-400/15 text-sky-200',
        'Brand brief nudge' => 'bg-purple-400/15 text-purple-200',
        'Reply' => 'bg-white/10 text-brand-100/70',
    ];
@endphp

<x-app-layout title="WhatsApp Activity">
    <x-slot name="header">
        <x-page-header title="Activity" eyebrow="WhatsApp CRM"
                       subtitle="What actually went out, day by day -- campaigns and every automated alert, in one place." />
    </x-slot>

    <div class="space-y-6">
        <x-card padding="sm">
            <x-day-nav route="whatsapp-crm.activity.index" :day="$day" param="day"
                       :subtitle="$rows->count().' '.Str::plural('message', $rows->count()).' sent'" />
        </x-card>

        {{-- ——— Last 14 days, at a glance ——— --}}
        <x-card padding="md">
            <x-section-heading title="Last 14 days"
                               subtitle="The daily schedule this actually follows -- see below for what's supposed to run when." />

            <div class="mt-4 overflow-x-auto -mx-4 sm:-mx-6">
                <div class="flex items-end gap-2 px-4 sm:px-6" style="min-width: max-content">
                    @php
                        $peak = max(1, $dailyTotals->max('total'));
                    @endphp
                    @foreach ($dailyTotals->reverse() as $point)
                        <a href="{{ route('whatsapp-crm.activity.index', ['day' => $point['date']->toDateString()]) }}"
                           class="group flex flex-col items-center gap-1.5 w-12 shrink-0">
                            <span class="text-[11px] tabular-nums text-brand-100/50 group-hover:text-white">{{ $point['total'] }}</span>
                            <div class="w-full h-24 flex items-end rounded-md bg-white/[0.04] overflow-hidden">
                                <div @class([
                                    'w-full rounded-t-sm transition-colors',
                                    'bg-brand-400 group-hover:bg-brand-300' => ! $point['date']->isSameDay($day),
                                    'bg-white group-hover:bg-white' => $point['date']->isSameDay($day),
                                ]) style="height: {{ $point['total'] > 0 ? max(6, round($point['total'] / $peak * 100)) : 0 }}%"></div>
                            </div>
                            <span @class([
                                'text-[10px]',
                                'text-white font-semibold' => $point['date']->isSameDay($day),
                                'text-brand-100/50 group-hover:text-white' => ! $point['date']->isSameDay($day),
                            ])>{{ $point['date']->format('j M') }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </x-card>

        {{-- ——— The day itself ——— --}}
        <x-card padding="md">
            <x-section-heading :title="$day->format('D j M Y').($day->isToday() ? ' — Today' : '')" />

            @if ($rows->isEmpty())
                <x-empty-state message="Nothing sent this day." />
            @else
                <div class="mt-3 overflow-x-auto -mx-4 sm:-mx-6">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                <th class="px-4 sm:px-6 py-2">Time</th>
                                <th class="px-3 py-2">Category</th>
                                <th class="px-3 py-2">To</th>
                                <th class="px-4 sm:px-6 py-2">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach ($rows as $row)
                                @php
                                    $event = $row['event'];
                                @endphp
                                <tr class="align-top">
                                    <td class="px-4 sm:px-6 py-2.5 whitespace-nowrap text-brand-100/60 text-xs">
                                        {{ $event->occurred_at->format('H:i') }}
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $categoryColor[$row['category']] ?? 'bg-white/10 text-brand-100/70' }}">
                                            {{ $row['category'] }}
                                        </span>
                                        @if ($row['campaign'])
                                            <span class="block text-[11px] text-brand-100/50 mt-0.5 truncate">{{ $row['campaign'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-brand-100/80 font-mono text-xs">
                                        {{ $event->wa_id }}
                                    </td>
                                    <td class="px-4 sm:px-6 py-2.5 text-brand-100/80">
                                        {{ Str::limit($event->summary, 90) ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        {{-- ——— What runs on its own ——— --}}
        <x-card padding="md">
            <x-section-heading title="What runs on a daily schedule"
                               subtitle="The automations behind most of what shows up above -- from routes/console.php, not a live schedule reader." />

            <div class="mt-3 divide-y divide-white/10">
                @foreach ($schedule as $item)
                    <div class="flex items-center justify-between gap-3 py-2.5">
                        <span class="text-sm text-brand-100/80">{{ $item['label'] }}</span>
                        <span class="text-xs font-mono text-brand-100/50 shrink-0">{{ $item['time'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>
</x-app-layout>
