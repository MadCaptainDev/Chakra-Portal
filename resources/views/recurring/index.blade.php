<x-app-layout title="Recurring">
    <x-slot name="header">
        <x-page-header title="Recurring Invoices">
            <x-slot name="actions">
                <a href="{{ route('recurring.create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    + New Schedule
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($schedules->isEmpty())
            <x-empty-state message="No recurring schedules yet.">
                <a href="{{ route('recurring.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Set up your first schedule &rarr;</a>
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($schedules as $schedule)
                    <div class="bg-white shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 truncate">{{ $schedule->client->name }}</p>
                                @if ($schedule->label)
                                    <p class="text-sm text-gray-500 truncate">{{ $schedule->label }}</p>
                                @endif
                                <p class="text-sm text-gray-500">{{ \App\Models\RecurringInvoice::FREQUENCIES[$schedule->frequency] }} &middot; Next: {{ $schedule->next_run_on->format('d/m/Y') }}</p>
                            </div>
                            <x-badge :status="$schedule->is_active ? 'active' : 'inactive'" />
                        </div>
                        <div class="mt-3 flex items-center gap-4">
                            <a href="{{ route('recurring.edit', $schedule) }}" class="text-brand-500 font-semibold text-sm min-h-[44px] flex items-center">Edit</a>
                            <form method="POST" action="{{ route('recurring.toggle', $schedule) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-gray-600 font-semibold text-sm min-h-[44px]">{{ $schedule->is_active ? 'Pause' : 'Activate' }}</button>
                            </form>
                            <form method="POST" action="{{ route('recurring.destroy', $schedule) }}" onsubmit="return confirm('Delete this schedule?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 font-semibold text-sm min-h-[44px]">Delete</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Label</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Frequency</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Run</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($schedules as $schedule)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $schedule->client->name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $schedule->label }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ \App\Models\RecurringInvoice::FREQUENCIES[$schedule->frequency] }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $schedule->next_run_on->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$schedule->is_active ? 'active' : 'inactive'" /></td>
                                <td class="px-6 py-4 text-right text-sm space-x-3 whitespace-nowrap">
                                    <a href="{{ route('recurring.edit', $schedule) }}" class="text-brand-500 hover:text-brand-600 font-semibold">Edit</a>
                                    <form method="POST" action="{{ route('recurring.toggle', $schedule) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-gray-600 hover:text-gray-900 font-semibold">{{ $schedule->is_active ? 'Pause' : 'Activate' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('recurring.destroy', $schedule) }}" class="inline" onsubmit="return confirm('Delete this schedule?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900 font-semibold">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $schedules->links() }}
        </div>
    </div>
</x-app-layout>
