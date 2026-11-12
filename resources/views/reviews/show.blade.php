{{--
    Reviewing a cut, together. The player (tap to play, double-tap a side to
    skip, drag the timeline), everyone's comments and the AI's flags pinned
    on it, who else is watching and where, replies and "agree" on comments,
    and a verdict that takes a deliberate press-and-hold.
    Drawn by resources/js/video-review.js (videoReview).
--}}
@php
    $card = $video->todo;
    $vertical = $video->height && $video->width && $video->height > $video->width;
    $state['duration'] = $video->duration;
    $tones = [
        'good_to_go' => ['text-emerald-300', 'bg-emerald-400/15 ring-emerald-400/30', '#34d399'],
        'minor_fixes' => ['text-amber-200', 'bg-amber-400/15 ring-amber-400/30', '#fbbf24'],
        'needs_fixes' => ['text-red-300', 'bg-red-400/15 ring-red-400/30', '#f87171'],
    ];
    $btn = 'inline-flex items-center justify-center gap-1.5 rounded-xl font-semibold transition active:scale-[.97] disabled:opacity-40 disabled:active:scale-100';
@endphp
<x-app-layout :title="'Review · '.$card->title" dark>
    <div class="mx-auto max-w-7xl pb-28 lg:pb-12"
         x-data="videoReview(@js([
             'live' => route('reviews.live', $video),
             'comment' => route('reviews.comment', $video),
             'resolve' => route('reviews.comment.resolve', ['comment' => '__ID__']),
             'like' => route('reviews.comment.like', ['comment' => '__ID__']),
             'approve' => route('reviews.approve', $video),
             'changes' => route('reviews.changes', $video),
             'recheck' => route('reviews.recheck', $video),
         ]), @js($state))">

        {{-- ——— Top bar ——— --}}
        <header class="animate-rise-in mb-4 flex flex-wrap items-center gap-3">
            <a href="{{ route('reviews.index') }}" class="flex h-11 w-11 items-center justify-center rounded-xl text-brand-100/60 transition hover:bg-white/10 hover:text-white" aria-label="All reviews">
                <x-icon name="chevron-left" class="h-5 w-5" />
            </a>
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs text-brand-100/55">
                    {{ $card->contentItem?->venture ?? $card->venture ?? 'Video' }} · {{ $card->user?->name }} · v{{ $video->version }}
                    · {{ $video->created_at->format('j M, g:i A') }}
                </p>
                <h1 class="truncate text-xl font-extrabold tracking-tight text-white sm:text-2xl">{{ $card->title }}</h1>
            </div>

            {{-- Who else is here --}}
            <div class="flex items-center -space-x-2" x-show="s.watching.length" x-cloak x-transition.opacity>
                <template x-for="w in s.watching" :key="w.id">
                    <button type="button" @click="follow(w)" :title="w.name + (w.playing ? ' is watching at ' : ' is paused at ') + clock(w.at) + ' · tap to go there'"
                            class="rv-ping relative flex h-9 w-9 items-center justify-center rounded-full text-[11px] font-bold text-brand-900 ring-2 ring-brand-900 transition hover:z-10 hover:scale-110"
                            :style="`background:${hue(w.id)};color:${hue(w.id)}`">
                        <template x-if="w.avatar"><img :src="w.avatar" alt="" class="h-full w-full rounded-full object-cover"></template>
                        <span x-show="!w.avatar" class="text-brand-900" x-text="initials(w.name)"></span>
                    </button>
                </template>
                <span class="pl-3 text-xs text-brand-100/60" x-text="s.watching.length === 1 ? s.watching[0].name.split(' ')[0] + ' is watching' : s.watching.length + ' watching'"></span>
            </div>

            <span class="rounded-full px-3 py-1.5 text-xs font-bold ring-1 transition"
                  :class="{ pending: 'bg-sky-400/15 text-sky-200 ring-sky-400/30', approved: 'bg-emerald-400/15 text-emerald-200 ring-emerald-400/30', changes: 'bg-amber-400/15 text-amber-200 ring-amber-400/30' }[s.status]"
                  x-text="s.status_label"></span>

            @if ($versions->count() > 1)
                <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                    <button type="button" @click="open = !open" class="{{ $btn }} h-10 bg-white/[0.07] px-3 text-sm text-white ring-1 ring-white/10 hover:bg-white/10">
                        v{{ $video->version }} <span class="text-brand-100/50">of {{ $versions->count() }}</span> ▾
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-xl bg-[#15303f] p-1 shadow-2xl ring-1 ring-white/10">
                        @foreach ($versions as $v)
                            <a href="{{ route('reviews.show', $v) }}" class="flex items-center justify-between rounded-lg px-3 py-2.5 text-sm {{ $v->id === $video->id ? 'bg-white/10 text-white' : 'text-brand-100/80 hover:bg-white/5' }}">
                                <span>Version {{ $v->version }}</span>
                                <span class="text-[11px] text-brand-100/50">{{ \App\Models\ReviewVideo::STATUSES[$v->status] ?? $v->status }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </header>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
            {{-- ——— Player column (sticky on phones so the video stays in view while reading) ——— --}}
            <div class="sticky top-0 z-20 -mx-4 bg-brand-900/95 px-4 pb-3 pt-1 backdrop-blur sm:mx-0 sm:px-0 lg:static lg:bg-transparent lg:p-0 lg:backdrop-blur-none">
                <div x-ref="stage" class="group relative overflow-hidden rounded-2xl bg-black shadow-2xl ring-1 ring-white/10">
                    @if ($video->hasFile())
                        <video x-ref="video" src="{{ route('reviews.file', $video) }}" @if ($video->poster_path) poster="{{ route('reviews.poster', $video) }}" @endif
                               preload="metadata" playsinline controlsList="nodownload" disablePictureInPicture
                               class="mx-auto block w-full select-none bg-black {{ $vertical ? 'max-h-[44vh] w-auto sm:max-h-[64vh] lg:max-h-[70vh]' : 'max-h-[44vh] lg:max-h-[70vh]' }}"
                               @contextmenu.prevent></video>

                        {{-- Tap layer: tap = play/pause, double-tap a side = 5s --}}
                        <div class="absolute inset-0 cursor-pointer" @click="tapVideo($event)"></div>

                        {{-- The big icon, on every play/pause --}}
                        <template x-if="flash">
                            <span :key="flash.k" class="rv-flash pointer-events-none absolute left-1/2 top-1/2 flex h-20 w-20 items-center justify-center rounded-full bg-black/55 text-white">
                                <svg x-show="flash.icon === 'play'" class="h-9 w-9 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                <svg x-show="flash.icon === 'pause'" class="h-9 w-9" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                            </span>
                        </template>
                        <template x-if="ripple">
                            <span :key="ripple.k" class="pointer-events-none absolute inset-y-0 flex w-1/3 items-center justify-center" :class="ripple.side < 0 ? 'left-0' : 'right-0'">
                                <span class="rv-ripple absolute h-40 w-40 rounded-full bg-white/30"></span>
                                <span class="relative text-sm font-bold text-white drop-shadow" x-text="ripple.side < 0 ? '« 5s' : '5s »'"></span>
                            </span>
                        </template>

                        {{-- Paused: a calm play button --}}
                        <button type="button" x-show="!playing && !flash" x-transition.opacity.duration.200ms @click.stop="toggle()"
                                class="absolute left-1/2 top-1/2 flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-brand-900 shadow-xl transition hover:scale-105" aria-label="Play">
                            <svg class="h-7 w-7 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        </button>

                        {{-- After a verdict --}}
                        <template x-if="done">
                            <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/40">
                                <div class="relative">
                                    <template x-for="n in 12" :key="n">
                                        <span class="rv-burst absolute left-1/2 top-1/2 -ml-1 -mt-1 h-2 w-2 rounded-full" :style="`--r:${n * 30}deg;background:${done === 'approve' ? '#34d399' : '#fbbf24'}`"></span>
                                    </template>
                                    <span class="rv-done flex h-24 w-24 items-center justify-center rounded-full text-4xl font-black text-brand-900 shadow-2xl"
                                          :class="done === 'approve' ? 'bg-emerald-400' : 'bg-amber-400'" x-text="done === 'approve' ? '✓' : '↩'"></span>
                                </div>
                            </div>
                        </template>
                    @else
                        <div class="flex aspect-video items-center justify-center p-6 text-center text-sm text-brand-100/50">
                            This review copy was deleted after the video went out.
                        </div>
                    @endif
                </div>

                @if ($video->hasFile())
                    {{-- ——— Timeline: drag to scrub; pins for comments, diamonds for AI flags, dots for others watching ——— --}}
                    <div class="mt-3 select-none">
                        <div x-ref="track" class="relative h-12 cursor-pointer touch-none"
                             @pointerdown="scrubStart($event)" @pointermove="scrubMove($event)" @pointerup="scrubEnd()" @pointercancel="scrubEnd()" @pointerleave="if (!dragging) hover = null">
                            <div class="absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 overflow-hidden rounded-full bg-white/10 transition-all" :class="dragging || hover ? 'h-2.5' : ''">
                                <div class="absolute inset-y-0 left-0 bg-white/15" :style="`width:${pos(buffered)}`"></div>
                                <div class="absolute inset-y-0 left-0 bg-brand-400" :style="`width:${pos(t)}`"></div>
                            </div>
                            {{-- Comment pins (above) --}}
                            <template x-for="c in timed" :key="'p' + c.id">
                                <button type="button" @pointerdown.stop @click.stop="goTo(c)"
                                        class="rv-pin absolute top-0 flex h-6 w-6 -translate-x-1/2 items-center justify-center rounded-full text-[9px] font-bold text-brand-900 ring-2 ring-brand-900 transition hover:z-10 hover:scale-125"
                                        :class="c.resolved ? 'opacity-40' : ''" :style="`left:${pos(c.at)};background:${hue(c.by_id)}`" :title="c.stamp + ' ' + c.by + ': ' + c.body" x-text="initials(c.by)"></button>
                            </template>
                            {{-- AI flags (below) --}}
                            <template x-for="(i, n) in ((s.ai.report && s.ai.report.issues) || []).filter((i) => i.at != null)" :key="'a' + n">
                                <button type="button" @pointerdown.stop @click.stop="jump(i.at); tab = 'ai'"
                                        class="rv-pin absolute bottom-0 h-3 w-3 -translate-x-1/2 rotate-45 transition hover:scale-150" :class="i.severity === 'high' ? 'bg-red-400' : 'bg-amber-300'"
                                        :style="`left:${pos(i.at)}`" :title="'AI ' + clock(i.at) + ': ' + i.note"></button>
                            </template>
                            {{-- Others' playheads --}}
                            <template x-for="w in s.watching.filter((w) => w.at != null)" :key="'w' + w.id">
                                <span class="pointer-events-none absolute top-1/2 h-4 w-1 -translate-x-1/2 -translate-y-1/2 rounded-full transition-all duration-700" :style="`left:${pos(w.at)};background:${hue(w.id)}`"></span>
                            </template>
                            {{-- Playhead --}}
                            <span class="pointer-events-none absolute top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow-lg ring-4 ring-brand-400/40 transition-transform" :class="dragging ? 'scale-125' : ''" :style="`left:${pos(t)}`"></span>
                            {{-- Time preview --}}
                            <span x-show="hover" x-cloak class="pointer-events-none absolute -top-7 -translate-x-1/2 rounded-md bg-white px-1.5 py-0.5 font-mono text-[11px] font-bold text-brand-900 shadow" :style="`left:${hover ? hover.x : 0}%`" x-text="hover ? clock(hover.t) : ''"></span>
                        </div>

                        {{-- Controls --}}
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-brand-100/60">
                            <button type="button" @click="skip(-5)" class="{{ $btn }} h-10 w-10 bg-white/[0.06] text-white hover:bg-white/10" aria-label="Back 5 seconds">«5</button>
                            <button type="button" @click="toggle()" class="{{ $btn }} h-10 w-12 bg-white text-brand-900" :aria-label="playing ? 'Pause' : 'Play'">
                                <svg x-show="!playing" class="h-5 w-5 translate-x-px" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                <svg x-show="playing" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                            </button>
                            <button type="button" @click="skip(5)" class="{{ $btn }} h-10 w-10 bg-white/[0.06] text-white hover:bg-white/10" aria-label="On 5 seconds">5»</button>
                            <span class="ml-1 font-mono tabular-nums text-white/80" x-text="clock(t) + ' / ' + clock(duration)"></span>
                            <span class="ml-auto"></span>
                            <button type="button" @click="setRate()" class="{{ $btn }} h-10 min-w-[44px] bg-white/[0.06] px-2 font-mono text-white hover:bg-white/10" x-text="rate + '×'" aria-label="Speed"></button>
                            <button type="button" @click="mute()" class="{{ $btn }} h-10 w-10 bg-white/[0.06] text-white hover:bg-white/10" :aria-label="muted ? 'Sound on' : 'Mute'" x-text="muted ? '🔇' : '🔊'"></button>
                            <button type="button" @click="full()" class="{{ $btn }} hidden h-10 w-10 bg-white/[0.06] text-white hover:bg-white/10 sm:inline-flex" aria-label="Full screen">⛶</button>
                            <button type="button" @click="startComment()" class="{{ $btn }} h-10 bg-amber-400 px-3.5 font-bold text-brand-900 hover:bg-amber-300">
                                + Comment at <span class="font-mono" x-text="clock(t)"></span>
                            </button>
                        </div>
                        <p class="mt-1.5 hidden text-[11px] text-brand-100/35 lg:block">Space play · J / L 5s · ← → 2s · Shift + ← → one frame · C comment · M mute · F full screen</p>
                    </div>
                @endif
            </div>

            {{-- ——— Side: tabs ——— --}}
            <div class="min-w-0">
                <div class="mb-3 grid grid-cols-3 rounded-xl bg-white/[0.05] p-1 text-sm font-semibold ring-1 ring-white/10" role="tablist">
                    <button type="button" role="tab" @click="tab = 'comments'" :aria-selected="tab === 'comments'" class="h-10 rounded-lg transition" :class="tab === 'comments' ? 'bg-white text-brand-900 shadow' : 'text-brand-100/70 hover:text-white'">
                        Comments <span class="ml-0.5 rounded-full px-1.5 text-[11px]" :class="tab === 'comments' ? 'bg-brand-900/10' : 'bg-white/10'" x-text="open.length"></span>
                    </button>
                    <button type="button" role="tab" @click="tab = 'ai'" :aria-selected="tab === 'ai'" class="relative h-10 rounded-lg transition" :class="tab === 'ai' ? 'bg-white text-brand-900 shadow' : 'text-brand-100/70 hover:text-white'">
                        AI check
                        <span x-show="['queued', 'running'].includes(s.ai.status)" class="absolute right-2 top-2 h-2 w-2 animate-pulse rounded-full bg-brand-300"></span>
                        <span x-show="s.ai.verdict" class="absolute right-2 top-2 h-2 w-2 rounded-full" :style="`background:${(@js(collect($tones)->map(fn ($t) => $t[2])))[s.ai.verdict] || '#999'}`"></span>
                    </button>
                    <button type="button" role="tab" @click="tab = 'details'" :aria-selected="tab === 'details'" class="h-10 rounded-lg transition" :class="tab === 'details' ? 'bg-white text-brand-900 shadow' : 'text-brand-100/70 hover:text-white'">Details</button>
                </div>

                {{-- ——— Comments ——— --}}
                <section x-show="tab === 'comments'" x-transition.opacity.duration.150ms class="space-y-3">
                    {{-- Composer: Enter makes a new line; only the button (or Ctrl/Cmd + Enter) sends --}}
                    <form @submit.prevent="send()" class="rounded-2xl bg-[#15303f] p-3 ring-1 ring-white/[0.08] transition focus-within:ring-amber-400/60">
                        <textarea x-ref="comment" x-model="body" rows="2" maxlength="2000"
                                  @keydown.enter.ctrl.prevent="send()" @keydown.enter.meta.prevent="send()" @focus="$refs.video && $refs.video.pause()"
                                  placeholder="What needs changing? The word, the sound, the frame…"
                                  class="w-full resize-none rounded-xl border-0 bg-[#0f2230] p-3 text-[15px] text-white placeholder:text-brand-100/35 focus:ring-0"></textarea>
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" @click="pin = !pin" class="{{ $btn }} h-10 px-3 text-xs ring-1" :class="pin ? 'bg-amber-400/15 text-amber-200 ring-amber-400/40' : 'bg-white/5 text-brand-100/60 ring-white/10'">
                                <span x-show="pin">📍 At <span class="font-mono" x-text="clock(t)"></span></span>
                                <span x-show="!pin" x-cloak>Whole video</span>
                            </button>
                            <span x-show="body.trim()" x-cloak class="hidden text-[11px] text-brand-100/35 sm:inline">Draft saved · Ctrl+Enter to send</span>
                            <button type="submit" :disabled="!body.trim() || busy.send" class="{{ $btn }} ml-auto h-10 bg-white px-5 text-sm font-bold text-brand-900">
                                <span x-show="busy.send" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-brand-900/30 border-t-brand-900"></span>
                                Send
                            </button>
                        </div>
                    </form>

                    {{-- Filters --}}
                    <div class="flex gap-1.5 overflow-x-auto pb-0.5 text-xs">
                        <template x-for="f in [['open', 'Open'], ['all', 'All'], ['mine', 'Mine'], ['resolved', 'Resolved']]" :key="f[0]">
                            <button type="button" @click="filter = f[0]" class="{{ $btn }} h-9 shrink-0 px-3.5 ring-1" :class="filter === f[0] ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15 hover:text-white'" x-text="f[1]"></button>
                        </template>
                    </div>

                    {{-- The list --}}
                    <ul class="space-y-2.5">
                        <template x-for="c in shown" :key="c.id">
                            <li :id="'c-' + c.id" class="rounded-2xl bg-[#15303f] p-3.5 ring-1 transition"
                                :class="[glow[c.id] ? 'rv-glow ring-amber-400/60' : 'ring-white/[0.07]', c.resolved ? 'opacity-60' : '']"
                                x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full text-[11px] font-bold text-brand-900" :style="`background:${hue(c.by_id)}`">
                                        <template x-if="c.avatar"><img :src="c.avatar" alt="" class="h-full w-full object-cover"></template>
                                        <span x-show="!c.avatar" x-text="initials(c.by)"></span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                                            <span class="font-semibold text-white" x-text="c.by"></span>
                                            <button type="button" x-show="c.at != null" @click="jump(c.at)" class="rounded-md bg-white/10 px-1.5 py-0.5 font-mono text-[11px] font-bold text-amber-200 transition hover:bg-amber-400/20" x-text="c.stamp"></button>
                                            <span class="text-brand-100/40" x-text="c.when"></span>
                                            <span x-show="isNew(c)" class="rounded-full bg-brand-400 px-1.5 text-[10px] font-bold text-brand-900">NEW</span>
                                            <span x-show="c.correction" class="rounded-full bg-amber-400/15 px-1.5 text-[10px] font-semibold text-amber-200">sent as a correction</span>
                                        </p>
                                        <p class="mt-1 whitespace-pre-line break-words text-[14.5px] leading-relaxed text-white/95" :class="c.resolved ? 'line-through decoration-white/30' : ''" x-text="c.body"></p>

                                        {{-- Actions: big enough for a thumb, spaced so one tap cannot hit two --}}
                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            <button type="button" @click="like(c)" :disabled="!!busy['l' + c.id]" class="{{ $btn }} h-9 px-3 text-xs ring-1"
                                                    :class="c.liked ? 'bg-sky-400/15 text-sky-200 ring-sky-400/40' : 'bg-white/[0.04] text-brand-100/70 ring-white/10 hover:text-white'"
                                                    :title="c.liked_by.length ? 'Agreed: ' + c.liked_by.join(', ') : 'Agree'">
                                                <span :class="c.liked ? 'rv-like' : ''" :key="'lk' + c.id + c.liked">👍</span>
                                                <span x-text="c.likes ? c.likes : 'Agree'"></span>
                                            </button>
                                            <button type="button" @click="openReply(c)" class="{{ $btn }} h-9 bg-white/[0.04] px-3 text-xs text-brand-100/70 ring-1 ring-white/10 hover:text-white">
                                                Reply<span x-show="c.replies.length" x-text="' · ' + c.replies.length"></span>
                                            </button>
                                            <button type="button" x-show="!c.resolved" @click="resolve(c)" class="{{ $btn }} h-9 bg-white/[0.04] px-3 text-xs text-emerald-300 ring-1 ring-white/10 hover:bg-emerald-400/10">✓ Resolve</button>
                                            <button type="button" x-show="c.resolved" x-cloak @click="resolve(c, false)" class="{{ $btn }} h-9 bg-white/[0.04] px-3 text-xs text-brand-100/70 ring-1 ring-white/10">Reopen</button>
                                            <button type="button" x-show="c.mine && !c.correction" @click="remove(c)" class="{{ $btn }} ml-auto h-9 w-9 text-brand-100/35 hover:bg-red-400/10 hover:text-red-300" aria-label="Delete comment">🗑</button>
                                        </div>
                                        <p x-show="c.liked_by.length" class="mt-1 text-[11px] text-brand-100/40" x-text="'Agreed: ' + c.liked_by.join(', ')"></p>

                                        {{-- Replies --}}
                                        <template x-if="c.replies.length">
                                            <ul class="mt-2.5 space-y-2 border-l-2 border-white/10 pl-3">
                                                <template x-for="r in c.replies" :key="r.id">
                                                    <li :id="'c-' + r.id" class="rounded-xl p-1.5 transition" :class="glow[r.id] ? 'rv-glow' : ''">
                                                        <p class="flex flex-wrap items-center gap-x-2 text-xs">
                                                            <span class="h-2 w-2 rounded-full" :style="`background:${hue(r.by_id)}`"></span>
                                                            <span class="font-semibold text-white" x-text="r.by"></span>
                                                            <span class="text-brand-100/40" x-text="r.when"></span>
                                                        </p>
                                                        <p class="mt-0.5 whitespace-pre-line break-words text-[14px] text-white/90" x-text="r.body"></p>
                                                        <div class="mt-1 flex items-center gap-1.5">
                                                            <button type="button" @click="like(r)" class="{{ $btn }} h-8 px-2.5 text-[11px] ring-1" :class="r.liked ? 'bg-sky-400/15 text-sky-200 ring-sky-400/40' : 'text-brand-100/60 ring-white/10'">
                                                                👍 <span x-text="r.likes || ''"></span>
                                                            </button>
                                                            <button type="button" x-show="r.mine" @click="remove(r)" class="{{ $btn }} h-8 w-8 text-brand-100/35 hover:text-red-300" aria-label="Delete reply">🗑</button>
                                                        </div>
                                                    </li>
                                                </template>
                                            </ul>
                                        </template>

                                        <form x-show="replyTo === c.id" x-cloak x-transition @submit.prevent="reply(c)" class="mt-2.5 flex items-end gap-2">
                                            <textarea :id="'reply-' + c.id" x-model="replyBody" rows="1" maxlength="2000" @keydown.enter.ctrl.prevent="reply(c)" @keydown.enter.meta.prevent="reply(c)" @keydown.escape="replyTo = null"
                                                      placeholder="Reply…" class="min-h-[40px] flex-1 resize-none rounded-xl border-0 bg-[#0f2230] p-2.5 text-sm text-white placeholder:text-brand-100/35 focus:ring-1 focus:ring-amber-400"></textarea>
                                            <button type="submit" :disabled="!replyBody.trim() || busy.reply" class="{{ $btn }} h-10 bg-white px-4 text-sm font-bold text-brand-900">Reply</button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        </template>
                    </ul>
                    <div x-show="!shown.length" class="rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center">
                        <p class="text-2xl">💬</p>
                        <p class="mt-1 text-sm text-brand-100/60" x-text="filter === 'open' ? 'No open comments. Pause where something is wrong and add one.' : 'Nothing here.'"></p>
                    </div>
                </section>

                {{-- ——— AI check ——— --}}
                <section x-show="tab === 'ai'" x-cloak x-transition.opacity.duration.150ms class="space-y-3">
                    <template x-if="['queued', 'running'].includes(s.ai.status)">
                        <div class="space-y-2 rounded-2xl bg-[#15303f] p-4 ring-1 ring-white/[0.07]">
                            <p class="text-sm text-brand-100/70">Gemini is watching the cut… usually under a minute.</p>
                            <div class="rv-shimmer h-4 w-3/4 rounded"></div>
                            <div class="rv-shimmer h-4 w-1/2 rounded"></div>
                            <div class="rv-shimmer h-16 rounded-xl"></div>
                        </div>
                    </template>
                    <template x-if="['failed', 'skipped', 'none'].includes(s.ai.status)">
                        <div class="rounded-2xl bg-[#15303f] p-4 text-sm ring-1 ring-white/[0.07]">
                            <p class="text-amber-200/90"><span x-text="s.ai.status === 'failed' ? 'The check did not finish' : 'Not checked'"></span><span x-show="s.ai.error" x-text="': ' + s.ai.error"></span></p>
                            <button type="button" @click="recheck()" class="{{ $btn }} mt-3 h-10 bg-white/10 px-4 text-sm text-white">Check again</button>
                        </div>
                    </template>
                    <template x-if="s.ai.status === 'done' && s.ai.report">
                        <div class="space-y-3">
                            <div class="flex items-center gap-4 rounded-2xl bg-[#15303f] p-4 ring-1 ring-white/[0.07]">
                                {{-- Score ring --}}
                                <svg class="h-20 w-20 shrink-0 -rotate-90" viewBox="0 0 36 36" x-data="{ shown: 0 }" x-init="setTimeout(() => shown = (s.ai.report.score || 0) / 10, 80)">
                                    <circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="3"/>
                                    <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3" stroke-linecap="round" pathLength="100"
                                            :stroke="(@js(collect($tones)->map(fn ($t) => $t[2])))[s.ai.verdict] || '#94a3b8'"
                                            :stroke-dasharray="`${shown * 100} 100`" style="transition: stroke-dasharray 1s cubic-bezier(.2,.8,.2,1)"/>
                                    <text x="18" y="21.5" text-anchor="middle" transform="rotate(90 18 18)" class="fill-white text-[10px] font-black" x-text="s.ai.report.score || '–'"></text>
                                </svg>
                                <div class="min-w-0">
                                    <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-bold ring-1"
                                          :class="(@js(collect($tones)->map(fn ($t) => $t[1].' '.$t[0])))[s.ai.verdict]" x-text="s.ai.verdict_label"></span>
                                    <p class="mt-1.5 text-[14px] leading-relaxed text-white/90" x-text="s.ai.report.summary"></p>
                                </div>
                            </div>
                            <ul class="space-y-2">
                                <template x-for="(i, n) in s.ai.report.issues" :key="n">
                                    <li class="rounded-2xl bg-[#15303f] p-3.5 ring-1 ring-white/[0.07]" :style="`animation: settleIn .38s cubic-bezier(.2,.8,.2,1) ${n * 60}ms both`">
                                        <div class="flex items-start gap-2.5">
                                            <button type="button" x-show="i.at != null" @click="jump(i.at)" class="shrink-0 rounded-md bg-white/10 px-1.5 py-0.5 font-mono text-[11px] font-bold text-white hover:bg-white/20" x-text="clock(i.at)"></button>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[10px] font-black uppercase tracking-wider" :class="i.severity === 'high' ? 'text-red-300' : 'text-amber-200'" x-text="i.type + (i.severity === 'high' ? ' · important' : '')"></p>
                                                <p class="mt-0.5 text-[14px] text-white/90" x-text="i.note"></p>
                                            </div>
                                        </div>
                                        <button type="button" @click="adopt(i)" class="{{ $btn }} mt-2 h-9 bg-white/[0.06] px-3 text-xs text-white ring-1 ring-white/10 hover:bg-white/10">+ Add as a comment</button>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="!s.ai.report.issues.length" class="rounded-2xl bg-emerald-400/[0.07] px-4 py-3 text-sm text-emerald-200">No problems found.</p>
                            <p x-show="s.ai.report.good && s.ai.report.good.length" class="text-xs text-brand-100/55" x-text="'Works well: ' + (s.ai.report.good || []).join(' · ')"></p>
                            <p class="text-[11px] text-brand-100/35">The AI flags; people decide.</p>
                        </div>
                    </template>
                </section>

                {{-- ——— Details ——— --}}
                <section x-show="tab === 'details'" x-cloak x-transition.opacity.duration.150ms class="space-y-3">
                    <dl class="grid grid-cols-[110px_1fr] gap-x-3 gap-y-2 rounded-2xl bg-[#15303f] p-4 text-sm ring-1 ring-white/[0.07]">
                        <dt class="text-brand-100/50">Editor</dt><dd class="text-white">{{ $card->user?->name }}</dd>
                        <dt class="text-brand-100/50">Uploaded</dt><dd class="text-white">{{ $video->created_at->format('D j M, g:i A') }} by {{ $video->uploadedBy?->name ?? '—' }}</dd>
                        <dt class="text-brand-100/50">File</dt><dd class="truncate text-white">{{ $video->original_name }} · {{ number_format($video->size / 1048576, 1) }} MB</dd>
                        @if ($video->width)<dt class="text-brand-100/50">Size</dt><dd class="text-white">{{ $video->width }}×{{ $video->height }} {{ $vertical ? '(vertical)' : '' }}</dd>@endif
                        <dt class="text-brand-100/50">Decision</dt><dd class="text-white"><span x-text="s.status_label"></span><span x-show="s.reviewed_by" x-text="' · ' + s.reviewed_by + ', ' + s.reviewed_at"></span></dd>
                        <template x-if="s.review_note"><dt class="text-brand-100/50">Note</dt></template>
                        <template x-if="s.review_note"><dd class="text-white" x-text="s.review_note"></dd></template>
                    </dl>
                    @if ($card->contentItem?->caption)
                        <div class="rounded-2xl bg-[#15303f] p-4 ring-1 ring-white/[0.07]">
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/50">Caption</p>
                            <p class="mt-2 whitespace-pre-line text-[13px] text-brand-100/85">{{ $card->contentItem->caption }}</p>
                        </div>
                    @endif
                </section>

                {{-- ——— Verdict (desktop) ——— --}}
                <div x-show="s.can_review" class="mt-4 hidden gap-2 lg:grid lg:grid-cols-2">
                    <button type="button" @click="decide('changes')" class="{{ $btn }} h-12 bg-amber-400 text-sm font-bold text-brand-900 hover:bg-amber-300">
                        ↩ Send back<span x-show="open.length" x-text="' (' + open.length + ')'"></span>
                    </button>
                    <button type="button" @click="decide('approve')" class="{{ $btn }} h-12 bg-emerald-400 text-sm font-bold text-brand-900 hover:bg-emerald-300">✓ Good to go</button>
                </div>
            </div>
        </div>

        {{-- ——— Verdict bar (phones): fixed, away from the comment buttons ——— --}}
        <div x-show="s.can_review" class="fixed inset-x-0 bottom-0 z-30 border-t border-white/10 bg-brand-900/95 px-4 pb-[max(.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur lg:hidden">
            <div class="mx-auto grid max-w-md grid-cols-2 gap-3">
                <button type="button" @click="decide('changes')" class="{{ $btn }} h-12 bg-amber-400 text-sm font-bold text-brand-900">↩ Send back<span x-show="open.length" x-text="' (' + open.length + ')'"></span></button>
                <button type="button" @click="decide('approve')" class="{{ $btn }} h-12 bg-emerald-400 text-sm font-bold text-brand-900">✓ Good to go</button>
            </div>
        </div>

        {{-- ——— The verdict sheet: read what will happen, then press and hold ——— --}}
        <template x-teleport="body">
            <div x-show="!!sheet" x-cloak class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true"
                 @keydown.escape.window="if (!busy.verdict) sheet = null">
                <div class="absolute inset-0 bg-black/65" x-show="!!sheet" x-transition.opacity @click="if (!busy.verdict) sheet = null"></div>
                <div x-show="!!sheet" class="rv-sheet theme-dark relative w-full max-w-lg rounded-t-3xl bg-[#15303f] p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] text-white shadow-2xl ring-1 ring-white/10 sm:rounded-3xl">
                    <div class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-white/15 sm:hidden"></div>
                    <template x-if="sheet === 'approve'">
                        <div>
                            <p class="text-3xl">✅</p>
                            <h3 class="mt-1 text-xl font-extrabold">Good to go?</h3>
                            <p class="mt-1 text-sm text-brand-100/70">Marks version {{ $video->version }} as checked. {{ $card->user?->name ? Str::before($card->user->name, ' ') : 'The editor' }} is told, and sets it Video ready with its publishing time.</p>
                            <p x-show="open.length" class="mt-3 rounded-xl bg-amber-400/10 px-3 py-2 text-sm text-amber-200" x-text="open.length + (open.length === 1 ? ' comment is' : ' comments are') + ' still open. They stay on the video but are not sent as changes.'"></p>
                        </div>
                    </template>
                    <template x-if="sheet === 'changes'">
                        <div>
                            <p class="text-3xl">↩️</p>
                            <h3 class="mt-1 text-xl font-extrabold">Send back for changes?</h3>
                            <p class="mt-1 text-sm text-brand-100/70" x-text="open.length ? 'Each open comment becomes a correction on the editor\'s card:' : 'There are no open comments. Write what to change in the note.'"></p>
                            <ul x-show="open.length" class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-xl bg-[#0f2230] p-2 text-[13px]">
                                <template x-for="c in open" :key="'s' + c.id">
                                    <li class="flex gap-2"><span class="shrink-0 font-mono text-amber-200" x-text="c.stamp || '—'"></span><span class="truncate text-white/85" x-text="c.body"></span><span x-show="c.likes" class="shrink-0 text-sky-200" x-text="'+' + c.likes"></span></li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <textarea x-model="note" rows="2" maxlength="1000" placeholder="Note for the editor (optional)"
                              class="mt-4 w-full resize-none rounded-xl border-0 bg-[#0f2230] p-3 text-sm text-white placeholder:text-brand-100/35 focus:ring-1 focus:ring-amber-400"></textarea>

                    {{-- Press and hold: a stray tap does nothing --}}
                    <button type="button" :disabled="busy.verdict"
                            @pointerdown.prevent="holdStart()" @pointerup="holdEnd()" @pointerleave="holdEnd()" @pointercancel="holdEnd()" @contextmenu.prevent
                            @keydown.enter.prevent="holdStart()" @keyup.enter="holdEnd()" @keydown.space.prevent="holdStart()" @keyup.space="holdEnd()"
                            class="relative mt-4 h-14 w-full select-none overflow-hidden rounded-2xl text-base font-extrabold text-brand-900 disabled:opacity-60"
                            :class="sheet === 'approve' ? 'bg-emerald-400/40' : 'bg-amber-400/40'" style="touch-action: none">
                        <span class="absolute inset-y-0 left-0" :class="sheet === 'approve' ? 'bg-emerald-400' : 'bg-amber-400'" :style="`width:${hold * 100}%`"></span>
                        <span class="relative" x-text="busy.verdict ? 'Saving…' : (hold > 0 ? 'Keep holding…' : (sheet === 'approve' ? 'Press and hold to approve' : 'Press and hold to send back'))"></span>
                    </button>
                    <button type="button" @click="sheet = null" :disabled="busy.verdict" class="mt-2 h-11 w-full rounded-xl text-sm text-brand-100/60 hover:text-white">Cancel</button>
                </div>
            </div>
        </template>

        {{-- ——— Toasts ——— --}}
        <template x-teleport="body">
            <div class="pointer-events-none fixed inset-x-0 bottom-24 z-[95] flex flex-col items-center gap-2 px-4 lg:bottom-6 lg:items-end lg:pr-6">
                <template x-for="t in toasts" :key="t.id">
                    <div class="rv-toast pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl px-4 py-3 shadow-2xl ring-1"
                         :class="t.bad ? 'bg-red-500 text-white ring-red-300/30' : 'bg-white text-brand-900 ring-black/5'">
                        <button type="button" class="min-w-0 flex-1 text-left" @click="t.onClick && t.onClick(); closeToast(t)">
                            <p class="text-sm font-bold" x-text="t.title"></p>
                            <p x-show="t.body" class="mt-0.5 line-clamp-2 text-xs opacity-75" x-text="t.body"></p>
                        </button>
                        <button type="button" x-show="!!t.undo" @click="t.undo(); closeToast(t)" class="shrink-0 rounded-lg bg-brand-900 px-3 py-1.5 text-xs font-bold text-white">Undo</button>
                    </div>
                </template>
            </div>
        </template>
    </div>
</x-app-layout>
