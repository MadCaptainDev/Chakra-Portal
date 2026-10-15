{{--
    One video, start to finish. Skeleton first, data from videos.show-data.
    See VideosController.
--}}
<x-app-layout title="Video">
    <div class="mx-auto max-w-[1100px] space-y-6 pb-16"
         x-data="videoDetail(@js(route('videos.show-data', $video)))" x-init="load()">
        <a href="{{ route('videos.index') }}" class="text-sm text-brand-300 hover:underline">← All videos</a>

        <template x-if="loading">
            <div class="space-y-5 animate-pulse" aria-busy="true">
                <div class="h-8 w-2/3 rounded bg-white/10"></div>
                <div class="h-4 w-1/3 rounded bg-white/10"></div>
                <div class="grid gap-3 sm:grid-cols-4">
                    <template x-for="n in 4" :key="n"><div class="h-24 rounded-xl bg-white/5"></div></template>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <template x-for="n in 3" :key="n"><div class="h-20 rounded-xl bg-white/5"></div></template>
                </div>
            </div>
        </template>

        <p x-show="failed" class="rounded-xl border border-red-400/30 bg-red-400/10 p-4 text-sm text-red-200">
            Could not load this video. <button type="button" class="underline" @click="load()">Try again</button>
        </p>

        <template x-if="d">
            <div class="space-y-6">
                <header>
                    <h1 class="text-[28px] font-extrabold tracking-tight text-white" x-text="d.title"></h1>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-brand-100/70">
                        <span x-text="d.client || 'No client'"></span>
                        <span class="rounded bg-white/10 px-2 py-0.5 text-xs" x-text="'Planned ' + (d.planned || '?')"></span>
                        <template x-if="d.posted">
                            <span class="rounded px-2 py-0.5 text-xs" :class="d.mismatch ? 'bg-amber-400/20 text-amber-200' : 'bg-emerald-400/15 text-emerald-200'"
                                  x-text="'Posted ' + d.posted"></span>
                        </template>
                        <template x-if="d.mismatch"><span class="text-xs text-amber-200">Planned as a reel, went out differently</span></template>
                    </p>
                </header>

                <ol class="grid gap-3 sm:grid-cols-4">
                    <template x-for="s in d.steps" :key="s.key">
                        <li class="rounded-xl border p-4" :class="s.done ? 'border-emerald-400/30 bg-emerald-400/5' : 'border-white/10 bg-white/5'">
                            <p class="text-xs font-semibold uppercase tracking-wider" :class="s.done ? 'text-emerald-300' : 'text-brand-100/50'" x-text="s.label"></p>
                            <p class="mt-1 text-sm text-white" x-text="s.detail"></p>
                            <p class="mt-1 text-xs text-brand-100/60" x-text="s.date || ''"></p>
                            <a x-show="!!s.url" :href="s.url || '#'" target="_blank" rel="noopener" class="mt-2 inline-block text-xs text-brand-300 hover:underline">Open</a>
                        </li>
                    </template>
                </ol>

                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4 sm:col-span-2">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/50">Reel on Instagram</p>
                        <template x-if="d.metrics">
                            <dl class="mt-3 grid grid-cols-3 gap-3 text-center">
                                <template x-for="(val, name) in d.metrics" :key="name">
                                    <div>
                                        <dd class="text-xl font-bold tabular-nums text-white" x-text="Number(val).toLocaleString('en-IN')"></dd>
                                        <dt class="text-xs capitalize text-brand-100/60" x-text="name"></dt>
                                    </div>
                                </template>
                            </dl>
                        </template>
                        <p x-show="!d.metrics" class="mt-2 text-sm text-brand-100/70"
                           x-text="d.has_media ? 'Linked, numbers not synced yet.' : 'Not matched to an Instagram post.'"></p>
                    </div>
                    <div class="rounded-xl border border-dashed border-white/15 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/50">Short on YouTube</p>
                        <p class="mt-2 text-sm text-brand-100/70">Not connected yet. Shorts are reels uploaded again, so this will sit beside the reel numbers.</p>
                    </div>
                </section>

                <div class="flex flex-wrap gap-3" x-show="d.links.length > 0">
                    <template x-for="l in d.links" :key="l.url">
                        <a :href="l.url" target="_blank" rel="noopener" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white hover:bg-white/10" x-text="l.label"></a>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <script>
        window.videoDetail = function (url) {
            return {
                d: null, loading: true, failed: false,
                async load() {
                    this.loading = true; this.failed = false;
                    try {
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        this.d = await res.json();
                    } catch (e) {
                        this.failed = true;
                    } finally {
                        this.loading = false;
                    }
                },
            };
        };
    </script>
</x-app-layout>
