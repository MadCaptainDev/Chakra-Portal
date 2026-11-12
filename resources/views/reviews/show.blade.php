{{--
    One cut under review: the player with comments and Gemini's flags on its
    timeline, the comments list, the AI check and the verdict. Drawn by
    resources/js/video-review.js.
--}}
@php
    $card = $video->todo;
    $vertical = $video->height && $video->width && $video->height > $video->width;
    $verdictTone = ['good_to_go' => 'bg-emerald-400/15 text-emerald-200 ring-emerald-400/30', 'minor_fixes' => 'bg-amber-400/15 text-amber-200 ring-amber-400/30', 'needs_fixes' => 'bg-red-400/15 text-red-200 ring-red-400/30'];
@endphp
<x-app-layout :title="'Review · '.$card->title" dark>
    <div class="mx-auto max-w-6xl space-y-4 pb-16"
         x-data="videoReview(@js([
             'live' => route('reviews.live', $video),
             'comment' => route('reviews.comment', $video),
             'resolve' => route('reviews.comment.resolve', ['comment' => '__ID__']),
             'approve' => route('reviews.approve', $video),
             'changes' => route('reviews.changes', $video),
             'recheck' => route('reviews.recheck', $video),
         ]), @js($state))">

        {{-- Heading --}}
        <div class="flex flex-wrap items-start gap-3">
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('reviews.index') }}" class="mt-1 flex h-9 w-9 items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Back">
                <x-icon name="chevron-left" class="h-5 w-5" />
            </a>
            <div class="min-w-0 flex-1">
                <p class="text-xs text-brand-100/55">{{ $card->contentItem?->venture ?? $card->venture ?? 'Video' }} · {{ $card->user?->name }}</p>
                <h1 class="text-2xl font-extrabold tracking-tight text-white">{{ $card->title }}</h1>
                <p class="mt-0.5 text-xs text-brand-100/50">
                    Version {{ $video->version }} · uploaded {{ $video->created_at->format('j M, g:i A') }} by {{ $video->uploadedBy?->name ?? 'someone' }}
                    · {{ number_format($video->size / 1048576, 1) }} MB @if ($video->width) · {{ $video->width }}×{{ $video->height }} @endif
                </p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-bold ring-1"
                  :class="{ pending: 'bg-sky-400/15 text-sky-200 ring-sky-400/30', approved: 'bg-emerald-400/15 text-emerald-200 ring-emerald-400/30', changes: 'bg-amber-400/15 text-amber-200 ring-amber-400/30' }[s.status]"
                  x-text="s.status_label"></span>
        </div>

        @if ($versions->count() > 1)
            <div class="flex flex-wrap gap-1.5">
                @foreach ($versions as $v)
                    <a href="{{ route('reviews.show', $v) }}" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 {{ $v->id === $video->id ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15 hover:text-white' }}">
                        v{{ $v->version }} · {{ \App\Models\ReviewVideo::STATUSES[$v->status] ?? $v->status }}
                    </a>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
            {{-- Player --}}
            <div class="space-y-3">
                <div class="overflow-hidden rounded-2xl bg-black ring-1 ring-white/10">
                    @if ($video->hasFile())
                        <video x-ref="video" src="{{ route('reviews.file', $video) }}" @if ($video->poster_path) poster="{{ route('reviews.poster', $video) }}" @endif
                               preload="metadata" playsinline controls controlsList="nodownload"
                               class="mx-auto block w-full bg-black {{ $vertical ? 'max-h-[72vh] w-auto' : '' }}" @click.prevent="toggle()"></video>
                    @else
                        <div class="flex aspect-video items-center justify-center text-sm text-brand-100/50" x-ref="video">
                            This review copy was deleted after the video went out.
                        </div>
                    @endif
                </div>

                {{-- The timeline: comments (white) and AI flags (amber/red) --}}
                <div class="rounded-xl bg-[#15303f] p-3 ring-1 ring-white/[0.06]">
                    <div class="relative h-8 cursor-pointer rounded-lg bg-white/[0.06]" @click="scrub($event)" title="Click to jump">
                        <div class="absolute inset-y-0 left-0 rounded-lg bg-brand-400/25" :style="`width:${pos(t)}`"></div>
                        <template x-for="c in s.comments.filter((c) => c.at != null)" :key="'c' + c.id">
                            <button type="button" @click.stop="jump(c.at)" class="absolute top-1/2 h-4 w-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full transition hover:scale-150"
                                    :class="c.resolved ? 'bg-white/30' : 'bg-white'" :style="`left:${pos(c.at)}`" :title="c.stamp + ' ' + c.by + ': ' + c.body"></button>
                        </template>
                        <template x-for="(i, n) in ((s.ai.report && s.ai.report.issues) || []).filter((i) => i.at != null)" :key="'a' + n">
                            <button type="button" @click.stop="jump(i.at)" class="absolute bottom-0 h-2.5 w-2.5 -translate-x-1/2 rotate-45 transition hover:scale-150"
                                    :class="i.severity === 'high' ? 'bg-red-400' : 'bg-amber-300'" :style="`left:${pos(i.at)}`" :title="'AI ' + clock(i.at) + ': ' + i.note"></button>
                        </template>
                        <div class="absolute inset-y-0 w-0.5 bg-brand-200" :style="`left:${pos(t)}`"></div>
                    </div>
                    <div class="mt-2 flex items-center gap-2 text-xs text-brand-100/55">
                        <button type="button" @click="toggle()" class="rounded-md bg-white/10 px-2.5 py-1 font-semibold text-white" x-text="playing ? 'Pause' : 'Play'"></button>
                        <span class="tabular-nums" x-text="clock(t) + ' / ' + clock(duration)"></span>
                        <span class="hidden sm:inline">· Space play · ← → 2s · Shift+← → one frame · C comment</span>
                        <button type="button" @click="startComment()" class="ml-auto rounded-md bg-brand-400 px-3 py-1 font-bold text-brand-900">+ Comment at <span x-text="clock(t)"></span></button>
                    </div>
                </div>

                {{-- New comment --}}
                <form @submit.prevent="addComment()" class="rounded-xl bg-[#15303f] p-3 ring-1 ring-white/[0.06]">
                    <textarea x-ref="comment" x-model="body" rows="2" maxlength="2000" @keydown.enter.meta.prevent="addComment()" @keydown.enter.ctrl.prevent="addComment()"
                              placeholder="What needs changing? Be exact: the word, the sound, the frame."
                              class="w-full rounded-lg border-0 bg-[#0f2230] p-2.5 text-sm text-white placeholder:text-brand-100/35 focus:ring-1 focus:ring-brand-400"></textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-brand-100/60">
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" x-model="pin" class="rounded border-white/25 bg-transparent text-brand-400 focus:ring-brand-400"> At <span class="tabular-nums" x-text="clock(t)"></span></label>
                        <span x-show="!pin">On the whole video</span>
                        <button type="submit" :disabled="busy || !body.trim()" class="ml-auto rounded-lg bg-white px-4 py-1.5 text-sm font-bold text-brand-900 disabled:opacity-40">Add comment</button>
                    </div>
                </form>
            </div>

            {{-- Side: AI check, comments, verdict --}}
            <div class="space-y-3">
                {{-- AI check --}}
                <section class="rounded-xl bg-[#15303f] p-4 ring-1 ring-white/[0.06]">
                    <div class="flex items-center gap-2">
                        <x-icon name="sparkles" class="h-4 w-4 text-brand-300" />
                        <h2 class="text-sm font-semibold text-white">AI check</h2>
                        <template x-if="s.ai.verdict">
                            <span class="ml-auto rounded-full px-2.5 py-0.5 text-xs font-bold ring-1" :class="@js($verdictTone)[s.ai.verdict]">
                                <span x-text="s.ai.verdict_label"></span><span x-show="s.ai.report && s.ai.report.score" x-text="' · ' + s.ai.report.score + '/10'"></span>
                            </span>
                        </template>
                    </div>
                    <p x-show="['queued', 'running'].includes(s.ai.status)" class="mt-2 flex items-center gap-2 text-xs text-brand-100/60">
                        <span class="h-3 w-3 animate-spin rounded-full border-2 border-brand-300/30 border-t-brand-300"></span> Gemini is watching it. Usually under a minute.
                    </p>
                    <p x-show="['failed', 'skipped'].includes(s.ai.status)" class="mt-2 text-xs text-amber-200/80">
                        <span x-text="s.ai.status === 'failed' ? 'The check did not finish' : 'Not checked'"></span><span x-show="s.ai.error" x-text="': ' + s.ai.error"></span>
                        <button type="button" @click="recheck()" class="ml-1 font-semibold text-brand-200 underline underline-offset-4">Check again</button>
                    </p>
                    <template x-if="s.ai.status === 'done' && s.ai.report">
                        <div class="mt-2 space-y-2">
                            <p class="text-[13px] text-brand-100/80" x-text="s.ai.report.summary"></p>
                            <ul class="space-y-1.5">
                                <template x-for="(i, n) in s.ai.report.issues" :key="n">
                                    <li class="rounded-lg bg-white/[0.04] px-2.5 py-2 text-[12.5px]">
                                        <div class="flex items-start gap-2">
                                            <button type="button" x-show="i.at != null" @click="jump(i.at)" class="shrink-0 rounded bg-white/10 px-1.5 font-mono text-[11px] text-white" x-text="clock(i.at)"></button>
                                            <span class="min-w-0 flex-1 text-brand-100/85">
                                                <span class="mr-1 text-[10px] font-bold uppercase" :class="i.severity === 'high' ? 'text-red-300' : 'text-amber-200'" x-text="i.type"></span>
                                                <span x-text="i.note"></span>
                                            </span>
                                        </div>
                                        <button type="button" x-show="s.can_review" @click="adopt(i)" class="mt-1 text-[11px] font-semibold text-brand-300 hover:text-white">+ Add as a comment</button>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="!s.ai.report.issues.length" class="text-xs text-emerald-200/80">No problems found.</p>
                            <p x-show="s.ai.report.good && s.ai.report.good.length" class="text-xs text-brand-100/55" x-text="'Works: ' + (s.ai.report.good || []).join(' · ')"></p>
                            <p class="text-[11px] text-brand-100/35">The AI flags; a person decides.</p>
                        </div>
                    </template>
                </section>

                {{-- Comments --}}
                <section class="rounded-xl bg-[#15303f] p-4 ring-1 ring-white/[0.06]">
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold text-white">Comments</h2>
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-xs text-brand-100/70" x-text="open.length + ' open'"></span>
                        <button type="button" x-show="s.comments.length > open.length" @click="showResolved = !showResolved" class="ml-auto text-xs text-brand-300 hover:text-white" x-text="showResolved ? 'Hide resolved' : 'Show resolved'"></button>
                    </div>
                    <ul class="mt-2 max-h-[46vh] space-y-1.5 overflow-y-auto">
                        <template x-for="c in shown" :key="c.id">
                            <li class="rounded-lg px-2.5 py-2 text-[13px]" :class="c.resolved ? 'bg-white/[0.02] opacity-60' : 'bg-white/[0.05]'">
                                <div class="flex items-start gap-2">
                                    <button type="button" x-show="c.at != null" @click="jump(c.at)" class="shrink-0 rounded bg-white/10 px-1.5 font-mono text-[11px] text-white" x-text="c.stamp"></button>
                                    <div class="min-w-0 flex-1">
                                        <p class="whitespace-pre-line text-white" :class="c.resolved ? 'line-through' : ''" x-text="c.body"></p>
                                        <p class="mt-0.5 text-[11px] text-brand-100/45"><span x-text="c.by"></span> · <span x-text="c.when"></span><span x-show="c.correction" class="text-amber-200/80"> · sent as a correction</span></p>
                                    </div>
                                </div>
                                <div class="mt-1 flex gap-3 pl-0.5 text-[11px]">
                                    <button type="button" x-show="!c.resolved" @click="resolve(c)" class="font-semibold text-emerald-300/90 hover:text-emerald-200">Resolve</button>
                                    <button type="button" x-show="c.resolved" @click="resolve(c, false)" class="text-brand-100/60 hover:text-white">Reopen</button>
                                    <button type="button" x-show="c.mine && !c.correction" @click="remove(c)" class="text-brand-100/40 hover:text-red-300">Delete</button>
                                </div>
                            </li>
                        </template>
                        <li x-show="!shown.length" class="py-4 text-center text-xs text-brand-100/45">No comments yet. Pause where something is wrong and add one.</li>
                    </ul>
                </section>

                {{-- Verdict --}}
                <section x-show="s.can_review" class="rounded-xl bg-[#15303f] p-4 ring-1 ring-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">Is it good to go?</h2>
                    <template x-if="s.status !== 'pending'">
                        <p class="mt-1 text-xs text-brand-100/60"><span x-text="s.status_label"></span> by <span x-text="s.reviewed_by"></span>, <span x-text="s.reviewed_at"></span><span x-show="s.review_note" x-text="': ' + s.review_note"></span></p>
                    </template>
                    <textarea x-model="note" rows="2" maxlength="1000" placeholder="Note for the editor (optional)"
                              class="mt-2 w-full rounded-lg border-0 bg-[#0f2230] p-2.5 text-[13px] text-white placeholder:text-brand-100/35 focus:ring-1 focus:ring-brand-400"></textarea>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <button type="button" @click="changes()" :disabled="busy" class="rounded-xl bg-amber-400 px-3 py-2.5 text-sm font-bold text-brand-900 hover:bg-amber-300 disabled:opacity-50">
                            Send back<span x-show="open.length" x-text="' (' + open.length + ')'"></span>
                        </button>
                        <button type="button" @click="approve()" :disabled="busy" class="rounded-xl bg-emerald-400 px-3 py-2.5 text-sm font-bold text-brand-900 hover:bg-emerald-300 disabled:opacity-50">Good to go ✓</button>
                    </div>
                    <p class="mt-2 text-[11px] text-brand-100/45">Send back turns each open comment into a correction on the editor's card. Good to go marks it checked; the editor then sets it Video ready with its publishing time.</p>
                </section>

                <p x-show="error" x-cloak class="rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200" x-text="error"></p>

                @if ($card->contentItem?->caption)
                    <details class="rounded-xl bg-[#15303f] p-4 ring-1 ring-white/[0.06]">
                        <summary class="cursor-pointer text-sm font-semibold text-white">Caption</summary>
                        <p class="mt-2 whitespace-pre-line text-[13px] text-brand-100/80">{{ $card->contentItem->caption }}</p>
                    </details>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
