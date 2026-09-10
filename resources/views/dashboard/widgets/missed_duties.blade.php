@if (($missedRoutinesCount ?? 0) > 0)
    <section>
        <div class="flex items-baseline justify-between gap-4 mb-4">
            <x-section-label dark>Missed duties</x-section-label>
            <a href="{{ route('routines.calendar') }}"
               class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-white">Calendar →</a>
        </div>
        <x-card tone="dark" class="p-5 sm:p-6">
            <p class="text-sm text-brand-100/70 mb-3">
                <span class="text-2xl font-bold tabular-nums text-amber-300">{{ $missedRoutinesCount }}</span>
                open {{ Str::plural('occurrence', $missedRoutinesCount) }} past due.
            </p>
            <div class="divide-y divide-white/5">
                @foreach ($missedRoutines as $occurrence)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0">
                            <span class="block text-sm text-white truncate">{{ $occurrence->routine?->title }}</span>
                            <span class="block text-[11px] text-brand-100/40 truncate">
                                Due {{ $occurrence->due_on->format('j M') }}
                                @if ($occurrence->checkpoint) · {{ $occurrence->checkpoint->name }} @endif
                                @if ($occurrence->subject && method_exists($occurrence->subject, 'displayHandle'))
                                    · {{ $occurrence->subject->displayHandle() }}
                                @endif
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </x-card>
    </section>
@endif
