@php
    /*
     * Everything that decides whose Notion content is whose: accounts,
     * their per-type targets, which ventures feed them, and which portal
     * client each Notion shoot belongs to.
     *
     * All of it is ONE form, saved together -- sorting out a mapping
     * touches many rows at once, and a save button per row turns that into
     * dozens of round trips.
     *
     * $i and $j are running indexes because the Notion string is carried as
     * a form VALUE, never as a key: PHP rewrites dots and spaces in request
     * keys to underscores, which would corrupt "Annamalai.mov" and
     * "Surya's Restaurant" into strings that match nothing.
     */
    $i = 0;
    $j = 0;
    $accountsByClient = $accounts->groupBy(fn ($a) => $a->client?->name ?? 'Unknown client');
@endphp

<x-settings-layout title="Content Accounts">
    <x-slot name="header">
        <x-page-header title="Content Accounts"
                       subtitle="Group Notion ventures into accounts, set a monthly target per content type, and map Notion's shoot clients." />
    </x-slot>

    <div class="space-y-5">

        <form method="POST" action="{{ route('content-accounts.update') }}">
            @csrf
            @method('PUT')

            {{-- Unmapped first: it is the only part of this screen that is
                 ever urgent. --}}
            <x-card padding="md" class="mb-5">
                <x-section-heading
                    title="Unmapped ventures"
                    subtitle="Notion ventures with no account. Their content is not counted on the dashboard until they have one." />

                @if ($unmapped->isEmpty())
                    <p class="text-sm text-green-700 bg-green-50 ring-1 ring-green-100 rounded-lg px-3 py-2">
                        Every venture in the synced content is assigned to an account.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                    <th class="py-2">Notion venture</th>
                                    <th class="py-2 text-right w-24">Items</th>
                                    <th class="py-2 w-72">Assign to account</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($unmapped as $row)
                                    <tr>
                                        <td class="py-2.5 font-medium text-gray-900">
                                            {{ $row->venture }}
                                            <input type="hidden" name="map[{{ $i }}][venture]" value="{{ $row->venture }}">
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-gray-500">{{ number_format($row->items) }}</td>
                                        <td class="py-2.5">
                                            <select name="map[{{ $i }}][account_id]" class="w-full rounded-md border-gray-300 text-sm py-1.5">
                                                <option value="">— leave unmapped —</option>
                                                @foreach ($accountsByClient as $clientName => $group)
                                                    <optgroup label="{{ $clientName }}">
                                                        @foreach ($group as $account)
                                                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                    @php $i++; @endphp
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card padding="md" class="mb-5">
                <x-section-heading
                    title="Accounts and targets"
                    subtitle="A target per content type. Only accounts with at least one target appear on the Content Dashboard." />

                @if ($accounts->isEmpty())
                    <x-empty-state message="No accounts yet — add one below." />
                @else
                    @foreach ($accountsByClient as $clientName => $group)
                        <div class="mb-5 last:mb-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-600 mb-2">{{ $clientName }}</p>

                            <div class="space-y-3">
                                @foreach ($group as $account)
                                    <div class="rounded-lg ring-1 ring-gray-900/5 bg-gray-50/60 p-3">
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div class="flex-1 min-w-[180px]">
                                                <x-input-label :for="'name-'.$account->id" value="Account name" />
                                                <x-text-input :id="'name-'.$account->id" type="text"
                                                              name="names[{{ $account->id }}]"
                                                              class="mt-1 w-full"
                                                              value="{{ old('names.'.$account->id, $account->name) }}" />
                                            </div>
                                            @foreach ($targetable as $source => $label)
                                                <div class="w-32">
                                                    <x-input-label :for="'t-'.$account->id.'-'.$source" :value="$label" />
                                                    <x-text-input :id="'t-'.$account->id.'-'.$source" type="number" min="0" max="9999"
                                                                  name="targets[{{ $account->id }}][{{ $source }}]"
                                                                  class="mt-1 w-full" placeholder="none"
                                                                  value="{{ old('targets.'.$account->id.'.'.$source, $account->targetFor($source)) }}" />
                                                </div>
                                            @endforeach
                                            <div class="pb-1">
                                                <button type="submit" form="delete-{{ $account->id }}"
                                                        class="text-xs font-semibold uppercase tracking-widest text-red-600 hover:text-red-800"
                                                        onclick="return confirm('Delete {{ $account->name }}? Its ventures become unmapped — no content is deleted.')">
                                                    Delete
                                                </button>
                                            </div>
                                        </div>

                                        @if ($account->ventures->isNotEmpty())
                                            <div class="mt-3 pt-3 border-t border-gray-200/70 space-y-2">
                                                @foreach ($account->ventures as $venture)
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="text-sm text-gray-700 flex-1 min-w-[160px]">
                                                            {{ $venture->venture }}
                                                            <span class="text-[11px] text-gray-400">({{ number_format($ventureCounts[$venture->venture] ?? 0) }})</span>
                                                        </span>
                                                        <input type="hidden" name="map[{{ $i }}][venture]" value="{{ $venture->venture }}">
                                                        <select name="map[{{ $i }}][account_id]" class="rounded-md border-gray-300 text-xs py-1 w-56">
                                                            <option value="">— unmap —</option>
                                                            @foreach ($accountsByClient as $optClient => $optGroup)
                                                                <optgroup label="{{ $optClient }}">
                                                                    @foreach ($optGroup as $opt)
                                                                        <option value="{{ $opt->id }}" @selected($opt->id === $account->id)>{{ $opt->name }}</option>
                                                                    @endforeach
                                                                </optgroup>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    @php $i++; @endphp
                                                @endforeach
                                            </div>
                                        @else
                                            <p class="mt-2 text-[11px] text-amber-600">No ventures assigned — this account will always show zero.</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            </x-card>

            {{-- Notion's shoot "Client" is its own free-text list and does
                 not overlap the venture names, so it needs its own mapping
                 rather than reusing the one above. --}}
            <x-card padding="md" class="mb-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <x-section-heading
                        title="Notion shoot clients"
                        subtitle="Which portal client each Notion shoot belongs to. Unmapped shoots import with no client attached." />
                    <button type="submit" form="auto-map-shoots"
                            class="shrink-0 text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                        Auto-match exact names
                    </button>
                </div>

                @if ($shootClients->isEmpty())
                    <x-empty-state message="No shoots synced from Notion yet." />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                    <th class="py-2">Notion client</th>
                                    <th class="py-2 text-right w-24">Shoots</th>
                                    <th class="py-2 w-72">Portal client</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($shootClients as $row)
                                    <tr>
                                        <td class="py-2.5 font-medium text-gray-900">
                                            {{ $row->client }}
                                            <input type="hidden" name="shootMap[{{ $j }}][client]" value="{{ $row->client }}">
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-gray-500">{{ $row->shoots }}</td>
                                        <td class="py-2.5">
                                            <select name="shootMap[{{ $j }}][client_id]" class="w-full rounded-md border-gray-300 text-sm py-1.5">
                                                <option value="">— unmapped —</option>
                                                @foreach ($clients as $client)
                                                    <option value="{{ $client->id }}" @selected((int) $row->client_id === $client->id)>{{ $client->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                    @php $j++; @endphp
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-5 pt-4 border-t border-gray-100">
                    <x-primary-button>Save all changes</x-primary-button>
                </div>
            </x-card>
        </form>

        {{-- Outside the main form: nested forms are invalid HTML, so the
             buttons above target these by id. --}}
        <form id="auto-map-shoots" method="POST" action="{{ route('content-accounts.auto-map-shoots') }}" class="hidden">@csrf</form>
        @foreach ($accounts as $account)
            <form id="delete-{{ $account->id }}" method="POST"
                  action="{{ route('content-accounts.destroy', $account) }}" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <x-card padding="md">
            <x-section-heading title="Add an account"
                               subtitle="For a client that runs more than one publishing identity, add one per identity." />

            <form method="POST" action="{{ route('content-accounts.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-[180px]">
                    <x-input-label for="client_id" value="Client" />
                    <select id="client_id" name="client_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm py-1.5">
                        <option value="">Choose a client…</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
                </div>
                <div class="flex-1 min-w-[160px]">
                    <x-input-label for="new-name" value="Account name" />
                    <x-text-input id="new-name" name="name" type="text" class="mt-1 w-full"
                                  placeholder="e.g. SVA Womenswear" value="{{ old('name') }}" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                @foreach ($targetable as $source => $label)
                    <div class="w-32">
                        <x-input-label :for="'new-t-'.$source" :value="$label" />
                        <x-text-input :id="'new-t-'.$source" :name="'target_'.$source" type="number" min="0" max="9999"
                                      class="mt-1 w-full" placeholder="optional" value="{{ old('target_'.$source) }}" />
                    </div>
                @endforeach
                <div class="pb-0.5">
                    <x-primary-button>Add</x-primary-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
