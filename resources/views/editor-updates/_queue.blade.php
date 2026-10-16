{{--
    What is waiting on a decision now (not on the day shown), as designed:
    Waiting for your review -- a correction, Video ready with its publishing
    time, or Delivered -- beside Ready to publish, late ones first. Every
    button calls My Editing's own endpoints (EditingController).
--}}
@php
    $kinds = \App\Models\Todo::POST_KINDS;
    $labels = \App\Models\Todo::KINDS;
    $span = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    $btn = 'inline-flex h-10 items-center rounded-[10px] px-3.5 text-[13px] font-semibold disabled:opacity-40';
    $canAct = auth()->user()->isAdmin();
@endphp
<section class="grid gap-5 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]"
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

    {{-- Waiting for your review --}}
    <div id="review-queue" class="scroll-mt-24 overflow-hidden rounded-[20px] border border-sky-400/30 bg-[#15303f]" x-data="{ all: false }">
        <div class="flex items-center justify-between px-5 py-4 sm:px-6">
            <h2 class="text-[17px] font-bold text-white">Waiting for your review <span class="ml-1.5 rounded-full bg-sky-400/20 px-2.5 py-0.5 text-[13px] text-sky-200">{{ $review->count() }}</span></h2>
            <span class="text-xs text-brand-100/50">Oldest first</span>
        </div>
        @forelse ($review as $i => $t)
            @php
                $openFixes = $t->corrections->filter->isOpen()->count();
                $pub = $t->contentItem?->published_date;
                $default = ($pub && $pub->gte(today()) ? $pub->copy()->setTime(18, 0) : now()->addHour()->startOfHour())->format('Y-m-d\TH:i');
                $doneAt = $t->updates->last(fn ($u) => $u->to_status === \App\Models\Todo::STATUS_COMPLETED)?->created_at ?? $t->updated_at;
                $wait = (int) $doneAt->diffInMinutes(now());
                $waitCls = $wait >= 240 ? 'bg-red-500/15 text-red-300' : ($wait >= 120 ? 'bg-amber-400/15 text-amber-200' : 'bg-white/[0.08] text-brand-100/80');
            @endphp
            <div class="space-y-3 border-t border-white/[0.06] px-5 py-4 sm:px-6" x-init="when[{{ $t->id }}] = @js($default)" @if ($i >= 3) x-show="all" x-cloak @endif>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[15px] font-semibold text-white">{{ $t->title }}</p>
                        <p class="mt-0.5 text-xs text-brand-100/65">
                            {{ $t->user?->name }} · {{ $t->venture }}
                            · {{ $pub ? 'planner '.($pub->isToday() ? 'today' : $pub->format('D j M')) : 'no planner date' }}
                            @if ($openFixes) · <span class="text-amber-300">{{ $openFixes }} correction{{ $openFixes > 1 ? 's' : '' }} open</span>@endif
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $waitCls }}">waiting {{ $span($wait) }}</span>
                </div>
                {{-- Managers see their team's queue; deciding on a video is the admin's. --}}
                @if ($canAct)
                <div class="flex flex-wrap gap-2" x-show="fixFor !== {{ $t->id }}">
                    <button type="button" @click="fixFor = {{ $t->id }}; fixText = ''; $nextTick(() => $refs['fix{{ $t->id }}'].focus())"
                            class="{{ $btn }} border border-amber-400/45 text-amber-300 hover:bg-amber-400/10">+ Correction</button>
                    <label class="inline-flex h-10 items-center gap-2 rounded-[10px] bg-[#0f2230] pl-3 text-[13px] text-brand-100/80">
                        Publish
                        <input type="datetime-local" x-model="when[{{ $t->id }}]" class="h-10 rounded-r-[10px] border-0 bg-transparent px-2 text-[13px] font-semibold text-white [color-scheme:dark] focus:ring-2 focus:ring-violet-400">
                    </label>
                    <button type="button" :disabled="busy === {{ $t->id }} || {{ $openFixes }} > 0"
                            @click="send(@js(route('my.editing.act', $t)), { action: 'ready', publish_at: when[{{ $t->id }}] }, {{ $t->id }})"
                            class="{{ $btn }} bg-violet-500 text-white hover:bg-violet-400">✓ Video ready</button>
                    <button type="button" :disabled="busy === {{ $t->id }} || {{ $openFixes }} > 0"
                            @click="send(@js(route('my.editing.act', $t)), { action: 'deliver' }, {{ $t->id }})"
                            class="{{ $btn }} bg-[#1a3a4b] text-teal-200 hover:bg-white/15">Delivered</button>
                </div>
                <form x-show="fixFor === {{ $t->id }}" x-cloak class="flex gap-2"
                      @submit.prevent="fixText.trim() && send(@js(route('my.editing.correct', $t)), { title: fixText.trim() }, {{ $t->id }})">
                    <input x-ref="fix{{ $t->id }}" x-model="fixText" maxlength="255" placeholder="What needs to change?"
                           class="h-10 min-w-0 flex-1 rounded-[10px] border-0 bg-[#0f2230] px-3 text-sm text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-amber-400">
                    <button class="{{ $btn }} bg-amber-400 font-bold text-brand-900" :disabled="busy === {{ $t->id }}">Send back</button>
                    <button type="button" @click="fixFor = null" class="px-2 text-sm text-brand-100/50" aria-label="Cancel">✕</button>
                </form>
                @endif
            </div>
        @empty
            <p class="border-t border-white/[0.06] px-6 py-8 text-center text-sm text-brand-100/45">Nothing waiting for review.</p>
        @endforelse
        @if ($review->count() > 3)
            <button type="button" @click="all = !all" class="block w-full border-t border-white/[0.06] px-6 py-3.5 text-left text-[13px] font-semibold text-brand-300 hover:text-white"
                    x-text="all ? 'Show fewer' : 'Show {{ $review->count() - 3 }} more'"></button>
        @endif
    </div>

    {{-- Ready to publish --}}
    <div id="ready-queue" class="scroll-mt-24 flex flex-col gap-3.5 rounded-[20px] border border-violet-400/30 bg-[#15303f] px-5 py-4 sm:px-6">
        <h2 class="text-[17px] font-bold text-white">Ready to publish <span class="ml-1.5 rounded-full bg-violet-400/20 px-2.5 py-0.5 text-[13px] text-violet-200">{{ $ready->count() }}</span>
            @if ($lateCount = $ready->filter->isLateToPublish()->count())
                <span class="ml-1 rounded-full bg-red-500/20 px-2.5 py-0.5 text-[13px] font-semibold text-red-200">{{ $lateCount }} late</span>
            @endif
        </h2>
        @forelse ($ready as $t)
            @php $late = $t->isLateToPublish(); @endphp
            <div class="space-y-2.5 rounded-[14px] p-3.5 {{ $late ? 'bg-red-500/10 ring-1 ring-red-400/30' : 'bg-[#1a3a4b]' }}"
                 x-init="kind[{{ $t->id }}] = @js(in_array($t->kind, $kinds, true) ? $t->kind : 'reel'); when[{{ $t->id }}] = @js($t->publish_at?->format('Y-m-d\TH:i'))">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">{{ $t->title }}</p>
                        <p class="text-xs text-brand-100/60">{{ $t->user?->name }} · {{ $t->venture }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $late ? 'bg-red-500/20 text-red-200' : 'bg-violet-400/15 text-violet-200' }}">
                        {{ $late ? 'was due' : '◷' }} {{ $t->publish_at?->isToday() ? $t->publish_at->format('g:i A') : $t->publish_at?->format('D j M, g:i A') }}
                    </span>
                </div>
                @if ($canAct)
                <div class="flex flex-wrap items-center gap-2">
                    <select x-model="kind[{{ $t->id }}]" class="h-10 rounded-[10px] border-0 bg-[#0f2230] py-0 pl-3 pr-8 text-[13px] text-white focus:ring-2 focus:ring-emerald-400">
                        @foreach ($kinds as $k)
                            <option value="{{ $k }}">{{ $labels[$k] }}</option>
                        @endforeach
                    </select>
                    <button type="button" :disabled="busy === {{ $t->id }}"
                            @click="send(@js(route('my.editing.act', $t)), { action: 'post', kind: kind[{{ $t->id }}] }, {{ $t->id }})"
                            class="{{ $btn }} bg-emerald-500 text-white hover:bg-emerald-400">Posted</button>
                    <button type="button" :disabled="busy === {{ $t->id }}"
                            @click="send(@js(route('my.editing.act', $t)), { action: 'deliver' }, {{ $t->id }})"
                            class="{{ $btn }} bg-teal-500/90 text-white hover:bg-teal-400">Delivered</button>
                    <input type="datetime-local" x-model="when[{{ $t->id }}]" class="h-10 rounded-[10px] border-0 bg-[#0f2230] px-2 text-[13px] text-white [color-scheme:dark]" aria-label="New publishing time">
                    <button type="button" :disabled="busy === {{ $t->id }}"
                            @click="send(@js(route('my.editing.act', $t)), { action: 'ready', publish_at: when[{{ $t->id }}] }, {{ $t->id }})"
                            class="{{ $btn }} bg-white/10 text-white hover:bg-white/15">New time</button>
                </div>
                @endif
            </div>
        @empty
            <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-[14px] border border-dashed border-white/15 px-5 py-8 text-center">
                <span class="text-3xl text-violet-300">◷</span>
                <p class="text-[15px] font-semibold text-white">Nothing scheduled</p>
                <p class="max-w-xs text-[13px] leading-relaxed text-brand-100/65">Approve a video with its time and it waits here, to be posted or delivered. Not out 30 minutes after its time, the editor and you get a reminder.</p>
            </div>
        @endforelse
    </div>
</section>
