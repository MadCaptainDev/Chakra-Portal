<x-app-layout title="Client Advances">
    <x-slot name="header">
        <x-page-header title="Client Advances"
                       subtitle="What the studio paid on a client's behalf, and what is still owed back">
            <x-slot name="actions">
                @can('client-advances.create')
                    <x-btn :href="route('client-advances.create')" icon="plus">Log an advance</x-btn>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        {{-- The three figures the screen exists for. Computed over every
             advance, never the filtered list. --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Still owed to you</p>
                <p class="text-lg sm:text-2xl font-bold text-amber-300">
                    ₹{{ number_format($totalOutstanding, 0) }}
                </p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Recovered</p>
                <p class="text-lg sm:text-2xl font-bold text-emerald-300">
                    ₹{{ number_format($totalRecovered, 0) }}
                </p>
            </x-card>
            <x-card class="p-3 sm:p-4 col-span-2 lg:col-span-1">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Paid out in total</p>
                <p class="text-lg sm:text-2xl font-bold text-white">₹{{ number_format($totalAdvanced, 0) }}</p>
            </x-card>
        </div>

        {{-- Who owes what. Worst first, because that is the reason to open
             this screen. --}}
        @if ($byClient->isNotEmpty())
            <x-card class="p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-white/10">
                    <p class="text-sm font-semibold text-white">By client</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-brand-100/50">
                            <tr class="border-b border-white/10">
                                <th class="text-left font-medium px-4 py-2">Client</th>
                                <th class="text-right font-medium px-4 py-2">Items</th>
                                <th class="text-right font-medium px-4 py-2">Paid out</th>
                                <th class="text-right font-medium px-4 py-2">Recovered</th>
                                <th class="text-right font-medium px-4 py-2">Still owed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byClient as $row)
                                <tr class="border-b border-white/5 last:border-0">
                                    <td class="px-4 py-2.5 text-white">{{ $row['client']?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right text-brand-100/60">{{ $row['count'] }}</td>
                                    <td class="px-4 py-2.5 text-right text-brand-100/70">₹{{ number_format($row['advanced'], 0) }}</td>
                                    <td class="px-4 py-2.5 text-right text-emerald-300/80">₹{{ number_format($row['recovered'], 0) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold {{ $row['outstanding'] > 0 ? 'text-amber-300' : 'text-brand-100/40' }}">
                                        ₹{{ number_format($row['outstanding'], 0) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        {{-- Filters --}}
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs uppercase tracking-wide text-brand-100/60 mb-1">Client</label>
                <select name="client" class="rounded-lg border-0 bg-white/10 px-3 py-2 text-sm text-white">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected($filters['client'] == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-brand-100/60 mb-1">Category</label>
                <select name="category" class="rounded-lg border-0 bg-white/10 px-3 py-2 text-sm text-white">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category'] == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-brand-100/60 mb-1">Showing</label>
                <select name="state" class="rounded-lg border-0 bg-white/10 px-3 py-2 text-sm text-white">
                    <option value="outstanding" @selected($filters['state'] === 'outstanding')>Still owed</option>
                    <option value="recovered" @selected($filters['state'] === 'recovered')>Recovered</option>
                    <option value="all" @selected($filters['state'] === 'all')>Everything</option>
                </select>
            </div>
            <x-btn type="submit" size="sm" variant="secondary">Apply</x-btn>
        </form>

        {{-- The rows themselves --}}
        <x-card class="p-0 overflow-hidden">
            @forelse ($advances as $advance)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-white/5 last:border-0">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-white truncate">
                            {{ $advance->name }}
                            @if ($advance->isRecovered())
                                <span class="ml-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] text-emerald-300">Recovered</span>
                            @endif
                        </p>
                        <p class="text-xs text-brand-100/50 truncate">
                            {{ $advance->client?->name ?? '—' }}
                            · {{ $advance->categoryLabel() }}
                            · {{ $advance->spent_on?->format('j M Y') }}
                            @if ($advance->payee) · paid to {{ $advance->payee }} @endif
                            @if ($advance->isRecovered() && $advance->recovered_note)
                                · {{ $advance->recovered_note }}
                            @endif
                        </p>
                    </div>

                    <div class="text-right shrink-0">
                        <p class="text-sm font-semibold text-white">₹{{ number_format($advance->billable(), 0) }}</p>
                        @if ($advance->margin() != 0.0)
                            {{-- Only worth saying when the two differ. --}}
                            <p class="text-[11px] text-brand-100/40">
                                cost ₹{{ number_format((float) $advance->amount, 0) }}
                            </p>
                        @endif
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0">
                        @if ($advance->receipt_path)
                            <a href="{{ route('client-advances.receipt', $advance) }}"
                               class="rounded-lg p-2 text-brand-100/50 hover:text-white hover:bg-white/10" title="Receipt">
                                <x-icon name="document" class="h-4 w-4" />
                            </a>
                        @endif

                        @can('client-advances.edit')
                            @if ($advance->isRecovered())
                                <form method="POST" action="{{ route('client-advances.recovered.undo', $advance) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded-lg px-2 py-1 text-xs text-brand-100/50 hover:text-white hover:bg-white/10">
                                        Undo
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('client-advances.recovered', $advance) }}"
                                      onsubmit="this.recovered_note.value = prompt('Note — invoice number, or how it was settled:') ?? ''">
                                    @csrf
                                    <input type="hidden" name="recovered_note">
                                    <button type="submit" class="rounded-lg bg-emerald-500/15 px-2.5 py-1 text-xs font-medium text-emerald-300 hover:bg-emerald-500/25">
                                        Got it back
                                    </button>
                                </form>
                            @endif

                            <a href="{{ route('client-advances.edit', $advance) }}"
                               class="rounded-lg p-2 text-brand-100/50 hover:text-white hover:bg-white/10" title="Edit">
                                <x-icon name="pencil" class="h-4 w-4" />
                            </a>
                        @endcan
                    </div>
                </div>
            @empty
                <x-empty-state title="Nothing here"
                               message="Log what you have paid on a client's behalf and it will show up as owed to you until you mark it recovered." />
            @endforelse
        </x-card>
    </div>
</x-app-layout>
