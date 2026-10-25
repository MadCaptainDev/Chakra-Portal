{{--
    My Editing: the editor's videos as cards, To do → Working → Done →
    Posted, corrections as branches. Driven by resources/js/editing-day.js
    against My\EditingController; see the controller for what each list is.

    Phone first: the add box sticks under the top bar and suggests as you
    type; the four lanes are tabs below md and a grid above it.
--}}
@php
    $state = [
        'cards' => $cards,
        'planner' => $planner,
        'hints' => $hints,
        'kinds' => $kinds,
        'editors' => $editors,
        'me' => auth()->id(),
        'urls' => [
            'store' => route('my.editing.store'),
            'act' => route('my.editing.act', '__ID__'),
            'correct' => route('my.editing.correct', '__ID__'),
            'refresh' => route('my.editing.refresh'),
        ],
    ];
    $lanes = [
        'todo' => ['To do', 'To do', 'bg-white/10 text-brand-100/80'],
        'working' => ['Working', 'Working', 'bg-amber-400/15 text-amber-200'],
        'done' => ['Under review', 'Review', 'bg-sky-400/15 text-sky-200'],
        'ready' => ['Video ready', 'Ready', 'bg-violet-400/15 text-violet-200'],
        'posted' => ['Posted / Delivered', 'Out', 'bg-emerald-400/15 text-emerald-200'],
    ];
@endphp

<x-app-layout title="My Editing">
    <div class="mx-auto max-w-6xl space-y-4 pb-10" x-data="editingDay(@js($state))">
        @include('my._team-ready')
        @include('my._team-review')

        {{-- Header, with the Notion refresh kept small on purpose. --}}
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{{ now()->format('l j F') }}</p>
                <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">My Editing</h1>
            </div>
            <div class="flex items-center gap-1">
            <a href="{{ route('my.editing.guide') }}" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-xs font-semibold text-brand-200/80 hover:bg-white/5 hover:text-white" title="How My Editing works">
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand-400/20 text-[11px] font-bold text-brand-200">?</span>
                <span>How it works</span>
            </a>
            <button type="button" @click="refreshNotion()" :disabled="refreshing"
                    class="rounded-lg p-2.5 text-brand-100/40 hover:bg-white/5 hover:text-brand-100/80" title="Refresh from Notion" aria-label="Refresh the Reel Planner from Notion">
                <svg class="h-4 w-4" :class="refreshing && 'animate-spin'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            </button>
            </div>
        </div>

        {{-- Messages --}}
        <div x-show="error" x-cloak class="rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200" role="alert" x-text="error"></div>
        <div x-show="flash" x-cloak x-transition.opacity class="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-full bg-emerald-500 px-4 py-2 text-sm font-semibold text-white shadow-xl" role="status" x-text="flash"></div>

        {{-- Quick add: sticks under the phone's top bar, suggests as you type. --}}
        <div class="sticky top-16 z-20 -mx-4 bg-brand-900/95 px-4 py-2 backdrop-blur sm:mx-0 sm:px-0 lg:static lg:bg-transparent lg:p-0 lg:backdrop-blur-none">
            <div class="relative rounded-2xl bg-white/5 p-2.5 ring-1 ring-white/10 sm:p-4" @click.outside="open = false">
                <form @submit.prevent="enter()" class="flex gap-2">
                    <div class="relative min-w-0 flex-1">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-100/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" /></svg>
                        <input x-ref="add" x-model="title" autocomplete="off" autocapitalize="sentences" enterkeyhint="go"
                               role="combobox" aria-autocomplete="list" aria-controls="editing-matches" :aria-expanded="open && matches.length > 0"
                               @focus="open = true" @input="typed()"
                               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.escape="open = false"
                               placeholder="Type a video or client — e.g. SVA"
                               class="min-h-[48px] w-full rounded-xl border-0 bg-white/10 pl-10 pr-3 text-base text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-brand-400">
                    </div>
                    <button type="submit" :disabled="busy.add || !title.trim()"
                            class="min-h-[48px] shrink-0 rounded-xl bg-brand-400 px-5 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-40">Add</button>
                </form>

                {{-- Suggestions --}}
                <div x-show="open && matches.length > 0" x-cloak x-transition.opacity.duration.100ms
                     class="absolute inset-x-0 top-full z-30 mt-1.5 max-h-[60vh] overflow-y-auto rounded-2xl bg-brand-900 py-1.5 shadow-2xl shadow-black/50 ring-1 ring-white/15">
                    <p x-show="!title.trim()" class="px-4 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-300">Up next for you</p>
                    <ul id="editing-matches" role="listbox">
                        <template x-for="(m, i) in matches" :key="m.key">
                            <li role="option" :aria-selected="hi === i">
                                <button type="button" @click="choose(m)" @mouseenter="hi = i"
                                        class="flex min-h-[52px] w-full items-center gap-3 px-4 py-2 text-left"
                                        :class="hi === i ? 'bg-white/10' : 'hover:bg-white/5'">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                                          :class="{ 'bg-brand-400/20 text-brand-200': m.type === 'planner', 'bg-white/10 text-brand-100/70': m.type === 'title', 'bg-amber-400/15 text-amber-200': m.type === 'client' }"
                                          x-text="m.type === 'planner' ? '＋' : (m.type === 'client' ? 'Aa' : '↺')"></span>
                                    <span class="min-w-0 flex-1">
                                        <template x-if="m.type === 'planner'">
                                            <span class="block">
                                                <span class="block truncate text-sm font-semibold text-white" x-text="m.item.title"></span>
                                                <span class="block truncate text-xs text-brand-100/55">
                                                    <span :class="m.item.late ? 'font-semibold text-red-300' : ''" x-text="'Publishes ' + m.item.publishes"></span>
                                                    · <span x-text="m.item.status"></span>
                                                    <span x-show="!m.item.mine && m.item.editor" x-text="'· ' + m.item.editor + '’s'"></span>
                                                </span>
                                            </span>
                                        </template>
                                        <template x-if="m.type === 'client'">
                                            <span class="block">
                                                <span class="block truncate text-sm font-semibold text-white" x-text="m.text + ' - …'"></span>
                                                <span class="block text-xs text-brand-100/55">Start a title for this client</span>
                                            </span>
                                        </template>
                                        <template x-if="m.type === 'title'">
                                            <span class="block">
                                                <span class="block truncate text-sm text-white" x-text="m.text"></span>
                                                <span class="block text-xs text-brand-100/55">You did one like this — tap to edit the name</span>
                                            </span>
                                        </template>
                                    </span>
                                    <span x-show="m.type === 'planner'" class="shrink-0 rounded-lg bg-brand-400/15 px-2.5 py-1 text-xs font-bold text-brand-200">Add</span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="mt-2 flex items-center gap-1.5 overflow-x-auto pb-0.5 [scrollbar-width:none]">
                    <template x-for="c in hints.clients" :key="c">
                        <button type="button" @click="choose({ type: 'client', text: c })"
                                class="shrink-0 rounded-full bg-white/5 px-3 py-1.5 text-xs font-semibold text-brand-100/80 ring-1 ring-white/10 hover:bg-white/10" x-text="c + ' –'"></button>
                    </template>
                    <span class="ml-auto hidden shrink-0 pl-2 text-xs text-brand-100/50 sm:inline">
                        <span x-text="cards.length"></span> on your board
                        <template x-if="hints.per_day"><span> · you usually do about <span x-text="hints.per_day"></span> a day</span></template>
                    </span>
                </div>
            </div>
        </div>

        {{-- Up next from the Reel Planner: this editor's own. --}}
        <div class="rounded-2xl bg-white/5 ring-1 ring-white/10" x-show="mine.length > 0" x-cloak>
            <button type="button" @click="showPlanner = !showPlanner" class="flex min-h-[48px] w-full items-center justify-between gap-3 px-4 py-3 text-left">
                <span>
                    <span class="text-sm font-semibold text-white">Up next from the Reel Planner</span>
                    <span class="ml-1 text-xs text-brand-100/50" x-text="mine.length + ' to edit'"></span>
                </span>
                <span class="text-brand-100/50" x-text="showPlanner ? '▴' : '▾'"></span>
            </button>
            <ul x-show="showPlanner" class="max-h-80 divide-y divide-white/5 overflow-y-auto border-t border-white/10">
                <template x-for="s in mine" :key="s.id">
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-white" x-text="s.title"></p>
                            <p class="truncate text-xs text-brand-100/55">
                                <span x-text="s.client"></span> ·
                                <span :class="s.late ? 'font-semibold text-red-300' : ''" x-text="'Publishes ' + s.publishes"></span> ·
                                <span x-text="s.status"></span>
                            </p>
                        </div>
                        <button type="button" @click="addFromPlanner(s)" :disabled="!!busy['s' + s.id]"
                                class="min-h-[40px] shrink-0 rounded-lg bg-brand-400/15 px-3.5 text-xs font-bold text-brand-200 hover:bg-brand-400/25 disabled:opacity-40">+ Add</button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- Lane tabs: phones only, one lane at a time. --}}
        <div class="grid grid-cols-5 gap-1 rounded-2xl bg-white/5 p-1 ring-1 ring-white/10 md:hidden" role="tablist">
            @foreach ($lanes as $lane => [$label, $short, $chip])
                <button type="button" role="tab" @click="tab = '{{ $lane }}'" :aria-selected="tab === '{{ $lane }}'"
                        class="flex min-h-[48px] flex-col items-center justify-center rounded-xl text-xs font-semibold transition"
                        :class="tab === '{{ $lane }}' ? 'bg-white/15 text-white' : 'text-brand-100/60'">
                    <span>{{ $short }}</span>
                    <span class="mt-0.5 rounded-full px-1.5 text-[11px] tabular-nums {{ $chip }}" x-text="lane('{{ $lane }}').length"></span>
                </button>
            @endforeach
        </div>

        {{-- The board --}}
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            @foreach ($lanes as $lane => [$label, $short, $chip])
                <section class="rounded-2xl bg-white/[0.03] p-2 ring-1 ring-white/10 md:block" :class="tab === '{{ $lane }}' ? 'block' : 'hidden'">
                    <h2 class="hidden items-center justify-between px-2 pb-2 pt-1 text-xs font-semibold uppercase tracking-[0.14em] text-brand-100/60 md:flex">
                        {{ $label }}
                        <span class="rounded-full px-2 py-0.5 text-[11px] normal-case tracking-normal {{ $chip }}" x-text="lane('{{ $lane }}').length"></span>
                    </h2>
                    @if ($lane === 'done')
                        <p class="px-2 pb-2 pt-1 text-xs text-brand-100/50 md:hidden">Needs a change? Add a correction. All good? Make it Video ready with its publishing time — or Delivered.</p>
                    @endif
                    <div class="space-y-2">
                        <template x-for="card in lane('{{ $lane }}')" :key="card.id">
                            <article class="rounded-xl bg-brand-900/80 p-3.5 ring-1 ring-white/10 sm:p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold leading-snug text-white" x-text="card.title"></p>
                                    <span class="-mr-1 -mt-1 flex shrink-0">
                                    {{-- Hand it to someone else. --}}
                                    <button type="button" x-show="!card.posted_at && editors.length > 1" @click="handing = handing === card.id ? null : card.id; handTo = ''"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg text-brand-100/35 hover:bg-white/10 hover:text-white sm:h-7 sm:w-7"
                                            :class="handing === card.id ? 'bg-white/10 text-white' : ''" title="Reassign to another editor" aria-label="Reassign to another editor">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                                    </button>
                                    {{-- Added by mistake, or not yours: take it off the board. --}}
                                    <button type="button" x-show="!card.posted_at" @click="remove(card)" :disabled="!!busy[card.id + 'remove']"
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-brand-100/35 hover:bg-red-500/15 hover:text-red-300 disabled:opacity-40 sm:h-7 sm:w-7"
                                            title="Remove from my board" aria-label="Remove from my board">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-brand-100/55">
                                    <span x-text="card.client"></span>
                                    <template x-if="card.publishes"><span> · publishes <span x-text="card.publishes"></span></span></template>
                                    <template x-if="card.working_minutes > 0 || card.status === 'started'"><span> · <span x-text="timer(card)"></span> worked</span></template>
                                </p>

                                <template x-if="card.sent_back">
                                    <p class="mt-2 rounded-lg bg-red-500/10 px-2 py-1.5 text-xs text-red-200">Sent back: <span x-text="card.sent_back"></span></p>
                                </template>

                                <template x-if="card.status === 'blocked'">
                                    <p class="mt-2 rounded-lg bg-violet-400/10 px-2 py-1.5 text-xs font-semibold text-violet-200">⏸ On hold<span class="font-normal" x-show="!!card.held" x-text="' — ' + card.held"></span></p>
                                </template>

                                {{-- Corrections: branches on this video. --}}
                                <template x-if="card.corrections.length > 0">
                                    <ul class="mt-2 space-y-0.5 border-l-2 border-amber-400/40 pl-2.5">
                                        <template x-for="fix in card.corrections" :key="fix.id">
                                            <li>
                                                <label class="flex min-h-[36px] cursor-pointer items-center gap-2.5 text-sm sm:min-h-0 sm:items-start sm:py-0.5 sm:text-xs">
                                                    <input type="checkbox" :checked="fix.status === 'completed'" @change="toggleFix(card, fix)"
                                                           class="h-5 w-5 shrink-0 rounded border-white/20 bg-white/10 text-emerald-500 focus:ring-emerald-400 sm:mt-0.5 sm:h-4 sm:w-4">
                                                    <span :class="fix.status === 'completed' ? 'text-brand-100/40 line-through' : 'text-amber-100'" x-text="fix.title"></span>
                                                </label>
                                            </li>
                                        </template>
                                    </ul>
                                </template>

                                <template x-if="fixing === card.id">
                                    <form @submit.prevent="addFix(card)" class="mt-2 flex gap-1.5">
                                        <input x-model="fixText" x-init="$el.focus()" placeholder="What needs fixing?" maxlength="255"
                                               class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-base text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-amber-400 sm:min-h-[36px] sm:text-xs">
                                        <button class="rounded-lg bg-amber-400 px-3.5 text-sm font-bold text-brand-900 sm:text-xs">Add</button>
                                        <button type="button" @click="fixing = null" class="px-2 text-sm text-brand-100/50" aria-label="Cancel">✕</button>
                                    </form>
                                </template>

                                {{-- Hold: a tap on a usual reason, or type one (optional). --}}
                                <template x-if="holding === card.id">
                                    <div class="mt-2 space-y-1.5 rounded-lg bg-violet-400/10 p-2">
                                        <p class="text-xs font-semibold text-violet-200">Why is it on hold?</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="r in ['Waiting for footage', 'Waiting for client', 'Waiting for approval', 'Waiting for assets']" :key="r">
                                                <button type="button" @click="hold(card, r)" :disabled="!!busy[card.id + 'hold']"
                                                        class="min-h-[40px] rounded-full bg-white/5 px-3 text-xs font-semibold text-brand-100/80 ring-1 ring-white/10 hover:bg-white/10 disabled:opacity-40 sm:min-h-[30px] sm:text-[11px]" x-text="r"></button>
                                            </template>
                                        </div>
                                        <form @submit.prevent="hold(card)" class="flex gap-1.5">
                                            <input x-model="holdText" x-init="$el.focus()" placeholder="Or type the reason (optional)" maxlength="255"
                                                   class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-base text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-violet-400 sm:min-h-[36px] sm:text-xs">
                                            <button :disabled="!!busy[card.id + 'hold']" class="rounded-lg bg-violet-400 px-3.5 text-sm font-bold text-brand-900 disabled:opacity-40 sm:text-xs">Hold</button>
                                            <button type="button" @click="holding = null" class="px-2 text-sm text-brand-100/50" aria-label="Cancel">✕</button>
                                        </form>
                                    </div>
                                </template>

                                {{-- What you can do next, by stage. --}}
                                <div class="mt-3 flex flex-wrap gap-2 sm:mt-2.5 sm:gap-1.5">
                                    <template x-if="card.status === 'waiting'">
                                        <button type="button" @click="act(card, 'start')" :disabled="!!busy[card.id + 'start']"
                                                class="min-h-[44px] flex-1 rounded-lg bg-amber-400 px-3 text-sm font-bold text-brand-900 hover:bg-amber-300 disabled:opacity-50 sm:min-h-[32px] sm:flex-none sm:text-xs">▶ Start working</button>
                                    </template>
                                    <template x-if="card.status === 'blocked'">
                                        <button type="button" @click="act(card, 'start')" :disabled="!!busy[card.id + 'start']"
                                                class="min-h-[44px] flex-1 rounded-lg bg-amber-400 px-3 text-sm font-bold text-brand-900 hover:bg-amber-300 disabled:opacity-50 sm:min-h-[32px] sm:flex-none sm:text-xs">▶ Resume</button>
                                    </template>
                                    <template x-if="card.status === 'started'">
                                        <button type="button" @click="act(card, 'done')" :disabled="openFixes(card) > 0 || !!busy[card.id + 'done']"
                                                :title="openFixes(card) > 0 ? 'Finish the corrections first' : ''"
                                                class="min-h-[44px] flex-1 rounded-lg bg-sky-400 px-3 text-sm font-bold text-brand-900 hover:bg-sky-300 disabled:opacity-40 sm:min-h-[32px] sm:flex-none sm:text-xs">✓ Done — send for review</button>
                                    </template>
                                    <template x-if="['waiting', 'started'].includes(card.status)">
                                        <button type="button" @click="holding = card.id; holdText = ''; fixing = null"
                                                class="min-h-[44px] rounded-lg bg-white/5 px-3 text-sm font-semibold text-violet-200 ring-1 ring-white/10 hover:bg-white/10 sm:min-h-[32px] sm:text-xs">⏸ Hold</button>
                                    </template>
                                    <template x-if="['waiting', 'started', 'blocked'].includes(card.status)">
                                        <button type="button" @click="moveOn(card, 'next')" :disabled="!!busy[card.id + 'next']" title="Not today — move it to the next day"
                                                class="min-h-[44px] rounded-lg bg-white/5 px-3 text-sm font-semibold text-sky-200 ring-1 ring-white/10 hover:bg-white/10 disabled:opacity-40 sm:min-h-[32px] sm:text-xs">Next day →</button>
                                    </template>
                                    <template x-if="card.status !== 'completed' || !card.posted_at">
                                        <button type="button" @click="fixing = card.id; fixText = ''; holding = null"
                                                class="min-h-[44px] rounded-lg bg-white/5 px-3 text-sm font-semibold text-amber-200 ring-1 ring-white/10 hover:bg-white/10 sm:min-h-[32px] sm:text-xs">+ Correction</button>
                                    </template>
                                </div>

                                {{-- Reassign: hand the video (and its open corrections) to someone else. --}}
                                <template x-if="handing === card.id">
                                    <form @submit.prevent="reassign(card)" class="mt-2 flex gap-1.5 rounded-lg bg-white/5 p-2">
                                        <select x-model="handTo" required aria-label="Hand this video to"
                                                class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-[#0f2230] py-0 pl-3 pr-8 text-sm text-white sm:min-h-[34px] sm:text-xs">
                                            <option value="">Hand it to…</option>
                                            <template x-for="e in editors.filter((e) => e.id !== card.user?.id)" :key="e.id">
                                                <option :value="e.id" x-text="e.id === me ? e.name + ' (me)' : e.name"></option>
                                            </template>
                                        </select>
                                        <button :disabled="!handTo || !!busy[card.id + 'reassign']" class="shrink-0 rounded-lg bg-white px-3.5 text-sm font-bold text-brand-900 disabled:opacity-40 sm:text-xs">Reassign</button>
                                        <button type="button" @click="handing = null" class="px-2 text-sm text-brand-100/50" aria-label="Cancel">✕</button>
                                    </form>
                                </template>

                                {{-- Under review: a correction (the button above) sends it back
                                     to Working; otherwise it is Video ready with its publishing
                                     time, or delivered to the client. --}}
                                <template x-if="card.status === 'completed' && !card.posted_at && !card.ready">
                                    <div class="mt-2.5 space-y-2 rounded-lg bg-sky-400/[0.06] p-2 ring-1 ring-sky-400/20">
                                        <p class="text-xs font-semibold text-sky-200">In review — needs a change? <span class="font-normal text-sky-100/70">Add a correction. All good? Set when it goes out:</span></p>
                                        <form @submit.prevent="act(card, 'ready', { publish_at: when[card.id] || card.publish_default })" class="flex flex-wrap gap-1.5">
                                            <input type="datetime-local" required :value="when[card.id] || card.publish_default" @input="when[card.id] = $event.target.value"
                                                   class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-base text-white [color-scheme:dark] focus:ring-2 focus:ring-violet-400 sm:min-h-[32px] sm:text-xs">
                                            <button :disabled="openFixes(card) > 0 || !!busy[card.id + 'ready']"
                                                    class="min-h-[44px] shrink-0 rounded-lg bg-violet-500 px-3.5 text-sm font-bold text-white hover:bg-violet-400 disabled:opacity-40 sm:min-h-[32px] sm:text-xs">✓ Video ready</button>
                                        </form>
                                        <button type="button" @click="act(card, 'deliver')" :disabled="openFixes(card) > 0 || !!busy[card.id + 'deliver']"
                                                class="min-h-[44px] w-full rounded-lg bg-teal-500/90 px-3 text-sm font-bold text-white hover:bg-teal-400 disabled:opacity-40 sm:min-h-[32px] sm:text-xs">📦 Delivered to client instead</button>
                                        <button type="button" @click="act(card, 'reopen')" class="min-h-[36px] text-xs text-brand-100/50 underline underline-offset-2 hover:text-white sm:min-h-0 sm:text-[11px]">Back to working</button>
                                    </div>
                                </template>

                                {{-- Video ready: one of three ways out, as tabs -- posted now,
                                     scheduled on Instagram/YouTube for the time the editor
                                     picks (counts as posted then), or delivered to the client. --}}
                                <template x-if="card.ready && !card.posted_at">
                                    <div class="mt-2.5 overflow-hidden rounded-lg ring-1"
                                         :class="card.scheduled ? 'ring-emerald-400/30' : (publishLate(card) ? 'ring-red-400/40' : 'ring-violet-400/25')">
                                        {{-- Scheduled: that is all it needs to say. --}}
                                        <template x-if="card.scheduled && outMode(card) !== 'schedule'">
                                            <div class="flex items-center gap-2 bg-emerald-400/[0.08] px-3 py-2.5">
                                                <span class="text-lg leading-none" aria-hidden="true">🗓</span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-sm font-semibold text-emerald-200 sm:text-xs"><span x-text="kinds[card.kind] ?? 'Reel'"></span> scheduled</p>
                                                    <p class="text-xs text-emerald-100/70 sm:text-[11px]" x-text="card.publish_label + ' · posts itself'"></p>
                                                </div>
                                                <button type="button" @click="setMode(card, 'schedule')" class="rounded-md px-2 py-1 text-xs text-brand-100/70 hover:bg-white/10 hover:text-white">Change</button>
                                                <button type="button" @click="act(card, 'unschedule')" class="rounded-md px-2 py-1 text-xs text-brand-100/50 hover:bg-white/10 hover:text-white">Undo</button>
                                            </div>
                                        </template>

                                        <template x-if="!card.scheduled || outMode(card) === 'schedule'">
                                            <div>
                                                <p class="flex items-center justify-between gap-2 px-3 pt-2.5 text-xs font-semibold"
                                                   :class="publishLate(card) ? 'text-red-200' : 'text-violet-200'">
                                                    <span><span x-text="publishLate(card) ? '⚠ Was due ' : 'Due '"></span><span x-text="card.publish_label"></span></span>
                                                    <span class="font-normal opacity-70" x-text="publishIn(card).replace(/^· /, '')"></span>
                                                </p>
                                                {{-- How it goes out --}}
                                                <div class="mx-3 mt-2 grid grid-cols-3 rounded-lg bg-white/5 p-0.5 text-xs font-semibold" role="tablist">
                                                    <template x-for="m in [['post', 'Post now'], ['schedule', 'Schedule'], ['deliver', 'Deliver']]" :key="m[0]">
                                                        <button type="button" role="tab" @click="setMode(card, m[0])" :aria-selected="outMode(card) === m[0]"
                                                                class="min-h-[38px] rounded-md transition sm:min-h-[30px]"
                                                                :class="outMode(card) === m[0] ? 'bg-white text-brand-900' : 'text-brand-100/60 hover:text-white'" x-text="m[1]"></button>
                                                    </template>
                                                </div>

                                                <div class="space-y-2 p-3">
                                                    {{-- Type: posting and scheduling both need it. --}}
                                                    <div x-show="outMode(card) !== 'deliver'" class="flex flex-wrap gap-1">
                                                        <template x-for="key in (outMode(card) === 'schedule' ? ['reel', 'youtube'] : ['reel', 'youtube', 'post', 'story'])" :key="key">
                                                            <button type="button" @click="pick[card.id] = key"
                                                                    :class="kindOf(card) === key ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-100/70 hover:bg-white/10'"
                                                                    class="min-h-[36px] rounded-full px-3 text-xs font-semibold sm:min-h-[28px] sm:text-[11px]" x-text="kinds[key]"></button>
                                                        </template>
                                                    </div>

                                                    <template x-if="outMode(card) === 'post'">
                                                        <form @submit.prevent="act(card, 'post', { kind: kindOf(card), post_url: links[card.id] || null })" class="flex gap-1.5">
                                                            <input x-model="links[card.id]" type="url" inputmode="url" placeholder="Link (optional)"
                                                                   class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-base text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-emerald-400 sm:min-h-[32px] sm:text-xs">
                                                            <button :disabled="!!busy[card.id + 'post']" class="shrink-0 rounded-lg bg-emerald-500 px-3.5 text-sm font-bold text-white hover:bg-emerald-400 disabled:opacity-40 sm:text-xs">Mark posted</button>
                                                        </form>
                                                    </template>

                                                    <template x-if="outMode(card) === 'schedule'">
                                                        <form @submit.prevent="schedule(card)" class="space-y-1.5">
                                                            <label class="block text-[11px] text-brand-100/55">When it is scheduled to go live</label>
                                                            <div class="flex gap-1.5">
                                                                <input type="datetime-local" required :value="when['s' + card.id] || card.publish_at" @input="when['s' + card.id] = $event.target.value"
                                                                       class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-base text-white [color-scheme:dark] focus:ring-2 focus:ring-emerald-400 sm:min-h-[32px] sm:text-xs">
                                                                <button :disabled="!!busy[card.id + 'schedule']" class="shrink-0 rounded-lg bg-emerald-500 px-3.5 text-sm font-bold text-white hover:bg-emerald-400 disabled:opacity-40 sm:text-xs"
                                                                        x-text="card.scheduled ? 'Save' : 'Schedule'"></button>
                                                            </div>
                                                            <button type="button" x-show="card.scheduled" @click="setMode(card, 'post')" class="text-[11px] text-brand-100/50 underline underline-offset-2">Cancel</button>
                                                        </form>
                                                    </template>

                                                    <template x-if="outMode(card) === 'deliver'">
                                                        <form @submit.prevent="act(card, 'deliver', { post_url: links[card.id] || null })" class="flex gap-1.5">
                                                            <input x-model="links[card.id]" type="url" inputmode="url" placeholder="Drive / file link (optional)"
                                                                   class="min-h-[44px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-base text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-teal-400 sm:min-h-[32px] sm:text-xs">
                                                            <button :disabled="!!busy[card.id + 'deliver']" class="shrink-0 rounded-lg bg-teal-500 px-3.5 text-sm font-bold text-white hover:bg-teal-400 disabled:opacity-40 sm:text-xs">Delivered</button>
                                                        </form>
                                                    </template>

                                                    <details x-show="outMode(card) === 'post'" class="text-[11px] text-brand-100/50">
                                                        <summary class="cursor-pointer py-0.5 hover:text-white">Change the due time</summary>
                                                        <form @submit.prevent="act(card, 'ready', { publish_at: when[card.id] || card.publish_at })" class="mt-1 flex gap-1.5">
                                                            <input type="datetime-local" required :value="when[card.id] || card.publish_at" @input="when[card.id] = $event.target.value"
                                                                   class="min-h-[40px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-sm text-white [color-scheme:dark] sm:min-h-[30px] sm:text-xs">
                                                            <button class="shrink-0 rounded-lg bg-white/10 px-3 text-xs font-semibold text-white hover:bg-white/15">Save</button>
                                                        </form>
                                                    </details>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <template x-if="card.posted_at">
                                    <div class="mt-2 flex items-center justify-between gap-2 text-sm sm:text-xs">
                                        <span class="font-semibold text-emerald-300">✓ <span x-text="card.kind === 'delivered' ? 'Delivered' : (kinds[card.kind] ?? 'Posted') + ' posted'"></span> <span x-text="card.posted_at"></span></span>
                                        <span class="flex gap-3 sm:gap-2">
                                            <template x-if="card.post_url"><a :href="card.post_url" target="_blank" rel="noopener" class="py-1.5 text-sky-300 hover:underline">Open</a></template>
                                            <button type="button" @click="act(card, 'unpost')" class="py-1.5 text-brand-100/40 hover:text-white">Undo</button>
                                        </span>
                                    </div>
                                </template>
                            </article>
                        </template>
                        <div x-show="lane('{{ $lane }}').length === 0" class="px-2 py-6 text-center text-sm text-brand-100/35 sm:py-4 sm:text-xs">
                            @if ($lane === 'todo')
                                Nothing to do yet — type above or pick from Up next.
                            @else
                                Nothing here
                            @endif
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        {{-- Moved on with "Next day": off today's lanes, back on their own when the day comes. --}}
        <div class="rounded-2xl bg-white/[0.03] ring-1 ring-white/10" x-show="lane('later').length > 0" x-cloak>
            <h2 class="flex items-center justify-between px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-[0.14em] text-brand-100/60">
                Moved to the next day
                <span class="rounded-full bg-sky-400/15 px-2 py-0.5 text-[11px] normal-case tracking-normal text-sky-200" x-text="lane('later').length"></span>
            </h2>
            <ul class="divide-y divide-white/5">
                <template x-for="card in lane('later')" :key="card.id">
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-white" x-text="card.title"></p>
                            <p class="truncate text-xs text-brand-100/55">
                                <span x-text="card.client"></span> ·
                                <span class="text-sky-200/80" x-text="'Back on your board ' + (card.next_on === 'Tomorrow' ? 'tomorrow' : card.next_on)"></span>
                                <template x-if="card.status === 'blocked'"><span class="text-violet-200/80"> · on hold</span></template>
                            </p>
                        </div>
                        <button type="button" @click="moveOn(card, 'today')" :disabled="!!busy[card.id + 'today']"
                                class="min-h-[40px] shrink-0 rounded-lg bg-white/5 px-3 text-xs font-semibold text-brand-100/80 ring-1 ring-white/10 hover:bg-white/10 disabled:opacity-40">← Today</button>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</x-app-layout>
