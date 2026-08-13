@php
    use App\Models\ShootKit;

    /*
    | Kit rows carry their availability up front so the picker can be honest
    | about a clash without asking the database once per row.
    */
    $kitRows = $shoot->kits->map(function (ShootKit $line) use ($committed, $shortfalls) {
        $item = $line->item;
        $elsewhere = (int) ($committed[$line->equipment_item_id]->committed ?? 0);
        $missing = (int) ($shortfalls[$line->equipment_item_id] ?? 0);
        $stock = max(0, ($item?->quantity ?? 0) - $missing);

        return [
            'id' => $line->id,
            'name' => $item?->name ?? 'Removed item',
            'quantity' => $line->quantity,
            'checkedOut' => $line->checked_out_at !== null,
            'returned' => $line->returned_at !== null,
            'by' => $line->checkedOutBy?->name,
            'at' => $line->checked_out_at?->format('H:i'),
            'condition' => $line->conditionLabel(),
            'shortfall' => $line->shortfall(),
            // Short by this many once everyone else's claims are counted.
            'over' => max(0, ($elsewhere + $line->quantity) - $stock),
        ];
    })->values();

    $alreadyOn = $shoot->kits->pluck('equipment_item_id')->all();
    $clashes = $kitRows->where('over', '>', 0);
@endphp

<x-app-layout :title="$shoot->title">
    <x-slot name="header">
        <x-page-header :title="$shoot->title" eyebrow="Shoot"
                       :subtitle="$shoot->starts_at->format('l j F, H:i').($shoot->location ? ' · '.$shoot->location : '')">
            <x-slot name="actions">
                <x-btn :href="route('shoots.call-sheet', $shoot)" variant="secondary" icon="printer">Call sheet</x-btn>
                @can('shoots.edit')
                    <x-btn :href="route('shoots.edit', $shoot)" icon="pencil">Edit</x-btn>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4"
         x-data="shootKit({
            rows: {{ Illuminate\Support\Js::from($kitRows) }},
            urls: {
                item: '{{ route('shoots.kit.check-out', [$shoot, 0]) }}',
                bulk: '{{ route('shoots.kit.bulk', $shoot) }}',
            },
            canEdit: {{ auth()->user()->can('shoots.edit') ? 'true' : 'false' }},
         })">

        <div class="flex flex-wrap items-center gap-2">
            <x-badge :status="$shoot->status" />
            @if ($shoot->clientLabel())
                <span class="text-sm text-gray-600">{{ $shoot->clientLabel() }}</span>
            @endif
            <span class="ml-auto text-xs" :class="statusClass" x-text="statusLine"></span>
        </div>

        @if ($clashes->isNotEmpty())
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 flex items-start gap-3">
                <x-icon name="alert" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />
                <div class="text-sm text-amber-900">
                    <p class="font-semibold">
                        {{ $clashes->count() }} {{ Str::plural('item', $clashes->count()) }} on this shoot
                        {{ $clashes->count() === 1 ? 'is' : 'are' }} promised elsewhere too.
                    </p>
                    <p class="mt-1 text-amber-800">
                        Nothing is blocked — somebody just needs to decide who gets it.
                    </p>
                </div>
            </div>
        @endif

        {{-- ——— The kit list. The screen someone opens at the door. ——— --}}
        <x-card class="overflow-hidden">
            <div class="sticky top-0 z-10 flex items-center justify-between gap-3 px-4 py-3 bg-white border-b border-gray-100">
                <div>
                    <p class="text-sm font-semibold text-gray-900">Kit</p>
                    <p class="text-xs text-gray-500" x-text="progress"></p>
                </div>

                <div class="flex items-center gap-2">
                    <x-btn type="button" size="sm" x-show="anyToTake" @click="bulk('check-out')">Take all</x-btn>
                    <x-btn type="button" size="sm" variant="secondary" x-show="anyOut" x-cloak @click="bulk('check-in')">All back</x-btn>
                </div>
            </div>

            <template x-if="rows.length === 0">
                <p class="p-8 text-center text-sm text-gray-500">No kit on this shoot yet.</p>
            </template>

            <template x-for="row in rows" :key="row.id">
                <div class="border-b border-gray-100 last:border-0">
                    {{-- The whole row is the tap target: one hand, dim corridor. --}}
                    <button type="button" @click="toggle(row)"
                            class="w-full flex items-center gap-3 px-4 py-3 min-h-[56px] text-left hover:bg-gray-50 transition">
                        <span class="shrink-0 w-6 h-6 rounded-md border-2 flex items-center justify-center transition"
                              :class="row.returned ? 'bg-gray-300 border-gray-300'
                                    : row.checkedOut ? 'bg-brand-500 border-brand-500' : 'border-gray-300'">
                            <svg x-show="row.checkedOut || row.returned" class="w-4 h-4 text-white" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900 truncate">
                                <span x-text="row.name"></span>
                                <span x-show="row.quantity > 1" class="text-gray-400" x-text="'×' + row.quantity"></span>
                            </span>
                            <span class="block text-xs mt-0.5"
                                  :class="row.over > 0 ? 'text-amber-600' : 'text-gray-500'">
                                <span x-show="row.returned">Back<span x-show="row.condition" x-text="' · ' + row.condition"></span></span>
                                <span x-show="row.checkedOut && ! row.returned" x-text="'Taken ' + (row.at || '') + (row.by ? ' by ' + row.by : '')"></span>
                                <span x-show="! row.checkedOut && ! row.returned && row.over > 0">Also promised to another shoot</span>
                                <span x-show="! row.checkedOut && ! row.returned && row.over === 0">To take</span>
                            </span>
                        </span>

                        <span x-show="row.saving" class="shrink-0 w-1.5 h-1.5 rounded-full bg-brand-400 animate-pulse"></span>
                        <span x-show="row.failed" x-cloak class="shrink-0 text-xs font-semibold text-red-600">Retry</span>
                    </button>
                </div>
            </template>

            @can('shoots.edit')
                <div class="p-4 bg-gray-50 border-t border-gray-100">
                    <form method="POST" action="{{ route('shoots.kit.store', $shoot) }}"
                          class="flex flex-wrap items-end gap-2" @submit="() => {}">
                        @csrf
                        <div class="flex-1 min-w-[180px]">
                            <x-input-label for="equipment_item_id" value="Add kit" />
                            <x-select id="equipment_item_id" name="equipment_item_id" class="mt-1" required>
                                <option value="">Choose an item</option>
                                @foreach ($available as $item)
                                    @php
                                        $short = (int) ($shortfalls[$item->id] ?? 0);
                                        $stock = max(0, $item->quantity - $short);
                                        $taken = (int) ($committed[$item->id]->committed ?? 0);
                                        $free = $stock - $taken;
                                    @endphp
                                    <option value="{{ $item->id }}" @disabled(in_array($item->id, $alreadyOn, true))>
                                        {{ $item->name }}
                                        @if (in_array($item->id, $alreadyOn, true))
                                            — already on this shoot
                                        @elseif ($free <= 0)
                                            — none free ({{ $taken }} promised elsewhere)
                                        @elseif ($item->quantity > 1)
                                            — {{ $free }} of {{ $stock }} free
                                        @endif
                                    </option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="w-20">
                            <x-input-label for="quantity" value="Qty" />
                            <x-text-input id="quantity" name="quantity" type="number" min="1" value="1" class="mt-1" />
                        </div>
                        <x-btn type="submit" size="sm">Add</x-btn>
                    </form>
                </div>
            @endcan
        </x-card>

        {{-- ——— Crew ——— --}}
        <x-card class="p-4 sm:p-5">
            <p class="text-sm font-semibold text-gray-900 mb-3">Crew</p>

            @forelse ($shoot->crew as $member)
                <div class="flex items-center gap-3 py-2 border-b border-gray-100 last:border-0">
                    <x-avatar :name="$member->user?->name ?? '?'" :src="$member->user?->avatarUrl()" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ $member->user?->name }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $member->role ?: 'Crew' }}
                            @if ($member->call_time) &middot; call {{ \Illuminate\Support\Str::of($member->call_time)->substr(0, 5) }} @endif
                        </p>
                    </div>
                    @can('shoots.edit')
                        <form method="POST" action="{{ route('shoots.crew.destroy', [$shoot, $member]) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="min-h-[44px] px-2 text-xs text-gray-400 hover:text-red-600">Remove</button>
                        </form>
                    @endcan
                </div>
            @empty
                <p class="text-sm text-gray-500 py-2">Nobody assigned yet.</p>
            @endforelse

            @can('shoots.edit')
                <form method="POST" action="{{ route('shoots.crew.store', $shoot) }}" class="flex flex-wrap items-end gap-2 mt-4 pt-4 border-t border-gray-100">
                    @csrf
                    <div class="flex-1 min-w-[150px]">
                        <x-input-label for="user_id" value="Add someone" />
                        <x-select id="user_id" name="user_id" class="mt-1" required>
                            <option value="">Choose</option>
                            @foreach ($staff as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="w-32">
                        <x-input-label for="role" value="Role" />
                        <x-text-input id="role" name="role" type="text" class="mt-1" placeholder="Camera" />
                    </div>
                    <div class="w-28">
                        <x-input-label for="call_time" value="Call" />
                        <x-text-input id="call_time" name="call_time" type="time" class="mt-1" />
                    </div>
                    <x-btn type="submit" size="sm">Add</x-btn>
                </form>
            @endcan
        </x-card>

        @if ($shoot->notes)
            <x-card class="p-4 sm:p-5">
                <p class="text-sm font-semibold text-gray-900 mb-2">Notes</p>
                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $shoot->notes }}</p>
            </x-card>
        @endif

        @can('shoots.delete')
            <form method="POST" action="{{ route('shoots.destroy', $shoot) }}"
                  onsubmit="return confirm('Delete this shoot? Its crew and kit list go with it.')">
                @csrf @method('DELETE')
                <button type="submit" class="text-sm font-semibold text-red-600 hover:text-red-700">Delete shoot</button>
            </form>
        @endcan
    </div>

    @push('scripts')
    <script>
        function shootKit(config) {
            return {
                rows: config.rows.map(r => ({ ...r, saving: false, failed: false })),
                urls: config.urls,

                get progress() {
                    const total = this.rows.length;
                    if (! total) return '';
                    const back = this.rows.filter(r => r.returned).length;
                    if (back === total) return 'All ' + total + ' back';
                    const out = this.rows.filter(r => r.checkedOut && ! r.returned).length;
                    return out + ' of ' + total + ' taken';
                },
                get anyToTake() { return this.rows.some(r => ! r.checkedOut && ! r.returned); },
                get anyOut() { return this.rows.some(r => r.checkedOut && ! r.returned); },
                get statusLine() {
                    if (this.rows.some(r => r.saving)) return 'Saving…';
                    if (this.rows.some(r => r.failed)) return 'Something did not save';
                    return '';
                },
                get statusClass() {
                    return this.rows.some(r => r.failed) ? 'text-red-600' : 'text-gray-400';
                },

                url(row, action) {
                    return this.urls.item.replace(/0\/check-out$/, row.id + '/' + action);
                },

                /*
                 * Three states, one tap each way: to take -> taken -> back.
                 * The row flips immediately and the request follows; a failure
                 * puts it back and says so on the row itself, because that is
                 * where the finger is.
                 */
                toggle(row) {
                    if (row.returned) return;

                    const action = row.checkedOut ? 'check-in' : 'check-out';
                    const before = { checkedOut: row.checkedOut, returned: row.returned };

                    if (action === 'check-out') { row.checkedOut = true; } else { row.returned = true; }
                    this.send(row, action, before);
                },

                async send(row, action, before) {
                    row.saving = true;
                    row.failed = false;

                    try {
                        const response = await fetch(this.url(row, action), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: '{}',
                        });

                        if (! response.ok) throw new Error('failed');

                        // Server time wins: a phone clock is routinely wrong and
                        // "Taken 04:15" on a 9am shoot destroys trust in the log.
                        Object.assign(row, await response.json());
                    } catch (e) {
                        Object.assign(row, before);
                        row.failed = true;
                    } finally {
                        row.saving = false;
                    }
                },

                async bulk(action) {
                    const snapshot = this.rows.map(r => ({ ...r }));
                    this.rows.forEach(r => {
                        if (action === 'check-out' && ! r.returned) r.checkedOut = true;
                        if (action === 'check-in' && r.checkedOut) r.returned = true;
                        r.saving = true;
                    });

                    try {
                        const response = await fetch(this.urls.bulk, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ action }),
                        });

                        if (! response.ok) throw new Error('failed');

                        const data = await response.json();
                        this.rows = data.kit.map(r => ({ ...r, saving: false, failed: false }));
                    } catch (e) {
                        this.rows = snapshot.map(r => ({ ...r, saving: false, failed: true }));
                    }
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
