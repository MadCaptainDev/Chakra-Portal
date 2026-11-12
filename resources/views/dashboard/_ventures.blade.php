{{--
    "My ventures": how many videos each venture this person chose has at
    every stage of the Notion planners, refreshed in place. Drawn by
    resources/js/venture-board.js from VentureBoardController::data.
--}}
@php
    $ventureBoard = \App\Support\VentureBoard::forUser(auth()->user());
    $toggle = 'relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition';
@endphp

<section id="ventures" class="scroll-mt-24"
         x-data="ventureBoard(@js([
             'data' => route('ventures.data'),
             'all' => route('ventures.all'),
             'choose' => route('ventures.choose'),
             'settings' => route('ventures.settings'),
             'refresh' => route('ventures.refresh'),
             'test' => route('ventures.test'),
             'planner' => Route::has('planner.index') ? route('planner.index') : url('/planner'),
         ]), @js($ventureBoard))">

    {{-- Heading, freshness, actions --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="mr-auto">
            <h2 class="text-[22px] font-extrabold tracking-tight text-white">My ventures</h2>
            <p class="mt-0.5 text-[13px] text-brand-100/60">
                <span x-text="board.totals.in_hand"></span> videos in hand (a reel and its YouTube copy count once) ·
                <span x-text="board.totals.stages.to_shoot"></span> to shoot ·
                <span x-text="board.totals.out_month"></span> out in <span x-text="board.month"></span>
                <span class="mx-1 text-brand-100/25">|</span>
                <span :class="refreshing ? 'text-brand-200' : ''">
                    <span x-show="!refreshing">Notion synced <span x-text="ago(board.last_synced_at)"></span></span>
                    <span x-show="refreshing" x-cloak>Refreshing from Notion…</span>
                </span>
            </p>
        </div>
        <button type="button" @click="refresh()" :disabled="refreshing"
                class="inline-flex min-h-[40px] items-center gap-2 rounded-xl bg-brand-400 px-4 text-sm font-bold text-brand-900 transition hover:bg-brand-300 disabled:opacity-60">
            <span class="inline-flex" :class="refreshing ? 'animate-spin' : ''"><x-icon name="refresh" class="h-4 w-4" /></span>
            <span x-text="refreshing ? 'Refreshing…' : 'Refresh'"></span>
        </button>
        <button type="button" @click="openPicker()"
                class="inline-flex min-h-[40px] items-center gap-2 rounded-xl border border-white/15 px-4 text-sm font-semibold text-white transition hover:bg-white/[0.08]">
            <x-icon name="template" class="h-4 w-4" /> Choose ventures
        </button>
        <button type="button" @click="openSettings()"
                class="inline-flex min-h-[40px] items-center gap-2 rounded-xl border border-white/15 px-4 text-sm font-semibold text-white transition hover:bg-white/[0.08]">
            <x-icon name="bell" class="h-4 w-4" /> Notifications
        </button>
    </div>

    <p x-show="!board.chosen" x-cloak class="mb-3 rounded-xl border border-brand-400/25 bg-brand-400/[0.07] px-4 py-2.5 text-[13px] text-brand-100/80">
        Showing the busiest ventures for now. <button type="button" @click="openPicker()" class="font-semibold text-brand-200 underline underline-offset-4 hover:text-white">Choose yours</button> to keep this board and its notifications to the ones you care about.
    </p>

    {{-- Totals across the board, stage by stage --}}
    <div class="mb-3 grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-9">
        <div class="rounded-xl bg-gradient-to-br from-brand-400/25 to-brand-400/5 px-3 py-2.5 ring-1 ring-brand-400/30">
            <p class="text-2xl font-extrabold tabular-nums text-white" x-text="board.totals.in_hand"></p>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-100/70">In hand</p>
        </div>
        <template x-for="s in board.stages" :key="s.key">
            <div class="rounded-xl bg-[#15303f] px-3 py-2.5 ring-1 ring-white/[0.06]">
                <p class="text-2xl font-extrabold tabular-nums text-white" x-text="board.totals.stages[s.key]"></p>
                <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-100/55">
                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${s.color}`"></span><span x-text="s.label"></span>
                </p>
            </div>
        </template>
        <div class="rounded-xl bg-[#15303f] px-3 py-2.5 ring-1 ring-white/[0.06]">
            <p class="text-2xl font-extrabold tabular-nums text-emerald-300" x-text="board.totals.out_month"></p>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-100/55">Out this month</p>
        </div>
        <div x-show="board.totals.missing_youtube > 0" class="rounded-xl bg-amber-400/[0.08] px-3 py-2.5 ring-1 ring-amber-400/25" title="Published or scheduled as a reel, with no YouTube copy in the planner">
            <p class="text-2xl font-extrabold tabular-nums text-amber-200" x-text="board.totals.missing_youtube"></p>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-200/70">Not on YouTube</p>
        </div>
    </div>

    {{-- One card per venture. A video counts once, however many planners it is in (reel + YouTube). --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 transition-opacity" :class="busy ? 'opacity-60' : ''">
        <template x-for="(row, n) in board.rows" :key="row.venture">
            <article class="vb-card group flex flex-col rounded-2xl border bg-[#15303f] p-4 transition duration-300 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-black/20"
                     :style="`animation-delay:${n * 60}ms`"
                     :class="{ ok: 'border-white/[0.06] hover:border-brand-400/40', low: 'border-amber-400/35', empty: 'border-red-400/40' }[row.health]">
                <div class="flex items-center gap-3">
                    <template x-if="row.logo_url"><img :src="row.logo_url" alt="" class="h-11 w-11 shrink-0 rounded-xl bg-white object-contain p-0.5"></template>
                    <template x-if="!row.logo_url"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-sm font-bold text-white/70" x-text="initials(row.venture)"></span></template>
                    <div class="min-w-0 flex-1">
                        <a :href="plannerUrl(row.venture)" class="block truncate text-[15px] font-semibold text-white hover:text-brand-200" x-text="row.venture" title="Open in planner"></a>
                        <p class="text-xs text-brand-100/55">
                            <span x-show="row.planned_until">Planned until <span class="font-semibold text-brand-100/80" x-text="day(row.planned_until)"></span></span>
                            <span x-show="!row.planned_until">Nothing dated ahead</span>
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-3xl font-extrabold leading-none tabular-nums"
                           :class="{ ok: 'text-white', low: 'text-amber-300', empty: 'text-red-300' }[row.health]" x-text="row.in_hand"></p>
                        <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-brand-100/50" x-text="row.in_hand === 1 ? 'video in hand' : 'videos in hand'"></p>
                    </div>
                </div>

                {{-- The pipeline as one bar --}}
                <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-white/[0.06]">
                    <template x-for="s in board.stages" :key="s.key">
                        <span class="h-full transition-all duration-700 ease-out" :style="`width:${share(row, s.key)}%;background:${s.color}`"></span>
                    </template>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                    <template x-for="s in board.stages.filter((s) => row.stages[s.key])" :key="s.key">
                        <span class="inline-flex items-center gap-1 rounded-full bg-white/[0.05] px-2 py-0.5 text-brand-100/80">
                            <span class="h-1.5 w-1.5 rounded-full" :style="`background:${s.color}`"></span>
                            <span class="font-bold tabular-nums text-white" x-text="row.stages[s.key]"></span><span x-text="s.label"></span>
                        </span>
                    </template>
                </div>

                {{-- This month: what went out, and where --}}
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl bg-emerald-400/[0.06] px-3 py-2 text-xs ring-1 ring-emerald-400/15">
                    <span class="text-emerald-200">
                        <span class="text-base font-extrabold tabular-nums" x-text="row.out_month"></span><span x-show="row.target" class="text-emerald-200/70" x-text="' / ' + row.target"></span>
                        <span x-text="row.out_month === 1 ? 'video out' : 'videos out'"></span> in <span x-text="board.month.split(' ')[0]"></span>
                    </span>
                    <span class="ml-auto flex gap-1.5">
                        <template x-for="p in platforms" :key="p.key">
                            <span x-show="row.out_by_type[p.key]" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold" :class="p.chip">
                                <span x-text="p.icon"></span><span class="tabular-nums" x-text="row.out_by_type[p.key]"></span>
                            </span>
                        </template>
                    </span>
                    <div x-show="row.target" class="h-1 w-full overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full bg-emerald-400 transition-all duration-700 ease-out" :style="`width:${Math.min(100, row.target ? row.out_month / row.target * 100 : 0)}%`"></div>
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <span x-show="row.overdue > 0" class="text-red-300">⚠ <span x-text="row.overdue"></span> past their date</span>
                    <span x-show="row.missing_youtube > 0" class="text-amber-300" title="Published or scheduled as a reel, with no YouTube copy in the planner">▶ <span x-text="row.missing_youtube"></span> not on YouTube</span>
                    <span x-show="row.health === 'low'" class="text-amber-300">Running low</span>
                    <span x-show="row.health === 'empty'" class="text-red-300">Nothing in hand or to shoot</span>
                </div>

                {{-- The videos themselves --}}
                <button type="button" x-show="row.videos.length" @click="toggleOpen(row.venture)"
                        class="mt-3 flex items-center gap-2 border-t border-white/[0.05] pt-2.5 text-left text-xs font-semibold text-brand-200 hover:text-white">
                    <span x-text="isOpen(row.venture) ? 'Hide videos' : 'Show the ' + row.videos.length + ' videos'"></span>
                    <span class="transition-transform duration-300" :class="isOpen(row.venture) ? 'rotate-180' : ''">▾</span>
                </button>
                <div class="grid transition-[grid-template-rows] duration-300 ease-out" :class="isOpen(row.venture) ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                    <ul class="min-h-0 space-y-1 overflow-hidden">
                        <template x-for="(v, i) in row.videos" :key="v.title + v.date">
                            <li class="vb-video mt-1 flex items-start gap-2.5 rounded-lg px-2 py-1.5 transition hover:bg-white/[0.04]"
                                :class="isOpen(row.venture) ? 'vb-in' : ''" :style="`animation-delay:${i * 35}ms`">
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-full" :style="`background:${stageColor(v.stage)}`" :title="stageLabel(v.stage)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] text-white/90" :class="v.out ? 'text-white/70' : ''" x-text="v.title" :title="v.title"></p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-1 text-[10.5px]">
                                        <span class="text-brand-100/50" x-text="(v.out ? 'Out ' : '') + (v.date ? day(v.date) : 'No date') + (v.out ? '' : ' · ' + stageLabel(v.stage))"></span>
                                        <template x-for="p in v.platforms" :key="p.source">
                                            <span class="rounded px-1 py-px font-semibold"
                                                  :class="p.out ? 'bg-emerald-400/15 text-emerald-200' : (p.scheduled ? 'bg-violet-400/15 text-violet-200' : 'bg-white/[0.06] text-brand-100/55')"
                                                  :title="p.label + ': ' + p.status" x-text="(p.out ? '✓ ' : (p.scheduled ? '◷ ' : '')) + p.label"></span>
                                        </template>
                                        <span x-show="v.missing_youtube" class="rounded bg-amber-400/15 px-1 py-px font-semibold text-amber-200">No YouTube</span>
                                    </p>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </article>
        </template>
    </div>
    <p x-show="board.rows.length === 0" x-cloak class="rounded-2xl border border-white/[0.06] bg-[#15303f] px-5 py-8 text-center text-sm text-brand-100/60">
        No ventures yet. <button type="button" @click="openPicker()" class="font-semibold text-brand-200 underline underline-offset-4">Choose some</button>.
    </p>

    <p x-show="notice" x-cloak x-transition.opacity class="mt-3 text-sm text-brand-200" role="status" x-text="notice"></p>

    {{-- ——— Choose ventures ——— --}}
    <template x-teleport="body">
        <div x-show="picker" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Choose ventures" @keydown.escape.window="picker = false">
            <div class="absolute inset-0 bg-black/55" x-show="picker" x-transition.opacity @click="picker = false"></div>
            <div x-show="picker" x-transition.duration.150ms
                 class="theme-dark relative flex max-h-[88vh] w-full flex-col overflow-hidden rounded-t-2xl bg-[#15303f] text-white ring-1 ring-white/10 shadow-2xl sm:max-w-2xl sm:rounded-2xl">
                <div class="border-b border-white/[0.06] px-5 pb-3 pt-4">
                    <div class="flex items-center gap-3">
                        <h3 class="mr-auto text-lg font-bold">Choose ventures</h3>
                        <button type="button" @click="picker = false" class="flex h-9 w-9 items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">✕</button>
                    </div>
                    <p class="mt-0.5 text-xs text-brand-100/55">Tick the ones to watch. Your board, summary and alerts cover only these. Use the arrows to set the order.</p>
                    <input type="search" x-ref="ventureSearch" x-model="search" placeholder="Search ventures"
                           class="mt-3 h-10 w-full rounded-xl border-0 bg-[#0f2230] px-3.5 text-sm text-white ring-1 ring-white/10 placeholder:text-brand-100/35 focus:ring-brand-400">
                </div>

                <div class="grid min-h-0 flex-1 gap-0 overflow-hidden sm:grid-cols-[1fr_220px]">
                    <ul class="min-h-0 overflow-y-auto p-2">
                        <template x-for="v in filtered" :key="v.name">
                            <li>
                                <button type="button" @click="toggle(v.name)" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition hover:bg-white/[0.05]"
                                        :class="isChosen(v.name) ? 'bg-brand-400/10' : ''">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded border-2 transition"
                                          :class="isChosen(v.name) ? 'border-brand-400 bg-brand-400 text-brand-900' : 'border-white/25'">
                                        <svg x-show="isChosen(v.name)" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                    <template x-if="v.logo_url"><img :src="v.logo_url" alt="" class="h-7 w-7 shrink-0 rounded-lg bg-white object-contain p-0.5"></template>
                                    <template x-if="!v.logo_url"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/10 text-[10px] font-bold text-white/60" x-text="initials(v.name)"></span></template>
                                    <span class="min-w-0 flex-1 truncate text-sm" x-text="v.name"></span>
                                    <span class="shrink-0 text-xs text-brand-100/45"><span x-text="v.active"></span> open · <span x-text="v.total"></span></span>
                                </button>
                            </li>
                        </template>
                        <li x-show="all.length === 0" class="px-3 py-8 text-center text-sm text-brand-100/45">Loading…</li>
                    </ul>
                    <div class="hidden min-h-0 overflow-y-auto border-l border-white/[0.06] bg-[#0f2230]/60 p-3 sm:block">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">Order (<span x-text="chosen.length"></span>)</p>
                        <template x-for="(name, i) in chosen" :key="name">
                            <div class="mb-1 flex items-center gap-1 rounded-lg bg-white/[0.05] py-1 pl-2.5 pr-1 text-[13px]">
                                <span class="min-w-0 flex-1 truncate" x-text="name"></span>
                                <button type="button" @click="move(name, -1)" :disabled="i === 0" class="h-6 w-6 rounded text-brand-100/50 hover:bg-white/10 hover:text-white disabled:opacity-20" aria-label="Move up">↑</button>
                                <button type="button" @click="move(name, 1)" :disabled="i === chosen.length - 1" class="h-6 w-6 rounded text-brand-100/50 hover:bg-white/10 hover:text-white disabled:opacity-20" aria-label="Move down">↓</button>
                            </div>
                        </template>
                        <p x-show="chosen.length === 0" class="text-xs text-brand-100/40">Nothing chosen: the board shows the busiest six.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 border-t border-white/[0.06] px-5 py-3">
                    <button type="button" @click="chosen = []" class="text-sm text-brand-100/55 hover:text-white">Clear</button>
                    <button type="button" @click="picker = false" class="ml-auto text-sm text-brand-100/60 hover:text-white">Cancel</button>
                    <button type="button" @click="saveChoice()" :disabled="saving"
                            class="inline-flex h-10 items-center rounded-xl bg-brand-400 px-5 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-60"
                            x-text="saving ? 'Saving…' : 'Save'"></button>
                </div>
            </div>
        </div>
    </template>

    {{-- ——— Notifications ——— --}}
    <template x-teleport="body">
        <div x-show="settingsOpen" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Venture notifications" @keydown.escape.window="settingsOpen = false">
            <div class="absolute inset-0 bg-black/55" x-show="settingsOpen" x-transition.opacity @click="settingsOpen = false"></div>
            <div x-show="settingsOpen" x-transition.duration.150ms
                 class="theme-dark relative w-full overflow-y-auto rounded-t-2xl bg-[#15303f] p-5 text-white ring-1 ring-white/10 shadow-2xl sm:max-w-lg sm:rounded-2xl" style="max-height: 90vh">
                <div class="flex items-center gap-3">
                    <h3 class="mr-auto text-lg font-bold">Notify me about my ventures</h3>
                    <button type="button" @click="settingsOpen = false" class="flex h-9 w-9 items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">✕</button>
                </div>
                <p class="mt-1 text-xs text-brand-100/55">Sent to your phone (the Chakra app), the bell at the top of the portal and, when allowed below, WhatsApp. Checked every five minutes.</p>

                <template x-if="settings">
                    <div class="mt-4 space-y-1">
                        @foreach ([
                            ['daily_summary', 'Morning summary', 'Every venture\'s count, once a day.'],
                            ['notify_low', 'Running low', 'When a venture has fewer videos in hand than the number below.'],
                            ['notify_ready', 'Video ready', 'When a video is marked Video Ready.'],
                            ['notify_published', 'Video out', 'When a video is Published or Delivered.'],
                            ['notify_shot', 'Video shot', 'When a video moves to To Be Edited.'],
                            ['whatsapp', 'Also on WhatsApp', 'Only arrives if you messaged the portal number in the last 24 hours (Meta\'s rule).'],
                        ] as [$key, $label, $hint])
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-white/[0.04]">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ $label }}</span>
                                    <span class="block text-xs text-brand-100/50">{{ $hint }}</span>
                                </span>
                                <input type="checkbox" class="peer sr-only" x-model="settings.{{ $key }}">
                                <span class="{{ $toggle }}" :class="settings.{{ $key }} ? 'bg-brand-400' : 'bg-white/15'">
                                    <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="settings.{{ $key }} ? 'translate-x-[22px]' : 'translate-x-0.5'"></span>
                                </span>
                            </label>
                        @endforeach

                        <div class="grid grid-cols-2 gap-3 px-3 pt-2">
                            <label class="block">
                                <span class="text-xs text-brand-100/60">Summary time</span>
                                <input type="time" x-model="settings.summary_at" :disabled="!settings.daily_summary"
                                       class="mt-1 h-10 w-full rounded-xl border-0 bg-[#0f2230] px-3 text-sm text-white ring-1 ring-white/10 [color-scheme:dark] disabled:opacity-50">
                            </label>
                            <label class="block">
                                <span class="text-xs text-brand-100/60">Running low below</span>
                                <span class="mt-1 flex items-center gap-2">
                                    <input type="number" min="1" max="50" x-model="settings.low_stock"
                                           class="h-10 w-20 rounded-xl border-0 bg-[#0f2230] px-3 text-sm text-white ring-1 ring-white/10">
                                    <span class="text-xs text-brand-100/50">videos in hand</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </template>
                <p x-show="!settings" class="mt-6 text-center text-sm text-brand-100/50">Loading…</p>

                <div class="mt-5 rounded-xl bg-[#0f2230] p-3 text-xs text-brand-100/65">
                    <p x-show="canNotify === 'granted'">Desktop notifications are on: new ones pop up while the portal is open.</p>
                    <p x-show="canNotify === 'default'">
                        Want a pop-up on this computer too?
                        <button type="button" @click="allowDesktop()" class="font-semibold text-brand-200 underline underline-offset-4 hover:text-white">Allow desktop notifications</button>
                    </p>
                    <p x-show="canNotify === 'denied'">This browser is blocking notifications from the portal. Allow them in the browser's site settings to get pop-ups.</p>
                    <button type="button" @click="sendTest()" class="mt-2 font-semibold text-brand-200 underline underline-offset-4 hover:text-white">Send me a test notification</button>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" @click="settingsOpen = false" class="text-sm text-brand-100/60 hover:text-white">Cancel</button>
                    <button type="button" @click="saveSettings()" :disabled="saving || !settings"
                            class="inline-flex h-10 items-center rounded-xl bg-brand-400 px-5 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-60"
                            x-text="saving ? 'Saving…' : 'Save'"></button>
                </div>
            </div>
        </div>
    </template>
</section>
