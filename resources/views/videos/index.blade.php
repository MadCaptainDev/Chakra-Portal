{{--
    Videos -- every video with how far it has got and where it went.
    The page draws a skeleton at once; the rows arrive from videos.data.
    See VideosController.
--}}
<x-app-layout title="Videos">
    <div class="mx-auto max-w-[1320px] space-y-6 pb-16"
         x-data="videoList(@js(route('videos.data')))" x-init="load(true)">
        <header>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-300">Content</p>
            <h1 class="mt-1 text-[30px] font-extrabold tracking-tight text-white">Videos</h1>
            <p class="text-sm text-brand-100/70">Script, shoot, edit, post and numbers, together. Notion stays the source.</p>
        </header>

        <div class="flex flex-wrap items-center gap-3">
            <input type="search" x-model="q" @input.debounce.350ms="load(true)" placeholder="Search a title"
                   class="w-full max-w-xs rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-brand-100/40">
            <select x-model="flag" @change="load(true)"
                    class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                <option value="" class="text-black">All videos</option>
                <option value="mismatch" class="text-black">Planned reel, posted differently</option>
                <option value="unposted" class="text-black">Not posted yet</option>
            </select>
            <select x-model="posted" @change="load(true)"
                    class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                <option value="" class="text-black">Posted as: any</option>
                <option value="reel" class="text-black">Reel</option>
                <option value="post" class="text-black">Post</option>
                <option value="story" class="text-black">Story</option>
                <option value="delivered" class="text-black">Delivered</option>
            </select>
            <span class="text-xs text-brand-100/60" x-show="!loading" x-text="total + ' videos'"></span>
        </div>

        <div class="space-y-2" aria-live="polite">
            <template x-if="loading && rows.length === 0">
                <div class="space-y-2" aria-busy="true">
                    <template x-for="n in 8" :key="n">
                        <div class="flex animate-pulse items-center gap-4 rounded-xl border border-white/10 bg-white/5 p-4">
                            <div class="h-4 w-1/3 rounded bg-white/10"></div>
                            <div class="h-4 w-20 rounded bg-white/10"></div>
                            <div class="ml-auto h-4 w-40 rounded bg-white/10"></div>
                        </div>
                    </template>
                </div>
            </template>

            <template x-for="v in rows" :key="v.id">
                <a :href="v.url" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-white/10 bg-white/5 p-4 transition hover:bg-white/10">
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="truncate font-semibold text-white" x-text="v.title"></p>
                        <p class="truncate text-xs text-brand-100/60">
                            <span x-text="v.client || 'No client'"></span>
                            <template x-if="v.editor"><span> · <span x-text="v.editor"></span></span></template>
                            <template x-if="v.published"><span> · <span x-text="v.published"></span></span></template>
                        </p>
                    </div>
                    <div class="flex items-center gap-1" title="Script, shot, edited, posted">
                        <template x-for="(done, key) in v.steps" :key="key">
                            <span class="h-2.5 w-6 rounded-full" :class="done ? 'bg-emerald-400/80' : 'bg-white/10'"></span>
                        </template>
                    </div>
                    <div class="w-44 text-xs">
                        <span class="rounded bg-white/10 px-2 py-0.5 text-brand-100/80" x-text="'Planned ' + (v.planned || '?')"></span>
                        <template x-if="v.posted">
                            <span class="ml-1 rounded px-2 py-0.5"
                                  :class="v.mismatch ? 'bg-amber-400/20 text-amber-200' : 'bg-emerald-400/15 text-emerald-200'"
                                  x-text="'Posted ' + v.posted"></span>
                        </template>
                    </div>
                    <div class="w-20 text-right text-sm tabular-nums text-brand-100/80" x-text="v.views !== null ? v.views.toLocaleString('en-IN') + ' views' : ''"></div>
                </a>
            </template>

            <p x-show="!loading && rows.length === 0 && !failed" class="rounded-xl border border-white/10 bg-white/5 p-6 text-center text-sm text-brand-100/70">No videos match.</p>
            <p x-show="failed" class="rounded-xl border border-red-400/30 bg-red-400/10 p-4 text-sm text-red-200">
                Could not load videos. <button type="button" class="underline" @click="load(true)">Try again</button>
            </p>
            <div x-show="hasMore && !loading" class="pt-2 text-center">
                <button type="button" @click="load(false)" class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-white hover:bg-white/10">Load more</button>
            </div>
            <div x-show="loading && rows.length > 0" class="h-12 animate-pulse rounded-xl border border-white/10 bg-white/5"></div>
        </div>
    </div>

    <script>
        window.videoList = function (url) {
            return {
                q: '', flag: '', posted: '', rows: [], page: 0, lastPage: 1, total: 0,
                loading: true, failed: false, seq: 0,
                get hasMore() { return this.page < this.lastPage; },
                async load(reset) {
                    const mine = ++this.seq;
                    if (reset) { this.page = 0; this.rows = []; }
                    this.loading = true; this.failed = false;
                    const params = new URLSearchParams({ page: this.page + 1, q: this.q, flag: this.flag, posted: this.posted });
                    try {
                        const res = await fetch(url + '?' + params, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        const body = await res.json();
                        if (mine !== this.seq) return;
                        this.rows = reset ? body.rows : this.rows.concat(body.rows);
                        this.page = body.page; this.lastPage = body.last_page; this.total = body.total;
                    } catch (e) {
                        if (mine === this.seq) this.failed = true;
                    } finally {
                        if (mine === this.seq) this.loading = false;
                    }
                },
            };
        };
    </script>
</x-app-layout>
