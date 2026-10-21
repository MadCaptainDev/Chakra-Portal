{{--
    Shoots → Crew record. Everyone's shoot count by month, or one person's
    full past list (?person=). See ShootCrewRecordController.
--}}
<x-app-layout title="{{ $person ? $person->name.' — shoots' : 'Crew record' }}">
    <x-slot name="header">
        <x-page-header :title="$person ? $person->name : 'Crew record'" eyebrow="Shoots"
                       :subtitle="$person ? 'Every past shoot they were on, newest first.' : 'Past shoots per person, month by month. Cancelled shoots are not counted.'">
            <x-slot name="actions">
                @if ($person)
                    <x-btn :href="route('shoots.crew-record')" variant="secondary">All crew</x-btn>
                @endif
                <x-btn :href="route('shoots.index')" variant="secondary" icon="camera">Shoots</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-4xl">
        @if ($person)
            <div class="mx-auto max-w-xl">
                @include('shoots._crew-history', ['shoots' => $shoots, 'person' => $person])
            </div>
        @elseif ($team['people']->isEmpty())
            <x-empty-state message="No crew on any past shoot yet. Add people to a shoot's crew and they appear here." />
        @else
            <div class="overflow-x-auto rounded-2xl bg-white/[0.03] ring-1 ring-white/10">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-white/10 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-100/50">
                            <th class="px-4 py-3">Person</th>
                            @foreach ($team['months'] as $m)
                                <th class="px-2 py-3 text-right {{ $m->isSameMonth(today()) ? 'text-brand-200' : '' }}">{{ $m->format('M') }}</th>
                            @endforeach
                            <th class="px-3 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Last shoot</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($team['people'] as $p)
                            <tr class="hover:bg-white/[0.03]">
                                <td class="px-4 py-3">
                                    <a href="{{ route('shoots.crew-record', ['person' => $p['id']]) }}" class="font-semibold text-white hover:underline">{{ $p['name'] }}</a>
                                </td>
                                @foreach ($p['months'] as $n)
                                    <td class="px-2 py-3 text-right tabular-nums {{ $n ? 'text-white' : 'text-brand-100/25' }}">{{ $n ?: '–' }}</td>
                                @endforeach
                                <td class="px-3 py-3 text-right font-bold tabular-nums text-white">{{ $p['total'] }}</td>
                                <td class="px-4 py-3 text-right text-xs text-brand-100/60">{{ $p['last']?->format('j M') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-brand-100/50">Tap a name for their full list. Counted from each shoot's crew, including crew imported from Notion's "Team".</p>
        @endif
    </div>
</x-app-layout>
