@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $monthParam = $month->format('Y-m');
@endphp

<x-app-layout title="Quotations">
    <x-slot name="header">
        <x-page-header title="Quotations" eyebrow="Finance"
                       subtitle="What has been quoted this month, and what still needs a decision.">
            <x-slot name="actions">
                <x-btn :href="route('quotations.create')" icon="plus">New quotation</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $statusFilters = [
            '' => 'All',
            'draft' => 'Draft',
            'expired' => 'Expired',
            'accepted' => 'Accepted',
            'converted' => 'Converted',
            'rejected' => 'Rejected',
        ];
    @endphp

    <div class="space-y-4">
        <x-filter-bar>
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('quotations.index', array_filter(['month' => $prev, 'status' => $status, 'type' => $type, 'search' => $search])) }}"
                   class="inline-flex items-center min-h-[44px] px-3 rounded-lg bg-white/5 ring-1 ring-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]">&larr; Prev</a>

                <div class="text-center">
                    <p class="font-semibold text-white">{{ $month->format('F Y') }}</p>
                    <p class="text-xs text-brand-100/60">
                        {{ $quotations->total() }} {{ Str::plural('quotation', $quotations->total()) }}
                        &middot; {{ number_format($monthTotal, 2) }} quoted
                    </p>
                    @if (! $month->isSameMonth(now()))
                        <a href="{{ route('quotations.index', array_filter(['status' => $status, 'type' => $type, 'search' => $search])) }}"
                           class="text-xs text-brand-500 hover:text-brand-300">Back to this month</a>
                    @endif
                </div>

                <a href="{{ route('quotations.index', array_filter(['month' => $next, 'status' => $status, 'type' => $type, 'search' => $search])) }}"
                   class="inline-flex items-center min-h-[44px] px-3 rounded-lg bg-white/5 ring-1 ring-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]">Next &rarr;</a>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($statusFilters as $value => $label)
                    <a href="{{ route('quotations.index', array_filter(['month' => $monthParam, 'search' => $search, 'status' => $value, 'type' => $type])) }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $status === $value ? 'bg-brand-400 text-brand-900' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Chakra Production vs Chakra App Studio -- just the label,
                 no AMC/development split (a quotation carries no real
                 SaasProduct yet). --}}
            <div class="flex flex-wrap gap-2">
                @foreach (['' => 'All work', 'production' => 'Production', 'studio' => 'App Studio'] as $value => $label)
                    <a href="{{ route('quotations.index', array_filter(['month' => $monthParam, 'search' => $search, 'status' => $status, 'type' => $value])) }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $type === $value ? 'bg-brand-900 text-white' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('quotations.index') }}">
                <input type="hidden" name="month" value="{{ $monthParam }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="search" name="search" value="{{ $search }}" placeholder="Search by quotation number or client name..."
                    class="w-full sm:max-w-md rounded-lg border-white/15 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            </form>
        </x-filter-bar>

        @forelse ($quotations as $quotation)
        @empty
            <x-empty-state message="No quotations in {{ $month->format('F Y') }}{{ $status || $type || $search ? ' for this filter' : '' }}.">
                <x-btn :href="route('quotations.create')" icon="plus" size="sm">Create a quotation</x-btn>
            </x-empty-state>
        @endforelse

        @if ($quotations->isNotEmpty())
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($quotations as $quotation)
                    <a href="{{ route('quotations.show', $quotation) }}" class="block bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-white">{{ $quotation->quotation_number ?? 'Pending' }}</span>
                            <x-badge :status="$quotation->displayStatus()" />
                        </div>
                        <div class="mt-1 flex items-center justify-between text-sm text-brand-100/60">
                            <span>
                                {{ $quotation->client->name }}
                                @if ($quotation->is_app_studio)
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-indigo-400/15 text-indigo-200">
                                        App Studio
                                    </span>
                                @endif
                            </span>
                            <span>{{ $quotation->quotation_date->format('d/m/Y') }}</span>
                        </div>
                        <div class="mt-1 text-right text-white font-semibold">{{ number_format($quotation->total, 2) }}</div>
                    </a>
                @endforeach

                <div class="bg-white/5 shadow-sm rounded-lg p-4 flex items-center justify-between">
                    <p class="text-sm font-semibold text-brand-100/80">Sum</p>
                    <p class="text-white font-semibold">{{ number_format($monthTotal, 2) }}</p>
                </div>
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Quotation #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-brand-100/60 uppercase">Total</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-brand-100/60 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($quotations as $quotation)
                            <tr class="hover:bg-white/[0.09]">
                                <td class="px-6 py-4 text-sm font-medium text-white">{{ $quotation->quotation_number ?? 'Pending' }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">
                                    {{ $quotation->client->name }}
                                    @if ($quotation->is_app_studio)
                                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-indigo-400/15 text-indigo-200">
                                            App Studio
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $quotation->quotation_date->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$quotation->displayStatus()" /></td>
                                <td class="px-6 py-4 text-sm text-white text-right">{{ number_format($quotation->total, 2) }}</td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="inline-flex items-center justify-end gap-3">
                                        <a href="{{ route('quotations.pdf', $quotation) }}"
                                           class="inline-flex items-center justify-center min-h-[44px] px-2 font-semibold text-brand-500 hover:text-brand-300"
                                           title="Download PDF">
                                            PDF
                                        </a>
                                        <a href="{{ route('quotations.show', $quotation) }}" class="inline-flex items-center justify-center min-h-[44px] px-2 text-brand-500 hover:text-brand-300 font-semibold">View</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-brand-900/40">
                            <td colspan="4" class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-brand-100/60">Sum</td>
                            <td class="px-6 py-3 text-sm font-bold text-white text-right">{{ number_format($monthTotal, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </x-card>
        @endif

        <div>
            {{ $quotations->links() }}
        </div>
    </div>
</x-app-layout>
