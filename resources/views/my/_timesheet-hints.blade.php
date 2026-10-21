{{--
    Hints for the timesheet (App\Support\TimesheetHints): what My Editing, My
    Shoots and posting already say you did, as rows to add with one tap. The
    tap opens the add form filled in; nothing is saved until you save it.
--}}
@php
    $open = collect($hints)->where('logged', false)->values();
    $done = collect($hints)->where('logged', true)->count();
@endphp
@if ($hints)
    <section class="rounded-2xl border border-brand-400/25 bg-brand-400/[0.06] p-4 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-white">💡 {{ $hintDay->isToday() ? 'From your day' : 'From yesterday' }}</h2>
            <p class="text-xs text-brand-100/60">From My Editing, My Shoots and posting{{ $done ? ' · '.$done.' already logged' : '' }}</p>
        </div>
        @forelse ($open as $h)
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl bg-white/[0.04] px-3.5 py-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-white">{{ $h['task'] }}</p>
                    <p class="text-xs text-brand-100/60">
                        {{ $h['start'] ? \Illuminate\Support\Carbon::createFromFormat('H:i', $h['start'])->format('g:i A') : '' }}{{ $h['end'] ? ' – '.\Illuminate\Support\Carbon::createFromFormat('H:i', $h['end'])->format('g:i A') : '' }}
                        @if ($h['client']) · {{ $h['client'] }}@endif
                        · {{ $h['why'] }}
                    </p>
                </div>
                <a href="{{ route('my.timesheet', array_filter(['month' => $hintDay->format('Y-m'), 'task' => $h['task'], 'task_type' => $h['type'], 'venture' => $h['client'], 'worked_on' => $h['date'], 'started_at' => $h['start'], 'ended_at' => $h['end']])) }}#add-entry"
                   class="inline-flex h-9 shrink-0 items-center rounded-lg bg-brand-400 px-3 text-[13px] font-bold text-brand-900 hover:bg-brand-300">Add to timesheet</a>
            </div>
        @empty
            <p class="mt-2 text-sm text-emerald-200/80">Everything from {{ $hintDay->isToday() ? 'today' : 'yesterday' }} is on your timesheet.</p>
        @endforelse
    </section>
@endif
