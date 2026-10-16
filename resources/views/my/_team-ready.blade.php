{{--
    The team's approved videos waiting to go out (EditingController::teamReady).
    Posting is anyone's job: whoever is free marks it posted or delivered.
--}}
@if ($teamReady)
    <section class="rounded-2xl border border-violet-400/30 bg-[#15303f] p-4 sm:p-5"
             x-data="{ busy: null, error: '', kind: @js(collect($teamReady)->mapWithKeys(fn ($t) => [$t['id'] => $t['kind']])),
                 async go(id, body) {
                     this.busy = id; this.error = '';
                     const r = await fetch(@js(route('my.editing.act', ['todo' => '__ID__'])).replace('__ID__', id), { method: 'POST', credentials: 'same-origin',
                         headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
                     const d = await r.json().catch(() => ({}));
                     if (r.ok) { document.getElementById('team-ready-' + id)?.remove(); } else { this.error = d.message || 'That did not work.'; }
                     this.busy = null;
                 } }">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-white">Ready to post · team <span class="ml-1 rounded-full bg-violet-400/20 px-2 text-xs text-violet-200">{{ count($teamReady) }}</span></h2>
            <p class="text-xs text-brand-100/55">Anyone can post these — mark it once it is out.</p>
        </div>
        <p x-show="error" x-cloak x-text="error" class="mt-2 rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200"></p>
        @foreach ($teamReady as $t)
            <div id="team-ready-{{ $t['id'] }}" class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl px-3.5 py-3 {{ $t['late'] ? 'bg-red-500/10 ring-1 ring-red-400/30' : 'bg-white/[0.04]' }}">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-white">{{ $t['title'] }}</p>
                    <p class="text-xs {{ $t['late'] ? 'text-red-200' : 'text-brand-100/60' }}">{{ $t['late'] ? 'Was due ' : '' }}{{ $t['publish_label'] }} · {{ $t['editor'] }}{{ $t['client'] ? ' · '.$t['client'] : '' }}</p>
                </div>
                <select x-model="kind[{{ $t['id'] }}]" class="h-10 rounded-lg border-0 bg-[#0f2230] py-0 pl-3 pr-8 text-[13px] text-white" aria-label="Posted as">
                    @foreach (\App\Models\Todo::POST_KINDS as $k)
                        <option value="{{ $k }}">{{ \App\Models\Todo::KINDS[$k] }}</option>
                    @endforeach
                </select>
                <button type="button" :disabled="busy === {{ $t['id'] }}" @click="go({{ $t['id'] }}, { action: 'post', kind: kind[{{ $t['id'] }}] })"
                        class="h-10 rounded-lg bg-emerald-500 px-3.5 text-[13px] font-bold text-white hover:bg-emerald-400 disabled:opacity-40">Posted</button>
                <button type="button" :disabled="busy === {{ $t['id'] }}" @click="go({{ $t['id'] }}, { action: 'deliver' })"
                        class="h-10 rounded-lg bg-teal-500/90 px-3.5 text-[13px] font-bold text-white hover:bg-teal-400 disabled:opacity-40">Delivered</button>
            </div>
        @endforeach
    </section>
@endif
