{{--
    Notion ↔ Clients: deciding whose Notion content is whose.

    Notion has two free-text lists that must be connected to portal clients:
    the Content tables' Venture (connected to a content account -- what puts
    a reel on a client's dashboard) and the Shoots table's Client. This page
    replaced a single giant form of dropdowns with one saved-on-the-spot
    action per decision, and shows what a person needs to make each one:
    how much content a name carries, when it last posted, a few of its
    titles, and likely clients (whole-word matches only, never applied by
    themselves -- App\Support\NotionConnections).

    Rendered by Alpine from one state object (resources/js/notion-connections.js);
    every action returns the whole fresh state, so nothing here can drift
    from the database. Each change shows a toast with Undo.

    Tabs:
      To connect   ventures with no account, biggest first, as cards
      Clients      each client: its accounts, targets (edit in place), the
                   ventures and shoot names connected to it
      Shoot names  Notion shoot clients, split or unfiled ones first
      Ignored      names somebody said are not a client, restorable
--}}
@php
    $routes = [
        'connectVenture' => route('content-accounts.connect-venture'),
        'createAndConnect' => route('content-accounts.create-and-connect'),
        'ignore' => route('content-accounts.ignore'),
        'quick' => route('content-accounts.quick-update', ['contentAccount' => '__ID__']),
        'connectShoot' => route('content-accounts.connect-shoot-client'),
        'store' => route('content-accounts.store'),
        'destroy' => route('content-accounts.destroy', ['contentAccount' => '__ID__']),
        'autoMap' => route('content-accounts.auto-map-shoots'),
    ];

    $tabs = [
        'connect' => 'To connect',
        'clients' => 'Clients',
        'shoots' => 'Shoot names',
        'ignored' => 'Ignored',
    ];

    $strength = [
        'strong' => 'bg-emerald-400/15 text-emerald-200 ring-emerald-400/30 hover:bg-emerald-400/25',
        'good' => 'bg-brand-400/15 text-brand-100 ring-brand-400/30 hover:bg-brand-400/25',
        'weak' => 'bg-white/5 text-brand-100/80 ring-white/15 hover:bg-white/10',
    ];
@endphp

<x-settings-layout title="Notion ↔ Clients">
    <x-slot name="header">
        <x-page-header title="Notion ↔ Clients"
                       subtitle="Connect what is in Notion to the right client, so every reel and every shoot counts for them." />
    </x-slot>

    <div x-data="notionConnections(@js(['state' => $state, 'routes' => $routes]))" class="space-y-5 pb-24">

        {{-- ════════════════ How connected are we ════════════════ --}}
        <x-card padding="md">
            <div class="flex flex-col sm:flex-row sm:items-center gap-5">
                <div class="flex items-center gap-4 shrink-0">
                    <div class="relative w-20 h-20 shrink-0">
                        <svg viewBox="0 0 36 36" class="w-full h-full -rotate-90" aria-hidden="true">
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3.2" class="text-white/10" />
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke-width="3.2" stroke-linecap="round"
                                    :stroke="pct >= 95 ? '#34D399' : pct >= 75 ? '#67BCD4' : '#FBBF24'"
                                    :stroke-dasharray="`${pct} 100`" pathLength="100" class="transition-all duration-700" />
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-lg font-extrabold tabular-nums" x-text="pct + '%'"></span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-base font-semibold text-white">
                            <span x-text="s.stats.connected_items.toLocaleString('en-IN')"></span> of
                            <span x-text="s.stats.total_items.toLocaleString('en-IN')"></span> Notion items count for a client
                        </p>
                        <p class="mt-0.5 text-sm text-brand-100/60" x-show="s.stats.items_waiting > 0">
                            <span x-text="s.stats.items_waiting.toLocaleString('en-IN')"></span> are not on anyone's dashboard yet.
                        </p>
                        <p class="mt-0.5 text-sm text-emerald-300" x-show="s.stats.items_waiting === 0" x-cloak>Everything in Notion is connected.</p>
                    </div>
                </div>

                <div class="sm:ml-auto grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end">
                    <button type="button" @click="tab = 'connect'"
                            class="text-left rounded-xl px-3 py-2 ring-1 transition"
                            :class="counts.connect ? 'bg-amber-400/10 ring-amber-400/30 hover:bg-amber-400/15' : 'bg-white/5 ring-white/10'">
                        <span class="block text-xl font-extrabold tabular-nums" :class="counts.connect ? 'text-amber-200' : 'text-white'" x-text="counts.connect"></span>
                        <span class="block text-xs text-brand-100/70">ventures to connect</span>
                    </button>
                    <button type="button" @click="tab = 'shoots'"
                            class="text-left rounded-xl px-3 py-2 ring-1 transition"
                            :class="s.stats.shoots_waiting ? 'bg-amber-400/10 ring-amber-400/30 hover:bg-amber-400/15' : 'bg-white/5 ring-white/10'">
                        <span class="block text-xl font-extrabold tabular-nums" :class="s.stats.shoots_waiting ? 'text-amber-200' : 'text-white'" x-text="s.stats.shoots_waiting"></span>
                        <span class="block text-xs text-brand-100/70">shoots with no client</span>
                    </button>
                </div>
            </div>
        </x-card>

        {{-- ════════════════ Tabs and search ════════════════ --}}
        <div class="sticky top-16 lg:top-0 z-20 -mx-4 px-4 sm:mx-0 sm:px-0 py-2 bg-brand-900/90 backdrop-blur flex flex-col sm:flex-row gap-2 sm:items-center">
            <div class="flex gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden rounded-xl bg-white/5 p-1 ring-1 ring-white/10">
                @foreach ($tabs as $key => $label)
                    <button type="button" @click="tab = @js($key)"
                            class="shrink-0 inline-flex items-center gap-2 min-h-[40px] px-3.5 rounded-lg text-sm font-semibold transition"
                            :class="tab === @js($key) ? 'bg-brand-400 text-brand-900' : 'text-brand-100/70 hover:text-white'">
                        {{ $label }}
                        <span x-show="counts[@js($key)] > 0" x-text="counts[@js($key)]"
                              class="min-w-[20px] px-1.5 rounded-full text-[11px] font-bold tabular-nums"
                              :class="tab === @js($key) ? 'bg-brand-900/20' : '{{ $key === 'ignored' || $key === 'clients' ? 'bg-white/10' : 'bg-amber-400/20 text-amber-200' }}'"></span>
                    </button>
                @endforeach
            </div>
            <div class="relative sm:ml-auto sm:w-72">
                <input type="search" x-model="q" placeholder="Search names, clients, titles…"
                       class="w-full min-h-[44px] rounded-xl border-white/15 bg-white/5 text-base sm:text-sm pl-10 pr-3 placeholder:text-brand-100/40 focus:border-brand-400 focus:ring-brand-400">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-brand-100/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" /></svg>
            </div>
        </div>

        {{-- ════════════════ To connect ════════════════ --}}
        <section x-show="tab === 'connect'" class="space-y-3">
            <p class="text-sm text-brand-100/60" x-show="toConnect.length">
                Biggest first. Each is a Venture name from Notion whose content is not counted for any client yet.
            </p>

            <div class="grid gap-3 lg:grid-cols-2">
                <template x-for="v in toConnect" :key="v.name">
                    <article class="rounded-2xl bg-white/[0.04] ring-1 ring-white/10 p-4 sm:p-5 flex flex-col gap-3"
                             x-transition:leave="transition duration-300" x-transition:leave-end="opacity-0 scale-95">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-bold text-white break-words" x-text="v.name"></h3>
                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-100/60">
                                    <span class="font-semibold text-amber-200" x-text="v.items + (v.items === 1 ? ' item' : ' items')"></span>
                                    <span class="inline-flex items-center gap-1">
                                        <template x-for="src in v.sources" :key="src">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-white/10">
                                                <template x-if="src === 'youtube'"><x-brand-icon name="youtube" class="w-3 h-3" /></template>
                                                <template x-if="src !== 'youtube'"><x-brand-icon name="instagram" class="w-3 h-3" /></template>
                                                <span x-text="s.targetable[src] || (src === 'story' ? 'Story' : src)"></span>
                                            </span>
                                        </template>
                                    </span>
                                    <span x-show="v.last_date" x-text="'last ' + v.last_date"></span>
                                </p>
                            </div>
                        </div>

                        <ul x-show="v.samples.length" class="space-y-1 text-sm text-brand-100/75 border-l-2 border-white/10 pl-3">
                            <template x-for="title in v.samples" :key="title">
                                <li class="truncate" x-text="title"></li>
                            </template>
                        </ul>

                        <div x-show="v.suggestions.length" class="flex flex-wrap gap-2">
                            <template x-for="sg in v.suggestions" :key="sg.client_id + '-' + sg.account_id">
                                <button type="button" :disabled="busy"
                                        @click="sg.account_id ? connect(v.name, sg.account_id) : (openPicker('venture', v.name), startNew('new-account', sg.client_id))"
                                        class="inline-flex flex-col items-start text-left rounded-xl px-3 py-2 ring-1 transition disabled:opacity-50"
                                        :class="{ strong: @js($strength['strong']), good: @js($strength['good']), weak: @js($strength['weak']) }[sg.strength]">
                                    <span class="text-sm font-semibold" x-text="(sg.account_id ? '→ ' + accountLabel(sg.account_id) : '→ new account for ' + client(sg.client_id)?.name)"></span>
                                    <span class="text-[11px] opacity-70" x-text="sg.reason"></span>
                                </button>
                            </template>
                        </div>

                        <div class="mt-auto flex flex-wrap gap-2 pt-1">
                            <button type="button" @click="openPicker('venture', v.name)"
                                    class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-xl bg-brand-400 text-brand-900 text-sm font-bold hover:bg-brand-500 transition">
                                Connect to a client…
                            </button>
                            <button type="button" @click="ignore('venture', v.name)" :disabled="busy"
                                    class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-xl ring-1 ring-white/15 text-sm font-semibold text-brand-100/70 hover:bg-white/5 hover:text-white transition disabled:opacity-50">
                                Not a client
                            </button>
                        </div>
                    </article>
                </template>
            </div>

            <div x-show="!toConnect.length" class="rounded-2xl bg-emerald-400/10 ring-1 ring-emerald-400/25 p-8 text-center">
                <p class="text-3xl">✓</p>
                <p class="mt-2 font-semibold text-emerald-200" x-text="q ? 'Nothing waiting matches “' + q + '”.' : 'Every Notion venture is connected or set aside.'"></p>
                <p class="mt-1 text-sm text-brand-100/60" x-show="!q">New ventures appear here the moment a Notion sync brings them in.</p>
            </div>
        </section>

        {{-- ════════════════ Clients ════════════════ --}}
        <section x-show="tab === 'clients'" x-cloak class="space-y-3">
            <div class="flex flex-wrap gap-2">
                @foreach (['all' => 'All clients', 'active' => 'Active', 'missing' => 'Not connected yet'] as $key => $label)
                    <button type="button" @click="clientFilter = @js($key)"
                            class="min-h-[36px] px-3 rounded-full text-xs font-semibold ring-1 transition"
                            :class="clientFilter === @js($key) ? 'bg-white text-brand-900 ring-white' : 'ring-white/15 text-brand-100/70 hover:text-white'">{{ $label }}</button>
                @endforeach
            </div>

            <template x-for="c in clientCards" :key="c.id">
                <article class="rounded-2xl bg-white/[0.04] ring-1 ring-white/10 overflow-hidden" x-data="{ newAccount: '' }">
                    <header class="flex items-center gap-3 p-4 sm:p-5">
                        <template x-if="c.logo"><img :src="c.logo" alt="" class="w-11 h-11 rounded-xl bg-white object-contain p-1 shrink-0"></template>
                        <template x-if="!c.logo"><span class="w-11 h-11 rounded-xl bg-brand-400/15 text-brand-200 font-bold flex items-center justify-center shrink-0" x-text="initials(c.name)"></span></template>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold text-white truncate" x-text="c.name"></h3>
                            <p class="text-xs text-brand-100/60">
                                <span x-show="!c.active" class="text-amber-200">Inactive · </span>
                                <span x-show="c.connected" x-text="c.items.toLocaleString('en-IN') + ' Notion items · ' + c.shootNames.reduce((n, s) => n + s.shoots, 0) + ' shoots'"></span>
                                <span x-show="!c.connected" class="text-amber-200">Not connected to Notion yet</span>
                            </p>
                        </div>
                    </header>

                    <div class="border-t border-white/10 divide-y divide-white/10">
                        <template x-for="a in c.accounts" :key="a.id">
                            <div class="p-4 sm:px-5 space-y-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <label class="flex-1 min-w-[160px]">
                                        <span class="block text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">Account</span>
                                        <input type="text" :value="a.name" @change="saveAccount(a, 'name', $event.target.value)"
                                               class="mt-1 w-full min-h-[40px] rounded-lg border-white/15 bg-brand-900/50 text-base sm:text-sm font-semibold focus:border-brand-400 focus:ring-brand-400">
                                    </label>
                                    <template x-for="(label, src) in s.targetable" :key="src">
                                        <label class="w-[30%] sm:w-24">
                                            <span class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">
                                                <span x-text="label + '/mo'"></span>
                                                <span x-show="saved[a.id + '.target_' + src]" x-transition.opacity class="text-emerald-300 normal-case">✓</span>
                                            </span>
                                            <input type="number" min="0" max="9999" inputmode="numeric" placeholder="—" :value="a.targets[src] ?? ''"
                                                   @change="saveAccount(a, 'target_' + src, $event.target.value)"
                                                   class="mt-1 w-full min-h-[40px] rounded-lg border-white/15 bg-brand-900/50 text-base sm:text-sm tabular-nums focus:border-brand-400 focus:ring-brand-400">
                                        </label>
                                    </template>
                                    <button type="button" @click="deleteAccount(a)" class="min-h-[40px] px-2 text-xs font-semibold text-red-300/80 hover:text-red-200" aria-label="Delete account">Delete</button>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <template x-for="v in a.ventures" :key="v.name">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-400/10 ring-1 ring-brand-400/25 pl-3 pr-1 py-1 text-sm">
                                            <span class="font-medium text-white" x-text="v.name"></span>
                                            <span class="text-[11px] text-brand-100/60 tabular-nums" x-text="v.items"></span>
                                            <button type="button" @click="disconnect(v.name)" :disabled="busy" class="w-6 h-6 rounded-full text-brand-100/60 hover:bg-white/10 hover:text-white" :aria-label="'Disconnect ' + v.name">✕</button>
                                        </span>
                                    </template>
                                    <button type="button" @click="openPicker('account', a.id)"
                                            class="inline-flex items-center gap-1 min-h-[32px] px-3 rounded-full border border-dashed border-white/25 text-sm text-brand-100/70 hover:text-white hover:border-brand-400">
                                        + Add venture
                                    </button>
                                </div>
                                <p x-show="!a.ventures.length" class="text-xs text-amber-200/80">No Notion venture yet — this account will show zero on the Content Dashboard.</p>
                            </div>
                        </template>

                        <div x-show="c.shootNames.length" class="p-4 sm:px-5">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50 mb-2">Notion shoots filed as</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="sh in c.shootNames" :key="sh.name">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/5 ring-1 ring-white/15 pl-3 pr-1 py-1 text-sm">
                                        <span class="font-medium text-white" x-text="sh.name"></span>
                                        <span class="text-[11px] text-brand-100/60" x-text="sh.shoots + ' shoots'"></span>
                                        <button type="button" @click="openPicker('shoot', sh.name)" class="w-6 h-6 rounded-full text-brand-100/60 hover:bg-white/10 hover:text-white" :aria-label="'Change ' + sh.name">⋯</button>
                                    </span>
                                </template>
                            </div>
                        </div>

                        <form class="p-4 sm:px-5 flex gap-2" @submit.prevent="addAccount(c.id, newAccount); newAccount = ''">
                            <input type="text" x-model="newAccount" placeholder="New account name, e.g. a second Instagram"
                                   class="flex-1 min-h-[40px] rounded-lg border-white/15 bg-brand-900/50 text-base sm:text-sm placeholder:text-brand-100/35 focus:border-brand-400 focus:ring-brand-400">
                            <button type="submit" :disabled="!newAccount.trim() || busy"
                                    class="shrink-0 min-h-[40px] px-4 rounded-lg ring-1 ring-white/20 text-sm font-semibold hover:bg-white/5 disabled:opacity-40">Add account</button>
                        </form>
                    </div>
                </article>
            </template>

            <p x-show="!clientCards.length" class="rounded-2xl bg-white/5 p-6 text-center text-sm text-brand-100/60">No client matches.</p>
        </section>

        {{-- ════════════════ Shoot names ════════════════ --}}
        <section x-show="tab === 'shoots'" x-cloak class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-brand-100/60 max-w-xl">
                    The Client column on Notion's Shoots table. Connect a name once and every shoot with it follows — including ones synced later.
                </p>
                <button type="button" @click="autoMatch()" :disabled="busy"
                        class="min-h-[40px] px-4 rounded-xl ring-1 ring-brand-400/40 text-sm font-semibold text-brand-200 hover:bg-brand-400/10 disabled:opacity-50">
                    Match obvious ones automatically
                </button>
            </div>

            <div class="rounded-2xl ring-1 ring-white/10 overflow-hidden divide-y divide-white/10">
                <template x-for="sh in shoots" :key="sh.name">
                    <div class="p-4 sm:px-5 flex flex-col sm:flex-row sm:items-center gap-3"
                         :class="(sh.unmapped > 0 || sh.split) ? 'bg-amber-400/[0.04]' : ''">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-white" x-text="sh.name"></p>
                            <p class="text-xs text-brand-100/60">
                                <span x-text="sh.shoots + (sh.shoots === 1 ? ' shoot' : ' shoots')"></span>
                                <template x-if="sh.client_id && !sh.split"><span> · <span class="text-emerald-300" x-text="'→ ' + client(sh.client_id)?.name"></span></span></template>
                                <template x-if="sh.split"><span class="text-amber-200" x-text="' · split: ' + (sh.unmapped ? sh.unmapped + ' with no client' : '') + (sh.unmapped && sh.breakdown.length ? ', ' : '') + sh.breakdown.map((b) => b.shoots + ' → ' + (client(b.client_id)?.name || '?')).join(', ')"></span></template>
                                <template x-if="!sh.client_id"><span class="text-amber-200"> · no client</span></template>
                            </p>
                            <div x-show="sh.suggestions.length && (!sh.client_id || sh.split)" class="mt-2 flex flex-wrap gap-2">
                                <template x-for="sg in sh.suggestions" :key="sg.client_id">
                                    <button type="button" @click="connectShoot(sh.name, sg.client_id)" :disabled="busy"
                                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 transition"
                                            :class="{ strong: @js($strength['strong']), good: @js($strength['good']), weak: @js($strength['weak']) }[sg.strength]">
                                        <span x-text="'→ ' + client(sg.client_id)?.name"></span>
                                        <span class="opacity-60 font-normal" x-text="sg.reason"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <button type="button" @click="openPicker('shoot', sh.name)"
                                    class="flex-1 sm:flex-none min-h-[40px] px-4 rounded-xl text-sm font-semibold transition"
                                    :class="(sh.unmapped > 0 || sh.split) ? 'bg-brand-400 text-brand-900 hover:bg-brand-500' : 'ring-1 ring-white/15 text-brand-100/80 hover:bg-white/5'"
                                    x-text="sh.client_id && !sh.split ? 'Change' : (sh.split ? 'Fix — choose one client' : 'Choose client…')"></button>
                            <button type="button" x-show="!sh.client_id" @click="ignore('shoot_client', sh.name)" :disabled="busy"
                                    class="min-h-[40px] px-3 rounded-xl ring-1 ring-white/15 text-sm text-brand-100/70 hover:bg-white/5">Ignore</button>
                        </div>
                    </div>
                </template>
                <p x-show="!shoots.length" class="p-6 text-center text-sm text-brand-100/60">No Notion shoots synced yet.</p>
            </div>
        </section>

        {{-- ════════════════ Ignored ════════════════ --}}
        <section x-show="tab === 'ignored'" x-cloak class="space-y-3">
            <p class="text-sm text-brand-100/60">Names set aside as not a client. Nothing was deleted — restore one and it is asked about again.</p>
            <div class="rounded-2xl ring-1 ring-white/10 overflow-hidden divide-y divide-white/10">
                <template x-for="i in ignored" :key="i.kind + i.name">
                    <div class="p-4 sm:px-5 flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-white truncate" x-text="i.name"></p>
                            <p class="text-xs text-brand-100/60" x-text="(i.kind === 'venture' ? 'Venture' : 'Shoot client') + ' · ' + i.detail"></p>
                        </div>
                        <button type="button" @click="ignore(i.kind, i.name, false)" :disabled="busy"
                                class="min-h-[40px] px-4 rounded-xl ring-1 ring-white/15 text-sm font-semibold hover:bg-white/5">Restore</button>
                    </div>
                </template>
                <p x-show="!ignored.length" class="p-6 text-center text-sm text-brand-100/60">Nothing ignored.</p>
            </div>
        </section>

        {{-- ════════════════ The picker ════════════════ --}}
        <div x-show="picker" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-6" role="dialog" aria-modal="true"
             @keydown.escape.window="closePicker()">
            <div x-show="picker" x-transition.opacity class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closePicker()"></div>

            <div x-show="picker" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-8 opacity-0 sm:translate-y-0 sm:scale-95"
                 class="relative w-full sm:max-w-lg max-h-[88vh] flex flex-col rounded-t-3xl sm:rounded-3xl bg-brand-800 ring-1 ring-white/15 shadow-2xl">
                <template x-if="picker">
                    <div class="flex flex-col min-h-0">
                        <div class="p-5 pb-3 border-b border-white/10">
                            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-white/20 sm:hidden"></div>
                            <div class="flex items-start gap-3">
                                <h2 class="flex-1 text-lg font-bold text-white" x-text="pickerTitle"></h2>
                                <button type="button" @click="closePicker()" class="w-9 h-9 -mt-1 -mr-1 rounded-full text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">✕</button>
                            </div>
                            <div x-show="picker.step === 'pick'" class="relative mt-3">
                                <input x-ref="pickerSearch" type="search" x-model="picker.q"
                                       :placeholder="picker.mode === 'account' ? 'Search ventures or titles…' : 'Search clients and accounts…'"
                                       class="w-full min-h-[46px] rounded-xl border-white/15 bg-brand-900/60 text-base pl-10 focus:border-brand-400 focus:ring-brand-400">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-brand-100/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" /></svg>
                            </div>
                        </div>

                        <div class="overflow-y-auto overscroll-contain p-3 sm:p-4 space-y-4">
                            {{-- Choosing --}}
                            <template x-if="picker.step === 'pick'">
                                <div class="space-y-4">
                                    <div x-show="pickerSuggestions.length && !picker.q" class="space-y-2">
                                        <p class="px-2 text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">Suggested</p>
                                        <template x-for="sg in pickerSuggestions" :key="'s' + sg.client_id + '-' + sg.account_id">
                                            <button type="button" :disabled="busy"
                                                    @click="picker.mode === 'shoot' ? connectShoot(picker.shoot, sg.client_id) : (sg.account_id ? connect(picker.venture, sg.account_id) : startNew('new-account', sg.client_id))"
                                                    class="w-full flex items-center gap-3 rounded-xl px-3 py-3 text-left ring-1 transition"
                                                    :class="{ strong: @js($strength['strong']), good: @js($strength['good']), weak: @js($strength['weak']) }[sg.strength]">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block font-semibold truncate" x-text="picker.mode === 'shoot' ? client(sg.client_id)?.name : (sg.account_id ? accountLabel(sg.account_id) : 'New account for ' + client(sg.client_id)?.name)"></span>
                                                    <span class="block text-xs opacity-70" x-text="sg.reason"></span>
                                                </span>
                                                <span class="shrink-0 text-xs font-bold">Connect</span>
                                            </button>
                                        </template>
                                    </div>

                                    {{-- A venture: accounts under their clients --}}
                                    <template x-if="picker.mode === 'venture'">
                                        <div class="space-y-3">
                                            <template x-for="g in pickerGroups" :key="g.client.id">
                                                <div>
                                                    <p class="px-2 mb-1 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">
                                                        <span x-text="g.client.name"></span>
                                                        <span x-show="!g.client.active" class="normal-case tracking-normal text-amber-200/80">inactive</span>
                                                    </p>
                                                    <template x-for="a in g.accounts" :key="a.id">
                                                        <button type="button" @click="connect(picker.venture, a.id)" :disabled="busy"
                                                                class="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-white/5 disabled:opacity-50">
                                                            <span class="w-2 h-2 rounded-full bg-brand-400 shrink-0"></span>
                                                            <span class="flex-1 font-medium text-white truncate" x-text="a.name"></span>
                                                            <span class="text-xs text-brand-100/50" x-text="s.ventures.filter((v) => v.account_id === a.id).length + ' connected'"></span>
                                                        </button>
                                                    </template>
                                                    <button type="button" @click="startNew('new-account', g.client.id)"
                                                            class="w-full flex items-center gap-3 rounded-xl px-3 py-2 text-left text-sm text-brand-200 hover:bg-white/5">
                                                        <span class="w-2 text-center">+</span>
                                                        <span x-text="'New account for ' + g.client.name"></span>
                                                    </button>
                                                </div>
                                            </template>
                                            <button type="button" @click="startNew('new-client')"
                                                    class="w-full flex items-center gap-3 rounded-xl px-3 py-3 text-left border border-dashed border-brand-400/50 text-brand-100 hover:bg-brand-400/10">
                                                <span class="w-8 h-8 rounded-lg bg-brand-400 text-brand-900 font-bold flex items-center justify-center">+</span>
                                                <span>
                                                    <span class="block font-semibold" x-text="'New client “' + (picker.q.trim() || picker.venture) + '”'"></span>
                                                    <span class="block text-xs text-brand-100/60">Creates the client and its account, and connects this venture — in one go.</span>
                                                </span>
                                            </button>
                                        </div>
                                    </template>

                                    {{-- A shoot name: straight to a client --}}
                                    <template x-if="picker.mode === 'shoot'">
                                        <div class="space-y-1">
                                            <template x-for="c in pickerClients" :key="c.id">
                                                <button type="button" @click="connectShoot(picker.shoot, c.id)" :disabled="busy"
                                                        class="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-white/5 disabled:opacity-50">
                                                    <span class="w-8 h-8 rounded-lg bg-brand-400/15 text-brand-200 text-xs font-bold flex items-center justify-center shrink-0" x-text="initials(c.name)"></span>
                                                    <span class="flex-1 font-medium text-white truncate" x-text="c.name"></span>
                                                    <span x-show="!c.active" class="text-xs text-amber-200/80">inactive</span>
                                                </button>
                                            </template>
                                            <button type="button" @click="connectShoot(picker.shoot, null)" :disabled="busy"
                                                    class="w-full rounded-xl px-3 py-2.5 text-left text-sm text-brand-100/60 hover:bg-white/5">No client — leave these shoots unfiled</button>
                                        </div>
                                    </template>

                                    {{-- An account: which venture to add --}}
                                    <template x-if="picker.mode === 'account'">
                                        <div class="space-y-1">
                                            <template x-for="v in pickerVentures" :key="v.name">
                                                <button type="button" @click="connect(v.name, picker.accountId)" :disabled="busy"
                                                        class="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-white/5 disabled:opacity-50">
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block font-medium text-white truncate" x-text="v.name"></span>
                                                        <span class="block text-xs truncate" :class="v.account_id ? 'text-amber-200/80' : 'text-brand-100/50'"
                                                              x-text="v.account_id ? 'moves from ' + accountLabel(v.account_id) : (v.samples[0] || '')"></span>
                                                    </span>
                                                    <span class="text-xs text-brand-100/50 tabular-nums" x-text="v.items + ' items'"></span>
                                                </button>
                                            </template>
                                            <p x-show="!pickerVentures.length" class="p-4 text-center text-sm text-brand-100/60">No venture matches.</p>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Making a new client, or a new account for an existing one --}}
                            <template x-if="picker.step !== 'pick'">
                                <form class="space-y-4 p-2" @submit.prevent="createAndConnect()">
                                    <template x-if="picker.step === 'new-client'">
                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-100/80">Client name</span>
                                            <input type="text" x-model="picker.clientName" required
                                                   class="mt-1.5 w-full min-h-[46px] rounded-xl border-white/15 bg-brand-900/60 text-base focus:border-brand-400 focus:ring-brand-400">
                                            <span class="mt-1 block text-xs text-brand-100/50">The business, as it should appear across the portal. Add its details later on the Clients page.</span>
                                        </label>
                                    </template>
                                    <template x-if="picker.step === 'new-account'">
                                        <p class="text-sm text-brand-100/70">A new account under <span class="font-semibold text-white" x-text="client(picker.clientId)?.name"></span>.</p>
                                    </template>
                                    <label class="block">
                                        <span class="block text-sm font-medium text-brand-100/80">Account name</span>
                                        <input type="text" x-model="picker.accountName" required
                                               class="mt-1.5 w-full min-h-[46px] rounded-xl border-white/15 bg-brand-900/60 text-base focus:border-brand-400 focus:ring-brand-400">
                                        <span class="mt-1 block text-xs text-brand-100/50">One per publishing identity — e.g. a brand's main Instagram. Monthly targets can be set on the Clients tab.</span>
                                    </label>
                                    <p class="rounded-xl bg-white/5 px-3 py-2 text-sm text-brand-100/70">
                                        Connects <span class="font-semibold text-white" x-text="'“' + picker.venture + '”'"></span>
                                        (<span x-text="s.ventures.find((v) => v.name === picker.venture)?.items || 0"></span> items) to it.
                                    </p>
                                    <div class="flex gap-2">
                                        <button type="button" @click="picker.step = 'pick'" class="min-h-[46px] px-4 rounded-xl ring-1 ring-white/15 text-sm font-semibold hover:bg-white/5">Back</button>
                                        <button type="submit" :disabled="busy" class="flex-1 min-h-[46px] rounded-xl bg-brand-400 text-brand-900 text-sm font-bold hover:bg-brand-500 disabled:opacity-50"
                                                x-text="picker.step === 'new-client' ? 'Create client and connect' : 'Create account and connect'"></button>
                                    </div>
                                </form>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ════════════════ Toasts ════════════════ --}}
        <div class="fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 pointer-events-none sm:items-end sm:right-6 sm:left-auto">
            <template x-for="t in toasts" :key="t.id">
                <div x-transition.opacity class="pointer-events-auto w-full max-w-md flex items-center gap-3 rounded-2xl px-4 py-3 shadow-2xl ring-1"
                     :class="t.tone === 'error' ? 'bg-red-950/95 ring-red-400/40 text-red-100' : 'bg-brand-900/95 ring-white/15 text-white'">
                    <span class="flex-1 text-sm" x-text="t.text"></span>
                    {{-- !! matters: Alpine CALLS an expression that evaluates to a function, so a bare t.undo ran the undo the moment the toast rendered. --}}
                    <button type="button" x-show="!!t.undo" @click="undo(t)" class="shrink-0 text-sm font-bold text-brand-300 hover:text-white">Undo</button>
                    <button type="button" @click="dismiss(t.id)" class="shrink-0 w-7 h-7 rounded-full text-white/50 hover:bg-white/10 hover:text-white" aria-label="Dismiss">✕</button>
                </div>
            </template>
        </div>
    </div>
</x-settings-layout>
