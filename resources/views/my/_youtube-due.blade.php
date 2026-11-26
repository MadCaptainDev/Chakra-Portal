{{--
    Reels out on Instagram whose YouTube Short (the same video, without the
    copyrighted music) is still to be posted. One tap marks it posted --
    who and when is kept; undo is allowed for an hour. App\Support\YoutubeShorts.
    $shortsDue: Collection<Todo>, $showEditor: bool
--}}
@if ($shortsDue->isNotEmpty())
    @php $grace = \App\Support\YoutubeShorts::GRACE_HOURS; @endphp
    <section id="youtube-shorts" class="scroll-mt-24 rounded-2xl border border-red-400/30 bg-[#15303f] p-4 sm:p-5"
             x-data="{ busy: null, done: {}, error: '',
                 async mark(id, undo = false) {
                     this.busy = id; this.error = '';
                     const r = await fetch(@js(route('my.editing.act', ['todo' => '__ID__'])).replace('__ID__', id), { method: 'POST', credentials: 'same-origin',
                         headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                         body: JSON.stringify({ action: undo ? 'unyoutube' : 'youtube' }) });
                     const d = await r.json().catch(() => ({}));
                     if (r.ok) { this.done[id] = !undo; } else { this.error = d.message || 'That did not work.'; }
                     this.busy = null;
                 } }">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-white">▶ YouTube Shorts to post <span class="ml-1 rounded-full bg-red-500/20 px-2 text-xs text-red-200">{{ $shortsDue->count() }}</span></h2>
            <p class="text-xs text-brand-100/55">The same reel without the copyrighted music. Mark it once it is up on YouTube.</p>
        </div>
        <p x-show="error" x-cloak x-text="error" class="mt-2 rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200"></p>
        <ul class="mt-2 divide-y divide-white/5">
            @foreach ($shortsDue as $t)
                @php $late = $t->posted_at->lte(now()->subHours($grace)); @endphp
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-2.5">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('videos.card', $t) }}" class="block truncate text-sm font-semibold text-white hover:underline">{{ $t->title }}</a>
                        <p class="text-xs {{ $late ? 'text-red-300' : 'text-brand-100/55' }}">
                            @if ($showEditor) {{ $t->user?->name }} · @endif{{ $t->venture }} · on Instagram {{ $t->posted_at->diffForHumans() }}{{ $late ? ' — late' : '' }}
                        </p>
                    </div>
                    <button type="button" x-show="!done[{{ $t->id }}]" :disabled="busy === {{ $t->id }}" @click="mark({{ $t->id }})"
                            class="h-10 rounded-lg bg-red-500 px-3.5 text-[13px] font-bold text-white hover:bg-red-400 disabled:opacity-40">▶ Posted on YouTube</button>
                    <span x-show="done[{{ $t->id }}]" x-cloak class="flex items-center gap-2 text-[13px] font-semibold text-emerald-300">
                        ✓ Marked <button type="button" @click="mark({{ $t->id }}, true)" class="text-xs font-normal text-brand-100/50 underline hover:text-white">Undo</button>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
