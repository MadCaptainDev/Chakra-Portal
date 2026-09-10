{{-- ——— The month at a glance ——— --}}
<section>
    <x-section-label dark class="mb-4">The month at a glance</x-section-label>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
        @php
            $glance = [
                ['domain' => 'Team', 'value' => \App\Models\TimesheetEntry::formatMinutes($teamMinutes), 'label' => 'Hours logged',
                 'note' => $teamHours->count().' '.Str::plural('person', $teamHours->count()),
                 'href' => route('timesheets.index'), 'accent' => false],
                ['domain' => 'Money', 'value' => $money($thisMonthRevenue, 0), 'label' => 'Collected',
                 'note' => $money($outstanding, 0).' still owed', 'href' => route('invoices.index'), 'accent' => false],
                ['domain' => 'Money', 'value' => $money($outflowPending, 0), 'label' => 'Still to pay',
                 'note' => $outflowPending > 0 ? 'Clear before month end' : 'All settled',
                 'href' => route('expenses.index'), 'accent' => $outflowPending > 0],
            ];
        @endphp

        @foreach ($glance as $tile)
            <a href="{{ $tile['href'] }}"
               @class([
                   'rounded-xl p-5 transition-colors ring-1',
                   'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40 hover:from-amber-400/30' => $tile['accent'],
                   'bg-white/5 ring-white/10 hover:bg-white/[0.08]' => ! $tile['accent'],
               ])>
                <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-brand-100/50">{{ $tile['domain'] }}</p>
                <p class="mt-2.5 text-2xl sm:text-3xl font-extrabold leading-none tabular-nums tracking-tight">{{ $tile['value'] }}</p>
                <x-section-label dark class="mt-2">{{ $tile['label'] }}</x-section-label>
                <p @class(['mt-1.5 text-xs', 'text-amber-100/80' => $tile['accent'], 'text-brand-100/60' => ! $tile['accent']])>{{ $tile['note'] }}</p>
            </a>
        @endforeach
    </div>
</section>
