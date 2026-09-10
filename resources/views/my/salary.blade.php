<x-app-layout title="My Salary" dark>
    <x-slot name="header">
        <x-page-header title="My Salary" subtitle="Your current pay and every raise on record. Read only." />
    </x-slot>

    <div class="max-w-2xl space-y-6">
        @if (! $employee)
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="text-sm text-brand-100/70">No salary record is linked to your account yet. Ask an admin to link one.</p>
            </div>
        @else
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-2">Current Salary</p>
                <p class="text-2xl font-bold text-white tabular-nums">
                    {{ number_format($employee->amount, 2) }}<span class="text-sm font-semibold text-brand-100/50">/mo</span>
                </p>
                @if ($employee->joined_on)
                    <p class="mt-1 text-xs text-brand-100/60">Joined {{ $employee->joined_on->format('d M Y') }}</p>
                @endif
            </div>

            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">Salary History</p>

                @if ($hikes->isEmpty())
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                        <p class="text-sm text-brand-100/60">No raises on record yet.</p>
                    </div>
                @else
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 divide-y divide-white/10 overflow-hidden">
                        @foreach ($hikes as $hike)
                            <div class="p-4 sm:p-5 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-white">
                                        {{ number_format($hike->previous_amount, 2) }} &rarr; {{ number_format($hike->new_amount, 2) }}
                                    </p>
                                    <p class="text-xs text-brand-100/60 mt-0.5">
                                        Effective {{ $hike->effective_on->format('d M Y') }}
                                        @if ($hike->reason)
                                            &middot; {{ $hike->reason }}
                                        @endif
                                        @if ($hike->createdBy)
                                            &middot; by {{ $hike->createdBy->name }}
                                        @endif
                                    </p>
                                </div>
                                <p @class([
                                    'font-semibold shrink-0 tabular-nums',
                                    'text-green-400' => $hike->delta() > 0,
                                    'text-red-300' => $hike->delta() < 0,
                                ])>
                                    {{ $hike->delta() > 0 ? '+' : '' }}{{ number_format($hike->delta(), 2) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
