@php
    /*
     * Three questions on one screen, in the order somebody actually asks
     * them: what is wrong now, where did the time go, and who pays.
     *
     * "Needs attention" sits first and disappears entirely when there is
     * nothing wrong -- an empty list is the good outcome, and a heading over
     * a blank box just trains people to scroll past the section.
     */
    $money = fn ($amount) => '₹'.number_format((float) $amount, 0);
    $period = $wholeYear ? $month->format('Y') : $month->format('F Y');
@endphp

<x-app-layout title="Insights">
    <x-slot name="header">
        <x-page-header
            title="Insights"
            subtitle="Where the studio's hours went, what they were billed for, and who actually pays." />
    </x-slot>

    <div class="space-y-6">

        {{-- ——— What is off right now ——— --}}
        @if ($attention->isNotEmpty())
            <x-card class="divide-y divide-white/10">
                <div class="p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-white">Needs attention</h2>
                    <p class="text-xs text-brand-100/60 mt-0.5">
                        {{ $attention->count() }} {{ Str::plural('thing', $attention->count()) }} to deal with today.
                    </p>
                </div>
                @foreach ($attention as $item)
                    <a href="{{ $item['url'] }}" class="flex items-start gap-3 p-4 sm:p-5 hover:bg-white/5 transition">
                        <span @class([
                            'mt-1.5 w-2 h-2 rounded-full shrink-0',
                            'bg-red-400' => $item['severity'] === 'urgent',
                            'bg-amber-400' => $item['severity'] !== 'urgent',
                        ])></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-white">{{ $item['title'] }}</span>
                            <span class="block text-xs text-brand-100/60 mt-0.5">{{ $item['detail'] }}</span>
                        </span>
                    </a>
                @endforeach
            </x-card>
        @endif

        {{-- ——— Effort against revenue ——— --}}
        <x-month-nav route="insights.index" :month="$month"
                     :params="$wholeYear ? ['range' => 'year'] : []"
                     subtitle="Hours logged against money billed" />

        <div class="flex items-center gap-2 text-xs">
            <a href="{{ route('insights.index', ['month' => $month->format('Y-m')]) }}"
               @class(['px-3 py-1.5 rounded-lg ring-1 transition',
                   'bg-brand-500/20 ring-brand-400/40 text-white font-semibold' => ! $wholeYear,
                   'ring-white/10 text-brand-100/70 hover:text-white' => $wholeYear])>This month</a>
            <a href="{{ route('insights.index', ['month' => $month->format('Y-m'), 'range' => 'year']) }}"
               @class(['px-3 py-1.5 rounded-lg ring-1 transition',
                   'bg-brand-500/20 ring-brand-400/40 text-white font-semibold' => $wholeYear,
                   'ring-white/10 text-brand-100/70 hover:text-white' => ! $wholeYear])>Whole of {{ $month->format('Y') }}</a>
        </div>

        <x-card padding="md">
            <x-section-heading
                title="Effort vs revenue — {{ $period }}"
                subtitle="What each client cost in hours, and what they were invoiced for it." />

            @if ($effort['totals']['hours'] <= 0)
                <p class="text-sm text-brand-100/60">No hours were logged in {{ $period }}.</p>
            @else
                <div class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-[11px] uppercase tracking-wider text-brand-100/50 border-b border-white/10">
                                <th class="text-left font-semibold py-2 px-4 sm:px-2">Client</th>
                                <th class="text-right font-semibold py-2 px-2">Hours</th>
                                <th class="text-right font-semibold py-2 px-2">Share</th>
                                <th class="text-right font-semibold py-2 px-2">Invoiced</th>
                                <th class="text-right font-semibold py-2 px-2">Collected</th>
                                <th class="text-right font-semibold py-2 px-4 sm:px-2">Per hour</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($effort['rows'] as $row)
                                <tr class="hover:bg-white/5 transition">
                                    <td class="py-2.5 px-4 sm:px-2 text-white">
                                        @if ($row['client'])
                                            <a href="{{ route('clients.show', $row['client']) }}" class="hover:underline">{{ $row['name'] }}</a>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-2 text-right tabular-nums text-brand-100/80">{{ number_format($row['hours'], 1) }}</td>
                                    <td class="py-2.5 px-2 text-right tabular-nums text-brand-100/50">{{ $row['share'] }}%</td>
                                    <td class="py-2.5 px-2 text-right tabular-nums text-white">{{ $money($row['invoiced']) }}</td>
                                    <td class="py-2.5 px-2 text-right tabular-nums text-brand-100/60">{{ $money($row['collected']) }}</td>
                                    <td class="py-2.5 px-4 sm:px-2 text-right tabular-nums font-semibold
                                        {{ $row['per_hour'] !== null && $row['per_hour'] < ($effort['totals']['per_hour'] ?? 0) ? 'text-amber-300' : 'text-white' }}">
                                        {{ $row['per_hour'] !== null ? $money($row['per_hour']) : '—' }}
                                    </td>
                                </tr>
                            @endforeach

                            @if ($effort['unassigned']['hours'] > 0)
                                <tr class="text-brand-100/50">
                                    <td class="py-2.5 px-4 sm:px-2">
                                        Not billed to a client
                                        <span class="block text-[11px] mt-0.5">{{ Str::limit(implode(', ', $effort['unassigned']['ventures']), 90) }}</span>
                                    </td>
                                    <td class="py-2.5 px-2 text-right tabular-nums">{{ number_format($effort['unassigned']['hours'], 1) }}</td>
                                    <td class="py-2.5 px-2 text-right tabular-nums">{{ $effort['unassigned']['share'] }}%</td>
                                    <td colspan="3"></td>
                                </tr>
                            @endif
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/10 font-semibold text-white">
                                <td class="py-2.5 px-4 sm:px-2">Total</td>
                                <td class="py-2.5 px-2 text-right tabular-nums">{{ number_format($effort['totals']['hours'], 1) }}</td>
                                <td></td>
                                <td class="py-2.5 px-2 text-right tabular-nums">{{ $money($effort['totals']['invoiced']) }}</td>
                                <td class="py-2.5 px-2 text-right tabular-nums">{{ $money($effort['totals']['collected']) }}</td>
                                <td class="py-2.5 px-4 sm:px-2 text-right tabular-nums">
                                    {{ $effort['totals']['per_hour'] !== null ? $money($effort['totals']['per_hour']) : '—' }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="text-xs text-brand-100/50 mt-3">
                    Per hour is what was invoiced divided by the hours logged against that client — amber means below
                    the studio average of {{ $effort['totals']['per_hour'] !== null ? $money($effort['totals']['per_hour']) : '—' }}.
                    Invoices are counted by their own date, so work billed next month lands there, not here.
                </p>
            @endif
        </x-card>

        {{-- ——— Who pays ——— --}}
        <x-card padding="md">
            <x-section-heading
                title="Who pays, and when"
                subtitle="Days between the due date and the money arriving. Negative means early." />

            @if ($payers->isEmpty())
                <p class="text-sm text-brand-100/60">No payments against a dated invoice yet.</p>
            @else
                <div class="divide-y divide-white/5">
                    @foreach ($payers as $payer)
                        <div class="flex items-center gap-4 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-white truncate">
                                    {{ $payer['name'] }}
                                    @unless ($payer['confident'])
                                        <span class="text-[11px] text-brand-100/40">· only {{ $payer['payments'] }} so far</span>
                                    @endunless
                                </p>
                                <p class="text-xs text-brand-100/50">
                                    {{ $payer['payments'] }} {{ Str::plural('payment', $payer['payments']) }},
                                    {{ $money($payer['paid']) }} · last {{ $payer['last_paid_on']?->format('j M Y') }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <p @class(['text-sm font-semibold tabular-nums',
                                    'text-red-300' => $payer['average_days'] > 7,
                                    'text-amber-300' => $payer['average_days'] > 0 && $payer['average_days'] <= 7,
                                    'text-emerald-300' => $payer['average_days'] <= 0])>
                                    {{ $payer['average_days'] > 0 ? '+' : '' }}{{ $payer['average_days'] }} days
                                </p>
                                @if ($payer['worst_days'] > $payer['average_days'])
                                    <p class="text-[11px] text-brand-100/40">worst +{{ $payer['worst_days'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

    </div>
</x-app-layout>
