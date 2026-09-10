@php
    /*
     * Everything that decides whose Notion content is whose: accounts,
     * their per-type targets, which ventures feed them, and which portal
     * client each Notion shoot belongs to.
     *
     * All of it is ONE form, saved together -- sorting out a mapping
     * touches many rows at once, and a save button per row turns that into
     * dozens of round trips. Three tabs (Alpine x-show, everything still
     * rendered) split what used to be one long scroll of unrelated jobs
     * into three you can actually scan -- switching tabs never drops a
     * field's value, because nothing is removed from the DOM, only hidden.
     *
     * $i and $j are running indexes because the Notion string is carried as
     * a form VALUE, never as a key: PHP rewrites dots and spaces in request
     * keys to underscores, which would corrupt "Annamalai.mov" and
     * "Surya's Restaurant" into strings that match nothing.
     */
    $i = 0;
    $j = 0;
    $accountsByClient = $accounts->groupBy(fn ($a) => $a->client?->name ?? 'Unknown client');

    // Reel and Post are both Instagram; only YouTube gets the other mark.
    // Same convention Content Dashboard uses -- see its own $platformIcon.
    $platformIcon = [
        \App\Models\ContentItem::SOURCE_REEL => 'instagram',
        \App\Models\ContentItem::SOURCE_POST => 'instagram',
        \App\Models\ContentItem::SOURCE_YOUTUBE => 'youtube',
        \App\Models\ContentItem::SOURCE_STORY => 'instagram',
    ];
    $sourceLabel = $targetable + [\App\Models\ContentItem::SOURCE_STORY => 'Story'];

    $tabs = [
        'map' => ['label' => 'Map Ventures', 'count' => $unmapped->count() ?: null],
        'accounts' => ['label' => 'Accounts & Targets', 'count' => null],
        'shoots' => ['label' => 'Shoot Clients', 'count' => null],
    ];
@endphp

<x-settings-layout title="Content Accounts">
    <x-slot name="header">
        <x-page-header title="Content Accounts"
                       subtitle="Group Notion ventures into accounts, set a monthly target per content type, and map Notion's shoot clients." />
    </x-slot>

    <div class="space-y-5" x-data="{ tab: '{{ $unmapped->isNotEmpty() ? 'map' : 'accounts' }}', q: '' }">

        <form method="POST" action="{{ route('content-accounts.update') }}">
            @csrf
            @method('PUT')

            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <x-tab-nav :tabs="$tabs" />

                <div x-show="tab !== 'accounts'" x-cloak class="relative w-full sm:w-64">
                    <input type="search" x-model="q"
                           placeholder="Search by name…"
                           class="w-full rounded-md border-white/15 bg-white/5 text-sm py-1.5 pl-3 pr-8 placeholder:text-brand-100/40">
                    <button type="button" x-show="q" x-cloak @click="q = ''"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-brand-100/50 hover:text-white text-xs">✕</button>
                </div>
            </div>

            {{-- ═══════════════ Map Ventures ═══════════════ --}}
            <div x-show="tab === 'map'" x-cloak class="space-y-5">
                <x-card padding="md">
                    <x-section-heading
                        title="Unmapped ventures"
                        subtitle="Notion ventures with no account. Their content is not counted on the dashboard until they have one." />

                    @if ($unmapped->isEmpty())
                        <p class="text-sm text-green-200 bg-green-400/10 ring-1 ring-green-400/20 rounded-lg px-3 py-2">
                            Every venture in the synced content is assigned to an account.
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                        <th class="py-2">Notion venture</th>
                                        <th class="py-2">Platform</th>
                                        <th class="py-2 text-right w-20">Items</th>
                                        <th class="py-2 w-72">Assign to account</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                    @foreach ($unmapped as $row)
                                        <tr x-show="q === '' || @js(\Illuminate\Support\Str::lower($row->venture)).includes(q.toLowerCase())">
                                            <td class="py-2.5 font-medium text-white">
                                                {{ $row->venture }}
                                                <input type="hidden" name="map[{{ $i }}][venture]" value="{{ $row->venture }}">
                                            </td>
                                            <td class="py-2.5">
                                                <div class="flex items-center gap-1.5">
                                                    @foreach ($ventureSources[$row->venture] ?? [] as $source)
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/10 text-brand-100/70 whitespace-nowrap">
                                                            <x-brand-icon :name="$platformIcon[$source] ?? 'instagram'" class="w-3 h-3 shrink-0" />
                                                            {{ $sourceLabel[$source] ?? ucfirst($source) }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td class="py-2.5 text-right tabular-nums text-brand-100/60">{{ number_format($row->items) }}</td>
                                            <td class="py-2.5">
                                                <select name="map[{{ $i }}][account_id]" class="w-full rounded-md border-white/15 text-sm py-1.5">
                                                    <option value="">— leave unmapped —</option>
                                                    @foreach ($accountsByClient as $clientName => $group)
                                                        <optgroup label="{{ $clientName }}{{ $group->first()->client && ! $group->first()->client->is_active ? ' (Inactive)' : '' }}">
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

                <x-card padding="md">
                    <details {{ $unmapped->isEmpty() ? 'open' : '' }}>
                        <summary class="cursor-pointer list-none">
                            <x-section-heading
                                title="Already mapped"
                                subtitle="Every venture with an account, in case one needs to move. Click to open." />
                        </summary>

                        @php
                            $mappedRows = $accounts->flatMap(fn ($account) => $account->ventures->map(fn ($v) => [
                                'venture' => $v->venture,
                                'account' => $account,
                            ]))->sortBy('venture')->values();
                        @endphp

                        @if ($mappedRows->isEmpty())
                            <x-empty-state message="Nothing mapped yet." />
                        @else
                            <div class="overflow-x-auto mt-3">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                            <th class="py-2">Notion venture</th>
                                            <th class="py-2">Platform</th>
                                            <th class="py-2 text-right w-20">Items</th>
                                            <th class="py-2 w-72">Account</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/10">
                                        @foreach ($mappedRows as $row)
                                            @php $account = $row['account']; @endphp
                                            <tr x-show="q === '' || @js(\Illuminate\Support\Str::lower($row['venture'].' '.$account->name.' '.($account->client?->name ?? ''))).includes(q.toLowerCase())">
                                                <td class="py-2.5 font-medium text-white">
                                                    {{ $row['venture'] }}
                                                    <input type="hidden" name="map[{{ $i }}][venture]" value="{{ $row['venture'] }}">
                                                </td>
                                                <td class="py-2.5">
                                                    <div class="flex items-center gap-1.5">
                                                        @foreach ($ventureSources[$row['venture']] ?? [] as $source)
                                                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/10 text-brand-100/70 whitespace-nowrap">
                                                                <x-brand-icon :name="$platformIcon[$source] ?? 'instagram'" class="w-3 h-3 shrink-0" />
                                                                {{ $sourceLabel[$source] ?? ucfirst($source) }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                </td>
                                                <td class="py-2.5 text-right tabular-nums text-brand-100/60">{{ number_format($ventureCounts[$row['venture']] ?? 0) }}</td>
                                                <td class="py-2.5">
                                                    <select name="map[{{ $i }}][account_id]" class="w-full rounded-md border-white/15 text-sm py-1.5">
                                                        <option value="">— unmap —</option>
                                                        @foreach ($accountsByClient as $optClient => $optGroup)
                                                            <optgroup label="{{ $optClient }}{{ $optGroup->first()->client && ! $optGroup->first()->client->is_active ? ' (Inactive)' : '' }}">
                                                                @foreach ($optGroup as $opt)
                                                                    <option value="{{ $opt->id }}" @selected($opt->id === $account->id)>{{ $opt->name }}</option>
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
                    </details>
                </x-card>
            </div>

            {{-- ═══════════════ Accounts & Targets ═══════════════ --}}
            <div x-show="tab === 'accounts'" x-cloak class="space-y-5">
                <x-card padding="md">
                    <x-section-heading
                        title="Accounts and targets"
                        subtitle="A target per content type. Only accounts with at least one target appear on the Content Dashboard." />

                    @if ($accounts->isEmpty())
                        <x-empty-state message="No accounts yet — add one below." />
                    @else
                        @foreach ($accountsByClient as $clientName => $group)
                            <div class="mb-5 last:mb-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-300 mb-2 flex items-center gap-2">
                                    {{ $clientName }}
                                    @if ($group->first()->client && ! $group->first()->client->is_active)
                                        <x-badge status="inactive">Inactive</x-badge>
                                    @endif
                                </p>

                                <div class="space-y-3">
                                    @foreach ($group as $account)
                                        <div class="rounded-lg ring-1 ring-white/10 bg-brand-900/40 p-3">
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
                                                        <x-input-label :for="'t-'.$account->id.'-'.$source">
                                                            <span class="inline-flex items-center gap-1">
                                                                <x-brand-icon :name="$platformIcon[$source]" class="w-3.5 h-3.5 shrink-0" />
                                                                {{ $label }}
                                                            </span>
                                                        </x-input-label>
                                                        <x-text-input :id="'t-'.$account->id.'-'.$source" type="number" min="0" max="9999"
                                                                      name="targets[{{ $account->id }}][{{ $source }}]"
                                                                      class="mt-1 w-full" placeholder="none"
                                                                      value="{{ old('targets.'.$account->id.'.'.$source, $account->targetFor($source)) }}" />
                                                    </div>
                                                @endforeach
                                                <div class="pb-1">
                                                    <button type="submit" form="delete-{{ $account->id }}"
                                                            class="text-xs font-semibold uppercase tracking-widest text-red-300 hover:text-red-200"
                                                            onclick="return confirm('Delete {{ $account->name }}? Its ventures become unmapped — no content is deleted.')">
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>

                                            {{-- Read-only glance at what feeds this account -- the
                                                 actual mapping control lives on the Map Ventures tab
                                                 now, not duplicated here. --}}
                                            @if ($account->ventures->isNotEmpty())
                                                <div class="mt-3 pt-3 border-t border-white/10 flex flex-wrap items-center gap-1.5">
                                                    @foreach ($account->ventures as $venture)
                                                        <span class="inline-flex items-center gap-1 text-[11px] text-brand-100/70 bg-white/5 rounded-full px-2 py-0.5">
                                                            @foreach ($ventureSources[$venture->venture] ?? [] as $source)
                                                                <x-brand-icon :name="$platformIcon[$source] ?? 'instagram'" class="w-3 h-3 shrink-0" />
                                                            @endforeach
                                                            {{ $venture->venture }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <p class="mt-2 text-[11px] text-amber-300">
                                                    No ventures assigned — this account will always show zero.
                                                    <button type="button" @click="tab = 'map'" class="underline hover:text-white">Map one →</button>
                                                </p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @endif
                </x-card>

                <x-card padding="md">
                    <x-section-heading title="Add an account"
                                       subtitle="For a client that runs more than one publishing identity, add one per identity." />

                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[180px]">
                            <x-input-label for="client_id" value="Client" />
                            <select id="client_id" form="add-account" name="client_id" required class="mt-1 w-full rounded-md border-white/15 text-sm py-1.5">
                                <option value="">Choose a client…</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>
                                        {{ $client->name }}{{ $client->is_active ? '' : ' (Inactive)' }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
                        </div>
                        <div class="flex-1 min-w-[160px]">
                            <x-input-label for="new-name" value="Account name" />
                            <x-text-input id="new-name" form="add-account" name="name" type="text" class="mt-1 w-full"
                                          placeholder="e.g. SVA Womenswear" value="{{ old('name') }}" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        @foreach ($targetable as $source => $label)
                            <div class="w-32">
                                <x-input-label :for="'new-t-'.$source">
                                    <span class="inline-flex items-center gap-1">
                                        <x-brand-icon :name="$platformIcon[$source]" class="w-3.5 h-3.5 shrink-0" />
                                        {{ $label }}
                                    </span>
                                </x-input-label>
                                <x-text-input :id="'new-t-'.$source" form="add-account" :name="'target_'.$source" type="number" min="0" max="9999"
                                              class="mt-1 w-full" placeholder="optional" value="{{ old('target_'.$source) }}" />
                            </div>
                        @endforeach
                        <div class="pb-0.5">
                            <button type="submit" form="add-account"
                                    class="inline-flex items-center justify-center min-h-[44px] px-4 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                                Add
                            </button>
                        </div>
                    </div>
                </x-card>
            </div>

            {{-- ═══════════════ Shoot Clients ═══════════════ --}}
            <div x-show="tab === 'shoots'" x-cloak>
                {{-- Notion's shoot "Client" is its own free-text list and does
                     not overlap the venture names, so it needs its own mapping
                     rather than reusing the one above. --}}
                <x-card padding="md">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <x-section-heading
                            title="Notion shoot clients"
                            subtitle="Which portal client each Notion shoot belongs to. Unmapped shoots import with no client attached." />
                        <button type="submit" form="auto-map-shoots"
                                class="shrink-0 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                            Auto-match exact names
                        </button>
                    </div>

                    @if ($shootClients->isEmpty())
                        <x-empty-state message="No shoots synced from Notion yet." />
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                        <th class="py-2">Notion client</th>
                                        <th class="py-2 text-right w-24">Shoots</th>
                                        <th class="py-2 w-72">Portal client</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                    @foreach ($shootClients as $row)
                                        <tr x-show="q === '' || @js(\Illuminate\Support\Str::lower((string) $row->client)).includes(q.toLowerCase())">
                                            <td class="py-2.5 font-medium text-white">
                                                {{ $row->client }}
                                                <input type="hidden" name="shootMap[{{ $j }}][client]" value="{{ $row->client }}">
                                            </td>
                                            <td class="py-2.5 text-right tabular-nums text-brand-100/60">{{ $row->shoots }}</td>
                                            <td class="py-2.5">
                                                <select name="shootMap[{{ $j }}][client_id]" class="w-full rounded-md border-white/15 text-sm py-1.5">
                                                    <option value="">— unmapped —</option>
                                                    @foreach ($clients as $client)
                                                        <option value="{{ $client->id }}" @selected((int) $row->client_id === $client->id)>
                                                            {{ $client->name }}{{ $client->is_active ? '' : ' (Inactive)' }}
                                                        </option>
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
                </x-card>
            </div>

            <div class="mt-5">
                <x-primary-button>Save all changes</x-primary-button>
            </div>
        </form>

        {{-- Outside the main form: nested forms are invalid HTML, so the
             buttons above target these by id. --}}
        <form id="auto-map-shoots" method="POST" action="{{ route('content-accounts.auto-map-shoots') }}" class="hidden">@csrf</form>
        <form id="add-account" method="POST" action="{{ route('content-accounts.store') }}" class="hidden">@csrf</form>
        @foreach ($accounts as $account)
            <form id="delete-{{ $account->id }}" method="POST"
                  action="{{ route('content-accounts.destroy', $account) }}" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    </div>
</x-settings-layout>
