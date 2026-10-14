{{--
    What is waiting on a decision now (not on the day shown): videos in
    review -- a correction, Video ready with its publishing time, or
    Delivered -- and Video ready ones waiting to be posted, late ones first.
    Every button calls My Editing's own endpoints (EditingController).
--}}
@php
    $kinds = \App\Models\Todo::POST_KINDS;
    $labels = \App\Models\Todo::KINDS;
@endphp
@if ($review->isNotEmpty() || $ready->isNotEmpty())
    <section class="grid gap-3 lg:grid-cols-2"
             x-data="{
                 busy: null, error: '', fixFor: null, fixText: '', kind: {}, when: {},
                 async send(url, body, id) {
                     this.busy = id; this.error = '';
                     try {
                         const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
                         const d = await r.json().catch(() => ({}));
                         if (!r.ok) throw new Error((d.errors && Object.values(d.errors).flat()[0]) || d.message || 'That did not work.');
                         window.location.reload();
                     } catch (e) { this.error = e.message; this.busy = null; }
                 },
             }">
        <p x-show="error" x-cloak class="rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200 lg:col-span-2" x-text="error"></p>

        {{-- Waiting for review --}}
        <div id="review-queue" class="scroll-mt-24 rounded-2xl bg-sky-400/[0.05] ring-1 ring-sky-400/25">
            <div class="border-b border-sky-400/15 px-4 py-3">
                <p class="font-semibold text-white">Waiting for review <span class="ml-1 rounded-full bg-sky-400/20 px-2 py-0.5 text-xs text-sky-200">{{ $review->count() }}</span></p>
                <p class="text-xs text-brand-100/55">A change? Send a correction back. All good? Video ready with its publishing time — or Delivered.</p>
            </div>
            <ul class="divide-y divide-white/5">
                @forelse ($review as $t)
                    @php
                        $openFixes = $t->corrections->filter->isOpen()->count();
                        $pub = $t->contentItem?->published_date;
                        $default = ($pub && $pub->gte(today()) ? $pub->copy()->setTime(18, 0) : now()->addHour()->startOfHour())->format('Y-m-d\TH:i');
                    @endphp
                    <li class="px-4 py-3" x-init="when[{{ $t->id }}] = @js($default)">
                        <p class="font-semibold leading-snug text-white">{{ $t->title }}</p>
                        <p class="mt-0.5 text-xs text-brand-100/50">
                            {{ $t->user?->name }} · {{ $t->venture }}
                            @if ($t->closed_on) · done {{ $t->closed_on->isToday() ? 'today' : $t->closed_on->format('j M') }}@endif
                            @if ($pub) · planner {{ $pub->isToday() ? 'today' : $pub->format('D j M') }}@endif
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5" x-show="fixFor !== {{ $t->id }}">
                            <button type="button" @click="fixFor = {{ $t->id }}; fixText = ''; $nextTick(() => $refs['fix{{ $t->id }}'].focus())"
                                    class="min-h-[36px] rounded-lg bg-white/5 px-3 text-xs font-semibold text-amber-200 ring-1 ring-white/10 hover:bg-white/10">+ Correction</button>
                            <input type="datetime-local" x-model="when[{{ $t->id }}]" class="min-h-[36px] rounded-lg border-0 bg-white/10 px-2 text-xs text-white [color-scheme:dark] focus:ring-2 focus:ring-violet-400">
                            <button type="button" :disabled="busy === {{ $t->id }} || {{ $openFixes }} > 0"
                                    @click="send(@js(route('my.editing.act', $t)), { action: 'ready', publish_at: when[{{ $t->id }}] }, {{ $t->id }})"
                                    class="min-h-[36px] rounded-lg bg-violet-500 px-3 text-xs font-bold text-white hover:bg-violet-400 disabled:opacity-40">✓ Video ready</button>
                            <button type="button" :disabled="busy === {{ $t->id }} || {{ $openFixes }} > 0"
                                    @click="send(@js(route('my.editing.act', $t)), { action: 'deliver' }, {{ $t->id }})"
                                    class="min-h-[36px] rounded-lg bg-teal-500/90 px-3 text-xs font-bold text-white hover:bg-teal-400 disabled:opacity-40">📦 Delivered</button>
                        </div>
                        <form x-show="fixFor === {{ $t->id }}" x-cloak class="mt-2 flex gap-1.5"
                              @submit.prevent="fixText.trim() && send(@js(route('my.editing.correct', $t)), { title: fixText.trim() }, {{ $t->id }})">
                            <input x-ref="fix{{ $t->id }}" x-model="fixText" maxlength="255" placeholder="What needs to change?"
                                   class="min-h-[40px] min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-sm text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-amber-400">
                            <button class="rounded-lg bg-amber-400 px-3.5 text-sm font-bold text-brand-900" :disabled="busy === {{ $t->id }}">Send back</button>
                            <button type="button" @click="fixFor = null" class="px-2 text-sm text-brand-100/50" aria-label="Cancel">✕</button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-5 text-center text-sm text-brand-100/45">Nothing waiting for review.</li>
                @endforelse
            </ul>
        </div>

        {{-- Ready to publish --}}
        <div id="ready-queue" class="scroll-mt-24 rounded-2xl bg-violet-400/[0.05] ring-1 ring-violet-400/25">
            <div class="border-b border-violet-400/15 px-4 py-3">
                <p class="font-semibold text-white">Ready to publish <span class="ml-1 rounded-full bg-violet-400/20 px-2 py-0.5 text-xs text-violet-200">{{ $ready->count() }}</span>
                    @if ($lateCount = $ready->filter->isLateToPublish()->count())
                        <span class="ml-1 rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-semibold text-red-200">{{ $lateCount }} late</span>
                    @endif
                </p>
                <p class="text-xs text-brand-100/55">Each has its publishing time. Not posted 30 minutes after it, the editor and admins get a push.</p>
            </div>
            <ul class="divide-y divide-white/5">
                @forelse ($ready as $t)
                    @php $late = $t->isLateToPublish(); @endphp
                    <li class="px-4 py-3 {{ $late ? 'bg-red-500/[0.06]' : '' }}" x-init="kind[{{ $t->id }}] = @js(in_array($t->kind, $kinds, true) ? $t->kind : 'reel'); when[{{ $t->id }}] = @js($t->publish_at?->format('Y-m-d\TH:i'))">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold leading-snug text-white">{{ $t->title }}</p>
                                <p class="mt-0.5 text-xs text-brand-100/50">{{ $t->user?->name }} · {{ $t->venture }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $late ? 'bg-red-500/20 text-red-200' : 'bg-violet-400/15 text-violet-200' }}">
                                {{ $late ? '⚠ was due' : '🕒' }} {{ $t->publish_at?->isToday() ? $t->publish_at->format('g:i A') : $t->publish_at?->format('D j M, g:i A') }}
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <select x-model="kind[{{ $t->id }}]" class="min-h-[36px] rounded-lg border-0 bg-white/10 py-0 pl-2 pr-7 text-xs text-white focus:ring-2 focus:ring-emerald-400">
                                @foreach ($kinds as $k)
                                    <option value="{{ $k }}">{{ $labels[$k] }}</option>
                                @endforeach
                            </select>
                            <button type="button" :disabled="busy === {{ $t->id }}"
                                    @click="send(@js(route('my.editing.act', $t)), { action: 'post', kind: kind[{{ $t->id }}] }, {{ $t->id }})"
                                    class="min-h-[36px] rounded-lg bg-emerald-500 px-3 text-xs font-bold text-white hover:bg-emerald-400 disabled:opacity-40">Posted</button>
                            <input type="datetime-local" x-model="when[{{ $t->id }}]" class="min-h-[36px] rounded-lg border-0 bg-white/10 px-2 text-xs text-white [color-scheme:dark]">
                            <button type="button" :disabled="busy === {{ $t->id }}"
                                    @click="send(@js(route('my.editing.act', $t)), { action: 'ready', publish_at: when[{{ $t->id }}] }, {{ $t->id }})"
                                    class="min-h-[36px] rounded-lg bg-white/10 px-3 text-xs font-semibold text-white hover:bg-white/15 disabled:opacity-40">New time</button>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-5 text-center text-sm text-brand-100/45">Nothing ready to publish.</li>
                @endforelse
            </ul>
        </div>
    </section>
@endif
