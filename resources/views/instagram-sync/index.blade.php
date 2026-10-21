{{--
    "Sync all Instagram" (InstagramSyncController): the button, and the
    queue it fills -- each account waiting, syncing, done, failed or
    needing the client to connect again. Re-reads every 3 seconds.
--}}
<x-app-layout title="Instagram sync" dark>
    <div class="mx-auto max-w-4xl space-y-6 pb-16"
         x-data="{
             s: @js($state),
             timer: null,
             init() { this.timer = setInterval(() => this.read(), 3000); },
             async read() {
                 if (document.hidden) return;
                 try { const r = await fetch(@js(route('instagram-sync.status')), { headers: { Accept: 'application/json' } }); if (r.ok) this.s = await r.json(); } catch (e) {}
             },
             n(k) { return this.s.counts[k] || 0; },
             get finished() { return this.n('done') + this.n('failed') + this.n('reconnect'); },
             get pct() { return this.s.counts.total ? Math.round(this.finished / this.s.counts.total * 100) : 0; },
             look(st) {
                 return { queued: ['Waiting', 'bg-white/10 text-brand-100/75'], running: ['Syncing…', 'bg-amber-400/15 text-amber-200'],
                          done: ['Done', 'bg-emerald-400/15 text-emerald-200'], failed: ['Failed', 'bg-red-500/15 text-red-200'],
                          reconnect: ['Reconnect needed', 'bg-violet-400/20 text-violet-200'] }[st] || [st, 'bg-white/10'];
             },
         }">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight">Instagram sync</h1>
                <p class="mt-1 text-sm text-brand-100/65">Pull the latest posts and numbers for every client. It runs in the background, one account at a time — you can leave this page.</p>
            </div>
            <form method="POST" action="{{ route('instagram-sync.store') }}">
                @csrf
                <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-brand-400 px-5 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-50" :disabled="s.running">
                    <span x-text="s.running ? 'Sync running…' : '↻ Sync all Instagram'"></span>
                </button>
            </form>
        </header>

        <template x-if="s.counts.total">
            <section class="rounded-2xl border border-white/[0.06] bg-[#15303f] p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="font-semibold text-white" x-text="s.running ? 'Syncing ' + finished + ' of ' + s.counts.total : 'Last run finished'"></p>
                    <p class="text-xs text-brand-100/55" x-text="'Started ' + s.started"></p>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-[#0f2230]">
                    <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-300 transition-all duration-700" :style="`width:${pct}%`"></div>
                </div>
                <p class="mt-2 text-[13px] text-brand-100/70">
                    <span class="text-emerald-300" x-text="n('done') + ' done'"></span> ·
                    <span x-text="n('queued') + n('running') + ' to go'"></span>
                    <span x-show="n('failed')" class="text-red-300" x-text="' · ' + n('failed') + ' failed'"></span>
                    <span x-show="n('reconnect')" class="text-violet-300" x-text="' · ' + n('reconnect') + ' need reconnecting'"></span>
                </p>
            </section>
        </template>

        <section class="overflow-hidden rounded-2xl border border-white/[0.06] bg-[#15303f]">
            <template x-for="j in s.jobs" :key="j.id">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-white/[0.05] px-5 py-3.5 last:border-b-0">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-white"><span x-text="j.client"></span> <span class="text-sm font-normal text-brand-100/50" x-text="j.handle || ''"></span></p>
                        <p class="text-xs text-brand-100/60" x-show="j.message" x-text="j.message"></p>
                    </div>
                    <span class="text-xs text-brand-100/45" x-show="j.took" x-text="j.took"></span>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="look(j.status)[1]">
                        <span x-show="j.status === 'running'" class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-300"></span>
                        <span x-text="look(j.status)[0]"></span>
                    </span>
                    <a x-show="j.status === 'reconnect' && j.client_id" :href="'{{ url('clients') }}/' + j.client_id + '#social'"
                       class="inline-flex h-8 items-center rounded-lg bg-violet-500/80 px-3 text-xs font-bold text-white hover:bg-violet-500">Reconnect</a>
                </div>
            </template>
            <p x-show="!s.jobs.length" class="px-5 py-10 text-center text-sm text-brand-100/55">No sync has been run from here yet. Press “Sync all Instagram”.</p>
        </section>

        <p class="text-xs text-brand-100/45">Connections are renewed automatically before they expire. “Reconnect needed” means it already expired: open the client and connect their Instagram again (the client has to log in).</p>
    </div>
</x-app-layout>
