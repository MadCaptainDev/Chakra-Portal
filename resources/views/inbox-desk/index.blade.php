@php
    /**
     * Inbox Check -- see App\Services\InboxDesk and resources/js/inbox-desk.js.
     *
     * Built for a phone first: one column of account cards, two big tiles
     * each (DMs, Comments), one tap to check. Everything below renders from
     * the Alpine state so a poll or a tap can redraw it without a reload.
     */
    $config = [
        'board' => $board,
        'routes' => [
            'state' => route('inbox-desk.state'),
            'check' => route('inbox-desk.check', ['occurrence' => '__ID__']),
            'undo' => route('inbox-desk.undo', ['occurrence' => '__ID__']),
        ],
    ];
    $canEditRoutines = auth()->user()->can('routines.edit');
@endphp

<x-app-layout title="Inbox Check">
    <x-slot name="header">
        <x-page-header eyebrow="Daily · Instagram" title="Inbox Check"
                       subtitle="DMs and comments, account by account. Tap a tile when it is done.">
            @if ($canEditRoutines && Route::has('routines.index'))
                <x-slot name="actions">
                    <x-btn :href="route('routines.checking')" variant="secondary" size="sm" icon="clipboard-list">All routines</x-btn>
                    <x-btn :href="route('routines.index')" variant="secondary" size="sm" icon="cog">Set up</x-btn>
                </x-slot>
            @endif
        </x-page-header>
    </x-slot>

    <div x-data="inboxDesk({{ Js::from($config) }})" class="max-w-5xl space-y-5 pb-24">

        {{-- ═════════ Nothing set up ═════════ --}}
        <template x-if="b.routines.length === 0">
            <x-card padding="lg" class="text-center">
                <div class="mx-auto w-14 h-14 rounded-2xl bg-brand-400/15 text-brand-300 flex items-center justify-center mb-4">
                    <x-icon name="inbox" class="w-7 h-7" />
                </div>
                @if (auth()->user()->isAdmin())
                    <p class="text-lg font-semibold text-white">No Instagram check is set up yet</p>
                    <p class="mt-1 text-sm text-brand-100/60 max-w-sm mx-auto">
                        Create a daily routine scoped to accounts, pick the Instagram accounts and who checks them. It shows up here the same day.
                    </p>
                    @if (Route::has('routines.index'))
                        <x-btn :href="route('routines.index')" class="mt-5" icon="plus">Set up a routine</x-btn>
                    @endif
                @else
                    <p class="text-lg font-semibold text-white">Nothing to check</p>
                    <p class="mt-1 text-sm text-brand-100/60">No Instagram accounts are assigned to you. Your admin can add you on the routine.</p>
                @endif
            </x-card>
        </template>

        <template x-if="b.routines.length > 0">
            <div class="space-y-5">

                {{-- ═════════ Today at a glance ═════════ --}}
                <section class="relative overflow-hidden rounded-2xl ring-1 p-5 sm:p-6 transition-colors duration-500"
                         :class="allDone ? 'bg-emerald-400/10 ring-emerald-400/25' : 'bg-gradient-to-br from-brand-400/15 via-white/5 to-white/5 ring-white/10'">
                    <div class="flex items-center gap-5 sm:gap-7">
                        <div class="relative shrink-0 w-28 h-28 sm:w-32 sm:h-32">
                            <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90" aria-hidden="true">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="10" class="text-white/10" />
                                <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"
                                        :stroke-dasharray="2 * Math.PI * 52" :stroke-dashoffset="ringOffset"
                                        class="transition-all duration-700 ease-out"
                                        :class="allDone ? 'text-emerald-400' : 'text-brand-400'" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <template x-if="!allDone">
                                    <p class="text-2xl sm:text-3xl font-bold text-white tabular-nums leading-none">
                                        <span x-text="b.totals.done"></span><span class="text-brand-100/40 text-lg">/<span x-text="b.totals.total"></span></span>
                                    </p>
                                </template>
                                <template x-if="allDone">
                                    <svg class="w-11 h-11 text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </template>
                                <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-brand-100/50" x-show="!allDone">checks</p>
                            </div>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-300" x-text="b.date_label"></p>

                            <template x-if="b.totals.total === 0">
                                <div>
                                    <p class="mt-1 text-xl sm:text-2xl font-bold text-white">Nothing due today</p>
                                    <p class="mt-1 text-sm text-brand-100/60">No account has a check scheduled for today.</p>
                                </div>
                            </template>

                            <template x-if="b.totals.total > 0 && !allDone">
                                <div>
                                    <p class="mt-1 text-xl sm:text-2xl font-bold text-white leading-tight">
                                        <span x-text="b.totals.left"></span>
                                        <span x-text="b.totals.left === 1 ? 'check left' : 'checks left'"></span>
                                    </p>
                                    <p class="mt-1 text-sm text-brand-100/70">
                                        on <span class="font-semibold text-white" x-text="b.totals.accounts_left"></span>
                                        <span x-text="b.totals.accounts_left === 1 ? 'account' : 'accounts'"></span>
                                        <template x-if="b.totals.late > 0">
                                            <span> · <span class="font-semibold text-amber-300"><span x-text="b.totals.late"></span> late from earlier</span></span>
                                        </template>
                                    </p>
                                </div>
                            </template>

                            <template x-if="allDone">
                                <div>
                                    <p class="mt-1 text-xl sm:text-2xl font-bold text-white">All inboxes clear</p>
                                    <p class="mt-1 text-sm text-emerald-100/80">
                                        Every DM and comment check is done for today<template x-if="b.feed[0]"><span> — last by <span x-text="b.feed[0].by"></span> at <span x-text="b.feed[0].at_label"></span></span></template>.
                                    </p>
                                </div>
                            </template>

                            <div class="mt-3 h-1.5 rounded-full bg-white/10 overflow-hidden sm:hidden">
                                <div class="h-full rounded-full transition-all duration-700" :class="allDone ? 'bg-emerald-400' : 'bg-brand-400'" :style="`width:${pct}%`"></div>
                            </div>

                            <p class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-medium"
                               :class="offline ? 'text-amber-300' : 'text-brand-100/50'">
                                <span class="relative flex w-2 h-2">
                                    <span class="absolute inline-flex h-full w-full rounded-full opacity-60"
                                          :class="offline ? 'bg-amber-400' : 'bg-emerald-400 animate-ping'"></span>
                                    <span class="relative inline-flex w-2 h-2 rounded-full" :class="offline ? 'bg-amber-400' : 'bg-emerald-400'"></span>
                                </span>
                                <span x-text="syncLabel"></span>
                            </p>
                        </div>
                    </div>
                </section>

                {{-- ═════════ Filter ═════════ --}}
                <div class="flex items-center gap-2 overflow-x-auto -mx-1 px-1" role="tablist" aria-label="Show accounts">
                    <template x-for="f in [['all', 'All'], ['todo', 'To do'], ['done', 'Done']]" :key="f[0]">
                        <button type="button" role="tab" @click="filter = f[0]" :aria-selected="filter === f[0]"
                                class="shrink-0 inline-flex items-center gap-2 min-h-[40px] px-4 rounded-full text-sm font-semibold ring-1 transition"
                                :class="filter === f[0] ? 'bg-white text-brand-900 ring-white' : 'bg-white/5 text-brand-100/80 ring-white/10 hover:bg-white/10'">
                            <span x-text="f[1]"></span>
                            <span class="min-w-[20px] h-5 px-1.5 rounded-full text-[11px] leading-5 text-center"
                                  :class="filter === f[0] ? 'bg-brand-900/10' : 'bg-white/10'" x-text="count(f[0])"></span>
                        </button>
                    </template>
                </div>

                {{-- ═════════ Accounts ═════════ --}}
                <template x-for="routine in b.routines" :key="routine.id">
                    <section class="space-y-3">
                        <div x-show="b.routines.length > 1" class="flex items-baseline justify-between gap-3 pt-1">
                            <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-100/60" x-text="routine.title"></h2>
                            <p class="text-xs text-brand-100/50 tabular-nums"><span x-text="routine.done"></span>/<span x-text="routine.total"></span></p>
                        </div>

                        <div x-show="accountsFor(routine).length === 0" class="rounded-xl border border-dashed border-white/10 px-4 py-6 text-center text-sm text-brand-100/50"
                             x-text="filter === 'done' ? 'Nothing finished yet.' : 'Nothing left here. Nice work.'"></div>

                        <div class="grid gap-3 lg:grid-cols-2">
                            <template x-for="account in accountsFor(routine)" :key="account.key">
                                <article class="rounded-2xl ring-1 p-3.5 sm:p-4 transition-colors duration-300"
                                         :class="account.total > 0 && account.left === 0 ? 'bg-emerald-400/[0.04] ring-emerald-400/15' : (account.late > 0 ? 'bg-white/5 ring-amber-400/30' : 'bg-white/5 ring-white/10')">

                                    {{-- Who --}}
                                    <div class="flex items-center gap-3">
                                        <div class="relative shrink-0" x-data="{ broken: false }">
                                            <template x-if="account.avatar && !broken">
                                                <img :src="account.avatar" alt="" referrerpolicy="no-referrer" x-on:error="broken = true"
                                                     class="w-11 h-11 rounded-full object-cover ring-2"
                                                     :class="account.total > 0 && account.left === 0 ? 'ring-emerald-400/70' : 'ring-white/10'">
                                            </template>
                                            <template x-if="!account.avatar || broken">
                                                <span class="w-11 h-11 rounded-full bg-gradient-to-br from-fuchsia-500 via-rose-500 to-amber-400 text-white text-sm font-bold flex items-center justify-center ring-2"
                                                      :class="account.total > 0 && account.left === 0 ? 'ring-emerald-400/70' : 'ring-white/10'"
                                                      x-text="initials(account.handle)"></span>
                                            </template>
                                            <span x-show="account.total > 0 && account.left === 0" x-cloak
                                                  class="absolute -bottom-0.5 -right-0.5 w-5 h-5 rounded-full bg-emerald-400 text-brand-900 flex items-center justify-center ring-2 ring-brand-900">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </span>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <p class="font-semibold text-white truncate" x-text="account.handle"></p>
                                            <p class="text-xs text-brand-100/50 truncate" x-text="account.name || ' '"></p>
                                        </div>

                                        <span class="shrink-0 text-[11px] font-semibold px-2 py-1 rounded-full"
                                              x-show="account.total > 0"
                                              :class="account.left === 0 ? 'bg-emerald-400/15 text-emerald-300' : (account.late > 0 ? 'bg-amber-400/15 text-amber-300' : 'bg-white/10 text-brand-100/70')"
                                              x-text="account.left === 0 ? 'Done' : (account.late > 0 ? 'Late' : account.left + ' left')"></span>

                                        <a x-show="account.profile_url" :href="account.profile_url" target="_blank" rel="noopener"
                                           class="shrink-0 w-10 h-10 -mr-1 rounded-full flex items-center justify-center text-brand-100/60 hover:bg-white/10 hover:text-white"
                                           :aria-label="`Open ${account.handle} on Instagram`" title="Open on Instagram">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none" /></svg>
                                        </a>
                                    </div>

                                    {{-- The checks --}}
                                    <div class="mt-3 grid gap-2" :class="routine.checkpoints.length > 1 ? 'grid-cols-2' : 'grid-cols-1'">
                                        <template x-for="cp in routine.checkpoints" :key="cp.id">
                                            <button type="button" @click="tap(routine, account, cp)"
                                                    :disabled="cell(account, cp).state === 'none'"
                                                    :aria-expanded="expanded === tileKey(routine, account, cp)"
                                                    class="relative text-left min-h-[76px] rounded-xl p-3 ring-1 transition duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 active:scale-[0.98]"
                                                    :class="{
                                                        'bg-white/[0.06] ring-white/10 hover:bg-white/10': cell(account, cp).state === 'open',
                                                        'bg-emerald-400/10 ring-emerald-400/30': cell(account, cp).state === 'done',
                                                        'bg-white/[0.03] ring-white/10': cell(account, cp).state === 'skipped',
                                                        'bg-transparent ring-white/5 opacity-50 cursor-default': cell(account, cp).state === 'none',
                                                        '!ring-2 !ring-brand-300': expanded === tileKey(routine, account, cp),
                                                        '!ring-2 !ring-emerald-300 shadow-[0_0_24px_rgba(52,211,153,0.35)]': flash[tileKey(routine, account, cp)],
                                                        'opacity-70': pending[tileKey(routine, account, cp)],
                                                    }">
                                                <div class="flex items-start justify-between gap-2">
                                                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold"
                                                          :class="cell(account, cp).state === 'done' ? 'text-emerald-200' : 'text-white'">
                                                        <svg x-show="cp.kind === 'messages'" class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 3L3 10.5l7.5 3L14 21l7-18zM10.5 13.5L21 3" /></svg>
                                                        <svg x-show="cp.kind !== 'messages'" class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m-8 6l2.5-3H18a3 3 0 003-3V7a3 3 0 00-3-3H6a3 3 0 00-3 3v7a3 3 0 002 2.83V20z" /></svg>
                                                        <span x-text="cp.short"></span>
                                                    </span>

                                                    {{-- State mark --}}
                                                    <span class="shrink-0 w-6 h-6 rounded-full flex items-center justify-center transition"
                                                          :class="cell(account, cp).state === 'done' ? 'bg-emerald-400 text-brand-900' : 'ring-2 ring-white/20'">
                                                        <svg x-show="cell(account, cp).state === 'done'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                                        <svg x-show="pending[tileKey(routine, account, cp)] && cell(account, cp).state !== 'done'" class="w-3.5 h-3.5 animate-spin text-white/70" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" stroke-dasharray="40 20" /></svg>
                                                    </span>
                                                </div>

                                                <div class="mt-1.5 text-xs leading-snug">
                                                    <template x-if="cell(account, cp).state === 'open'">
                                                        <div class="space-y-1">
                                                            <template x-if="cell(account, cp).late_days > 0">
                                                                <span class="inline-block rounded-md bg-amber-400/15 px-1.5 py-0.5 text-[11px] font-semibold text-amber-300"
                                                                      x-text="cell(account, cp).late_days === 1 ? 'Missed yesterday' : `${cell(account, cp).late_days} days late`"></span>
                                                            </template>
                                                            <p :class="hint(account, cp) && hint(account, cp).count > 0 ? 'font-semibold text-brand-300' : 'text-brand-100/50'"
                                                               x-text="hint(account, cp) ? hint(account, cp).label : 'Tap when checked'"></p>
                                                        </div>
                                                    </template>
                                                    <template x-if="cell(account, cp).state === 'done'">
                                                        <p class="text-emerald-100/70">
                                                            <template x-if="cell(account, cp).count !== null && cell(account, cp).count !== undefined">
                                                                <span><span class="font-semibold text-emerald-200" x-text="cell(account, cp).count"></span> replied · </span>
                                                            </template>
                                                            <span x-text="`${cell(account, cp).by || ''} ${cell(account, cp).at_label || ''}`"></span>
                                                        </p>
                                                    </template>
                                                    <template x-if="cell(account, cp).state === 'skipped'">
                                                        <p class="text-brand-100/50">Skipped<span x-show="cell(account, cp).by" x-text="` by ${cell(account, cp).by}`"></span></p>
                                                    </template>
                                                    <template x-if="cell(account, cp).state === 'none'">
                                                        <p class="text-brand-100/40">Not due today</p>
                                                    </template>
                                                </div>
                                            </button>
                                        </template>
                                    </div>

                                    {{-- The open tile's panel: how many, then done. --}}
                                    <template x-for="cp in routine.checkpoints" :key="'panel-' + cp.id">
                                        <div x-show="expanded === tileKey(routine, account, cp)" x-transition.opacity.duration.150ms x-cloak>
                                            <div class="mt-2 rounded-xl bg-brand-900/60 ring-1 ring-white/10 p-3.5">
                                                <template x-if="cell(account, cp).state === 'open'">
                                                    <div>
                                                        <div class="flex items-center justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <p class="text-sm font-semibold text-white" x-text="cp.field ? cp.field.label : cp.name"></p>
                                                                <p class="text-xs text-brand-100/50" x-show="cell(account, cp).outstanding > 1"
                                                                   x-text="`Closes ${cell(account, cp).outstanding} days in one go`"></p>
                                                                <p class="text-xs text-brand-100/50" x-show="cell(account, cp).outstanding <= 1 && hint(account, cp)"
                                                                   x-text="hint(account, cp) ? `Instagram: ${hint(account, cp).label.toLowerCase()} since the last check` : ''"></p>
                                                            </div>
                                                            <div class="shrink-0 flex items-center rounded-full bg-white/5 ring-1 ring-white/10">
                                                                <button type="button" @click="step(tileKey(routine, account, cp), -1)"
                                                                        class="w-11 h-11 rounded-full text-xl text-white/80 hover:bg-white/10" aria-label="One fewer">−</button>
                                                                <input type="number" inputmode="numeric" min="0" max="9999"
                                                                       x-model.number="counts[tileKey(routine, account, cp)]"
                                                                       class="w-12 bg-transparent border-0 p-0 text-center text-lg font-bold text-white tabular-nums focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                                                                       aria-label="How many replied">
                                                                <button type="button" @click="step(tileKey(routine, account, cp), 1)"
                                                                        class="w-11 h-11 rounded-full text-xl text-white/80 hover:bg-white/10" aria-label="One more">+</button>
                                                            </div>
                                                        </div>
                                                        <div class="mt-3 grid grid-cols-2 gap-2">
                                                            <button type="button" @click="check(routine, account, cp, 0)"
                                                                    class="min-h-[44px] rounded-lg bg-white/10 ring-1 ring-white/15 text-sm font-semibold text-white hover:bg-white/15">
                                                                Nothing new
                                                            </button>
                                                            <button type="button" @click="check(routine, account, cp, counts[tileKey(routine, account, cp)] || 0)"
                                                                    class="min-h-[44px] rounded-lg bg-brand-400 text-sm font-bold text-brand-900 hover:bg-brand-300">
                                                                <span x-text="(counts[tileKey(routine, account, cp)] || 0) > 0 ? `Done · ${counts[tileKey(routine, account, cp)]} replied` : 'Done'"></span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </template>

                                                <template x-if="cell(account, cp).state === 'done'">
                                                    <div class="flex items-center justify-between gap-3">
                                                        <p class="text-sm text-brand-100/80">
                                                            Checked by <span class="font-semibold text-white" x-text="cell(account, cp).by"></span>
                                                            at <span x-text="cell(account, cp).at_label"></span><template x-if="cell(account, cp).count !== null && cell(account, cp).count !== undefined"><span> · <span x-text="cell(account, cp).count"></span> replied</span></template>
                                                        </p>
                                                        <button type="button" x-show="cell(account, cp).can_undo" @click="undo(routine, account, cp)"
                                                                class="shrink-0 min-h-[40px] px-3 rounded-lg text-sm font-semibold text-brand-300 hover:bg-white/10">
                                                            Undo
                                                        </button>
                                                    </div>
                                                </template>

                                                <template x-if="cell(account, cp).state === 'skipped'">
                                                    <p class="text-sm text-brand-100/70">Skipped<span x-show="cell(account, cp).note" x-text="`: ${cell(account, cp).note}`"></span></p>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    {{-- One tap for a quiet account. --}}
                                    <button type="button" x-show="account.left > 1" @click="clearAccount(routine, account)"
                                            class="mt-2 w-full min-h-[40px] rounded-lg text-xs font-semibold text-brand-100/60 hover:bg-white/5 hover:text-white">
                                        Nothing new on any — mark all checked
                                    </button>
                                </article>
                            </template>
                        </div>
                    </section>
                </template>

                {{-- ═════════ Today's activity ═════════ --}}
                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-100/60 mb-2">Today's activity</h2>
                    <x-card padding="sm">
                        <p x-show="b.feed.length === 0" class="text-sm text-brand-100/50 py-1">No checks yet today. They will appear here as they happen.</p>
                        <ol class="divide-y divide-white/5">
                            <template x-for="(item, i) in b.feed" :key="item.at + item.handle + item.checkpoint">
                                <li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                    <span class="w-8 h-8 shrink-0 rounded-full bg-emerald-400/15 text-emerald-300 text-xs font-bold flex items-center justify-center"
                                          x-text="(item.by || '?').slice(0, 1).toUpperCase()"></span>
                                    <p class="min-w-0 flex-1 text-sm text-brand-100/80 truncate">
                                        <span class="font-semibold text-white" x-text="item.by"></span>
                                        checked <span x-text="item.checkpoint"></span>
                                        on <span class="text-white" x-text="item.handle"></span><template x-if="item.count !== null"><span class="text-brand-100/50"> · <span x-text="item.count"></span> replied</span></template>
                                    </p>
                                    <span class="shrink-0 text-xs text-brand-100/50 tabular-nums" x-text="item.at_label"></span>
                                </li>
                            </template>
                        </ol>
                    </x-card>
                </section>

                <p class="text-xs text-brand-100/40 text-center">
                    Want this on your home screen? Add the <span class="text-brand-100/70">inbox</span> widget from
                    <a href="{{ route('profile.edit') }}#phone-widget" class="underline hover:text-white">My Profile → Phone widget</a>.
                </p>
            </div>
        </template>

        {{-- ═════════ Toasts ═════════ --}}
        <div class="fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 pointer-events-none sm:items-end sm:right-6 sm:left-auto" aria-live="polite">
            <template x-for="t in toasts" :key="t.id">
                <div x-transition.opacity class="pointer-events-auto w-full max-w-md flex items-center gap-3 rounded-2xl px-4 py-3 shadow-2xl ring-1"
                     :class="t.tone === 'error' ? 'bg-red-950/95 ring-red-400/40 text-red-100' : 'bg-brand-900/95 ring-white/15 text-white'">
                    <span class="flex-1 text-sm" x-text="t.text"></span>
                    {{-- !! matters: Alpine CALLS an expression that evaluates to a function, so a bare t.undo ran the undo the moment the toast rendered. --}}
                    <button type="button" x-show="!!t.undo" @click="runUndo(t)" class="shrink-0 text-sm font-bold text-brand-300 hover:text-white">Undo</button>
                    <button type="button" @click="dismiss(t.id)" class="shrink-0 w-7 h-7 rounded-full text-white/50 hover:bg-white/10 hover:text-white" aria-label="Dismiss">✕</button>
                </div>
            </template>
        </div>
    </div>
</x-app-layout>
