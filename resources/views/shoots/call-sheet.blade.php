@php
    /*
    | The call sheet. Read on a phone at 5:40am far more often than it is
    | printed, so it is a web page with print styles rather than a PDF.
    | Internal notes are deliberately absent: this gets forwarded.
    */
    $kitByCategory = $shoot->kits->groupBy(fn ($line) => $line->item?->categoryLabel() ?? 'Other');
@endphp

<x-app-layout :title="'Call sheet — '.$shoot->title">
    <x-slot name="header">
        <x-page-header title="Call sheet" :eyebrow="$shoot->title">
            <x-slot name="actions">
                <x-btn type="button" variant="secondary" icon="printer" onclick="window.print()">Print</x-btn>
                <x-btn :href="route('shoots.show', $shoot)" variant="secondary">Back</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-6 sm:p-8 print:shadow-none print:ring-0">

            <div class="text-center pb-5 border-b border-gray-200">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-400">{{ $shoot->starts_at->format('l') }}</p>
                <p class="mt-1 text-3xl font-extrabold text-gray-900">{{ $shoot->starts_at->format('j F Y') }}</p>
                <p class="mt-3 text-sm font-semibold uppercase tracking-widest text-brand-600">Call</p>
                <p class="text-4xl font-extrabold text-gray-900 tabular-nums leading-none">{{ $shoot->starts_at->format('H:i') }}</p>
                @if ($shoot->ends_at)
                    <p class="mt-2 text-sm text-gray-500">Expected wrap {{ $shoot->ends_at->format('H:i') }}</p>
                @endif
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 py-5 border-b border-gray-200 text-sm">
                @foreach (array_filter([
                    'Shoot' => $shoot->title,
                    'Client' => $shoot->clientLabel(),
                    'Location' => $shoot->location,
                ]) as $label => $value)
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $label }}</dt>
                        <dd class="mt-0.5 text-gray-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($shoot->crew->isNotEmpty())
                <div class="py-5 border-b border-gray-200">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400 mb-3">Crew</p>
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($shoot->crew as $member)
                                <tr class="border-b border-gray-50 last:border-0">
                                    <td class="py-2 font-medium text-gray-900">{{ $member->user?->name }}</td>
                                    <td class="py-2 text-gray-500">{{ $member->role }}</td>
                                    <td class="py-2 text-right tabular-nums text-gray-700">
                                        {{ $member->call_time ? \Illuminate\Support\Str::of($member->call_time)->substr(0, 5) : $shoot->starts_at->format('H:i') }}
                                    </td>
                                    <td class="py-2 text-right">
                                        @if ($member->user?->phone)
                                            <a href="tel:{{ $member->user->phone }}" class="text-brand-600 print:text-gray-900">{{ $member->user->phone }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($shoot->scripts->isNotEmpty())
                <div class="py-5 border-b border-gray-200">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400 mb-2">Scripts</p>
                    <ul class="text-sm text-gray-900 space-y-1">
                        @foreach ($shoot->scripts as $script)
                            <li>{{ $script->title }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($shoot->kits->isNotEmpty())
                <div class="py-5">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400 mb-3">Kit — {{ $shoot->kits->count() }} {{ Str::plural('item', $shoot->kits->count()) }}</p>

                    @foreach ($kitByCategory as $category => $lines)
                        <p class="text-xs font-semibold text-gray-700 mt-3 first:mt-0">{{ $category }}</p>
                        <ul class="mt-1 text-sm text-gray-900 space-y-0.5">
                            @foreach ($lines as $line)
                                <li class="flex items-center gap-2">
                                    <span class="inline-block w-3 h-3 border border-gray-400 rounded-sm shrink-0"></span>
                                    {{ $line->item?->name }}@if ($line->quantity > 1) <span class="text-gray-500">×{{ $line->quantity }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </div>
            @endif

            <p class="pt-4 border-t border-gray-200 text-[11px] text-gray-400">
                Chakra Productions &middot; generated {{ now()->format('j M Y, H:i') }}
            </p>
        </x-card>
    </div>

    @push('styles')
    <style>
        @media print {
            @page { size: A4; margin: 14mm; }
            tr, li { page-break-inside: avoid; }
            a[href]::after { content: none; }
        }
    </style>
    @endpush
</x-app-layout>
