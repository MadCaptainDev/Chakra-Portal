{{--
    Reel Planner — Today. Its own widget, its own fetch() call to
    ContentDashboardController::todayReelBoard() (see routes/web.php) --
    not part of the data DashboardController::index() gathers, so a
    dashboard load never waits on it and it can be refreshed on its own.

    Shrinkable: a plain <details>, closed by default, that loads on first
    open rather than the moment the dashboard renders -- a tab nobody opens
    costs nothing.
--}}
<section
    x-data="{
        open: false,
        loading: false,
        loaded: false,
        error: false,
        data: { date: null, total_posting: 0, counts: {}, items: [] },
        load() {
            if (this.loaded || this.loading) return;
            this.loading = true;
            this.error = false;
            fetch('{{ route('content-dashboard.reel-today') }}', { headers: { Accept: 'application/json' } })
                .then((r) => { if (! r.ok) throw new Error('request failed'); return r.json(); })
                .then((json) => { this.data = json; this.loaded = true; })
                .catch(() => { this.error = true; })
                .finally(() => { this.loading = false; });
        },
        statusChip(status) {
            return {
                'To Be Edited': 'bg-amber-400/15 text-amber-200',
                'Edit in Progress': 'bg-amber-400/15 text-amber-200',
                'Under Review': 'bg-indigo-400/15 text-indigo-200',
                'Published': 'bg-emerald-400/15 text-emerald-200',
                'Scheduled': 'bg-sky-400/15 text-sky-200',
                'Video Ready': 'bg-teal-400/15 text-teal-200',
                'To Be Shooted': 'bg-purple-400/15 text-purple-200',
            }[status] ?? 'bg-white/10 text-brand-100/70';
        },
    }"
>
    <details @toggle="open = $event.target.open; if (open) load()">
        <summary class="list-none cursor-pointer flex flex-wrap items-center justify-between gap-3 mb-1">
            <div class="flex items-center gap-2">
                <x-icon name="chevron-right" class="w-4 h-4 text-brand-100/50 transition-transform" x-bind:class="{ 'rotate-90': open }" />
                <x-section-label dark>Reel Planner — Today</x-section-label>
            </div>
            <span class="text-xs text-brand-100/60" x-show="loaded" x-cloak>
                <span class="font-semibold text-white tabular-nums" x-text="data.total_posting"></span> due today
            </span>
        </summary>

        <div class="mt-3" x-show="open" x-cloak>
            <p class="text-sm text-brand-100/60" x-show="loading" x-cloak>Loading today's board…</p>
            <p class="text-sm text-red-300" x-show="error" x-cloak>Couldn't load today's board. Try reopening this tab.</p>

            <div x-show="loaded" x-cloak class="space-y-4">
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-100/60">Total Posting</p>
                        <p class="mt-1 text-xl font-bold text-white tabular-nums" x-text="data.total_posting"></p>
                    </div>
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-100/60">To Be Edited</p>
                        <p class="mt-1 text-xl font-bold text-amber-300 tabular-nums" x-text="data.counts.to_be_edited"></p>
                    </div>
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-100/60">Edit In Progress</p>
                        <p class="mt-1 text-xl font-bold text-amber-300 tabular-nums" x-text="data.counts.edit_in_progress"></p>
                    </div>
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-100/60">Under Review</p>
                        <p class="mt-1 text-xl font-bold text-indigo-300 tabular-nums" x-text="data.counts.under_review"></p>
                    </div>
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-100/60">Posted</p>
                        <p class="mt-1 text-xl font-bold text-emerald-300 tabular-nums" x-text="data.counts.posted"></p>
                    </div>
                </div>

                <template x-if="data.items.length === 0">
                    <p class="text-sm text-brand-100/50">Nothing due today.</p>
                </template>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 divide-y divide-white/10 overflow-hidden" x-show="data.items.length > 0">
                    <template x-for="item in data.items" :key="item.title + item.editor + item.status">
                        <div class="p-3 sm:p-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-white truncate" x-text="item.title"></p>
                                <p class="text-xs text-brand-100/60 mt-0.5">Editor: <span x-text="item.editor"></span></p>
                            </div>
                            <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
                                  :class="statusChip(item.status)" x-text="item.status"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </details>
</section>
