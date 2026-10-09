{{--
    My Editing: the editor's videos as cards, To do → Working → Done →
    Posted, corrections as branches. Driven by resources/js/editing-day.js
    against My\EditingController; see the controller for what each list is.
--}}
@php
    $state = [
        'cards' => $cards,
        'suggestions' => $suggestions,
        'hints' => $hints,
        'kinds' => $kinds,
        'urls' => [
            'store' => route('my.editing.store'),
            'act' => route('my.editing.act', '__ID__'),
            'correct' => route('my.editing.correct', '__ID__'),
            'refresh' => route('my.editing.refresh'),
        ],
    ];
    $lanes = [
        'todo' => ['To do', 'bg-white/10 text-brand-100/80'],
        'working' => ['Working', 'bg-amber-400/15 text-amber-200'],
        'done' => ['Done — where does it go?', 'bg-sky-400/15 text-sky-200'],
        'posted' => ['Posted', 'bg-emerald-400/15 text-emerald-200'],
    ];
@endphp

<x-app-layout title="My Editing">
    <div class="mx-auto max-w-6xl space-y-4" x-data="editingDay(@js($state))">

        {{-- Header, with the Notion refresh kept small on purpose. --}}
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{{ now()->format('l j F') }}</p>
                <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">My Editing</h1>
            </div>
            <button type="button" @click="refreshNotion()" :disabled="refreshing"
                    class="rounded-lg p-2 text-brand-100/40 hover:bg-white/5 hover:text-brand-100/80" title="Refresh from Notion" aria-label="Refresh the Reel Planner from Notion">
                <svg class="h-4 w-4" :class="refreshing && 'animate-spin'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            </button>
        </div>

        {{-- Messages --}}
        <div x-show="error" x-cloak class="rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200" role="alert" x-text="error"></div>
        <div x-show="flash" x-cloak x-transition.opacity class="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-full bg-emerald-500 px-4 py-2 text-sm font-semibold text-white shadow-xl" role="status" x-text="flash"></div>

        {{-- Quick add --}}
        <div class="rounded-2xl bg-white/5 p-3 ring-1 ring-white/10 sm:p-4">
            <form @submit.prevent="add()" class="flex gap-2">
                <input x-ref="add" x-model="title" list="editing-titles" autocomplete="off" autofocus
                       placeholder="Add a video — e.g. SVA - Sharara Kurti Set"
                       class="min-h-[48px] flex-1 rounded-xl border-0 bg-white/10 px-4 text-base text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-brand-400">
                <button type="submit" :disabled="busy.add || !title.trim()"
                        class="shrink-0 rounded-xl bg-brand-400 px-5 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-40">Add</button>
            </form>
            <datalist id="editing-titles">
                <template x-for="t in hints.titles" :key="t"><option :value="t"></option></template>
            </datalist>
            <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                <template x-for="c in hints.clients" :key="c">
                    <button type="button" @click="prefill(c)" class="rounded-full bg-white/5 px-3 py-1 text-xs font-semibold text-brand-100/80 ring-1 ring-white/10 hover:bg-white/10" x-text="c + ' –'"></button>
                </template>
                <span class="ml-auto text-xs text-brand-100/50">
                    <span x-text="cards.length"></span> on your board
                    <template x-if="hints.per_day"><span> · you usually finish about <span x-text="hints.per_day"></span> a day</span></template>
                </span>
            </div>
        </div>

        {{-- Up next from the Reel Planner --}}
        <div class="rounded-2xl bg-white/5 ring-1 ring-white/10" x-show="suggestions.length > 0" x-cloak>
            <button type="button" @click="showPlanner = !showPlanner" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                <span>
                    <span class="text-sm font-semibold text-white">Up next from the Reel Planner</span>
                    <span class="ml-1 text-xs text-brand-100/50" x-text="suggestions.length + ' to edit'"></span>
                </span>
                <span class="text-brand-100/50" x-text="showPlanner ? '▴' : '▾'"></span>
            </button>
            <ul x-show="showPlanner" class="max-h-80 divide-y divide-white/5 overflow-y-auto border-t border-white/10">
                <template x-for="s in suggestions" :key="s.id">
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-white" x-text="s.title"></p>
                            <p class="truncate text-xs text-brand-100/55">
                                <span x-text="s.client"></span> ·
                                <span :class="s.late ? 'font-semibold text-red-300' : ''" x-text="'Publishes ' + s.publishes"></span> ·
                                <span x-text="s.status"></span>
                            </p>
                        </div>
                        <button type="button" @click="addFromPlanner(s)" :disabled="busy['s' + s.id]"
                                class="shrink-0 rounded-lg bg-brand-400/15 px-3 py-1.5 text-xs font-bold text-brand-200 hover:bg-brand-400/25 disabled:opacity-40">+ Add</button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- The board --}}
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($lanes as $lane => [$label, $chip])
                <section class="rounded-2xl bg-white/[0.03] p-2 ring-1 ring-white/10">
                    <h2 class="flex items-center justify-between px-2 pb-2 pt-1 text-xs font-semibold uppercase tracking-[0.14em] text-brand-100/60">
                        {{ $label }}
                        <span class="rounded-full px-2 py-0.5 text-[11px] normal-case tracking-normal {{ $chip }}" x-text="lane('{{ $lane }}').length"></span>
                    </h2>
                    <div class="space-y-2">
                        <template x-for="card in lane('{{ $lane }}')" :key="card.id">
                            <article class="rounded-xl bg-brand-900/80 p-3 ring-1 ring-white/10">
                                <p class="font-semibold leading-snug text-white" x-text="card.title"></p>
                                <p class="mt-0.5 text-xs text-brand-100/55">
                                    <span x-text="card.client"></span>
                                    <template x-if="card.publishes"><span> · publishes <span x-text="card.publishes"></span></span></template>
                                    <template x-if="card.working_minutes > 0 || card.status === 'started'"><span> · <span x-text="timer(card)"></span> worked</span></template>
                                </p>

                                <template x-if="card.sent_back">
                                    <p class="mt-2 rounded-lg bg-red-500/10 px-2 py-1.5 text-xs text-red-200">Sent back: <span x-text="card.sent_back"></span></p>
                                </template>

                                {{-- Corrections: branches on this video. --}}
                                <template x-if="card.corrections.length > 0">
                                    <ul class="mt-2 space-y-1 border-l-2 border-amber-400/40 pl-2.5">
                                        <template x-for="fix in card.corrections" :key="fix.id">
                                            <li>
                                                <label class="flex cursor-pointer items-start gap-2 text-xs">
                                                    <input type="checkbox" :checked="fix.status === 'completed'" @change="toggleFix(card, fix)"
                                                           class="mt-0.5 rounded border-white/20 bg-white/10 text-emerald-500 focus:ring-emerald-400">
                                                    <span :class="fix.status === 'completed' ? 'text-brand-100/40 line-through' : 'text-amber-100'" x-text="fix.title"></span>
                                                </label>
                                            </li>
                                        </template>
                                    </ul>
                                </template>

                                <template x-if="fixing === card.id">
                                    <form @submit.prevent="addFix(card)" class="mt-2 flex gap-1.5">
                                        <input x-model="fixText" x-init="$el.focus()" placeholder="What needs fixing?" maxlength="255"
                                               class="min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-2.5 py-1.5 text-xs text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-amber-400">
                                        <button class="rounded-lg bg-amber-400 px-2.5 text-xs font-bold text-brand-900">Add</button>
                                        <button type="button" @click="fixing = null" class="px-1 text-xs text-brand-100/50">✕</button>
                                    </form>
                                </template>

                                {{-- What you can do next, by stage. --}}
                                <div class="mt-2.5 flex flex-wrap gap-1.5">
                                    <template x-if="['waiting', 'blocked'].includes(card.status)">
                                        <button type="button" @click="act(card, 'start')" class="rounded-lg bg-amber-400 px-3 py-1.5 text-xs font-bold text-brand-900 hover:bg-amber-300">▶ Start working</button>
                                    </template>
                                    <template x-if="card.status === 'started'">
                                        <button type="button" @click="act(card, 'done')" :disabled="openFixes(card) > 0"
                                                :title="openFixes(card) > 0 ? 'Finish the corrections first' : ''"
                                                class="rounded-lg bg-sky-400 px-3 py-1.5 text-xs font-bold text-brand-900 hover:bg-sky-300 disabled:opacity-40">✓ Done</button>
                                    </template>
                                    <template x-if="card.status !== 'completed' || !card.posted_at">
                                        <button type="button" @click="fixing = card.id; fixText = ''" class="rounded-lg bg-white/5 px-3 py-1.5 text-xs font-semibold text-amber-200 ring-1 ring-white/10 hover:bg-white/10">+ Correction</button>
                                    </template>
                                </div>

                                {{-- Done: where it goes, then posted. --}}
                                <template x-if="card.status === 'completed' && !card.posted_at && card.kind !== 'none'">
                                    <div class="mt-2.5 space-y-2 rounded-lg bg-white/5 p-2">
                                        <div class="grid grid-cols-4 gap-1">
                                            <template x-for="[key, label] in Object.entries(kinds)" :key="key">
                                                <button type="button" @click="act(card, 'kind', { kind: key })"
                                                        :class="card.kind === key ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-100/70 hover:bg-white/10'"
                                                        class="rounded-md px-1 py-1.5 text-[11px] font-semibold" x-text="key === 'none' ? 'Not posting' : label"></button>
                                            </template>
                                        </div>
                                        <template x-if="card.kind && card.kind !== 'none'">
                                            <form @submit.prevent="act(card, 'post', { kind: card.kind, post_url: links[card.id] || null })" class="flex gap-1.5">
                                                <input x-model="links[card.id]" type="url" placeholder="Link (optional)"
                                                       class="min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-2.5 py-1.5 text-xs text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-emerald-400">
                                                <button class="shrink-0 rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-400">Mark posted</button>
                                            </form>
                                        </template>
                                        <button type="button" @click="act(card, 'reopen')" class="text-[11px] text-brand-100/50 underline underline-offset-2 hover:text-white">Back to working</button>
                                    </div>
                                </template>

                                <template x-if="card.posted_at">
                                    <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                                        <span class="font-semibold text-emerald-300">✓ <span x-text="kinds[card.kind] ?? 'Posted'"></span> posted <span x-text="card.posted_at"></span></span>
                                        <span class="flex gap-2">
                                            <template x-if="card.post_url"><a :href="card.post_url" target="_blank" rel="noopener" class="text-sky-300 hover:underline">Open</a></template>
                                            <button type="button" @click="act(card, 'unpost')" class="text-brand-100/40 hover:text-white">Undo</button>
                                        </span>
                                    </div>
                                </template>
                            </article>
                        </template>
                        <p x-show="lane('{{ $lane }}').length === 0" class="px-2 py-4 text-center text-xs text-brand-100/35">Nothing here</p>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
