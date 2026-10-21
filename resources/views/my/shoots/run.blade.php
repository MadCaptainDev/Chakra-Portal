@php
    $videos = $shoot->videos;
    $running = $shoot->isInProgress();
    $wrapped = $shoot->hasWrapped();
    // Shown after a save so the crew member gets an explicit fork rather than
    // an empty form that looks the same as the one they just submitted.
    $justSaved = session('status') === 'saved';
@endphp

<x-editor-layout :title="$shoot->title">
    <header class="flex items-center gap-3 border-b border-white/10 px-4 py-3 shrink-0">
        <a href="{{ route('my.shoots') }}"
           class="rounded-lg p-2 -ml-2 text-white/60 hover:text-white hover:bg-white/10"
           aria-label="Leave this shoot">
            <x-icon name="chevron-left" class="h-5 w-5" />
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="truncate font-semibold leading-tight">{{ $shoot->title }}</h1>
            <p class="truncate text-xs text-white/50">
                {{ $shoot->clientLabel() ?? 'No client' }}
                @if ($shoot->location) · {{ $shoot->location }} @endif
            </p>
        </div>
        @if ($running)
            <span class="flex items-center gap-1.5 rounded-full bg-red-500/15 px-2.5 py-1 text-xs font-medium text-red-300">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-400"></span>
                Rolling
            </span>
        @elseif ($wrapped)
            <span class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-medium text-emerald-300">Wrapped</span>
        @elseif ($shoot->hasReached())
            <span class="rounded-full bg-amber-500/15 px-2.5 py-1 text-xs font-medium text-amber-200">Reached</span>
        @elseif ($shoot->hasDeparted())
            <span class="rounded-full bg-sky-500/15 px-2.5 py-1 text-xs font-medium text-sky-200">On the way</span>
        @endif
    </header>

    @if (session('error'))
        <div class="mx-4 mt-3 rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200 shrink-0">
            {{ session('error') }}
        </div>
    @endif

    <div class="flex-1 overflow-y-auto px-4 py-4">
        {{-- ================= NOT STARTED ================= --}}
        @if (! $running && ! $wrapped)
            <div class="mx-auto flex h-full max-w-sm flex-col items-center justify-center text-center"
                 x-data="shootLive(@js(['liveUrl' => route('my.shoots.live', $shoot), 'version' => $liveVersion]))">
                <p class="text-sm text-white/60">
                    {{ $shoot->starts_at?->format('l j F') }}
                    @if ($shoot->starts_at && ! ($shoot->starts_at->hour === 0 && $shoot->starts_at->minute === 0))
                        · {{ $shoot->starts_at->format('g:i A') }}
                    @endif
                </p>

                @if ($shoot->crew->isNotEmpty())
                    <p class="mt-2 text-sm text-white/50">
                        {{ $shoot->crew->map(fn ($c) => $c->user?->name)->filter()->implode(', ') }}
                    </p>
                @endif

                @if ($blockedBy)
                    {{-- The rule the user asked for: one live shoot per person. --}}
                    <div class="mt-8 w-full rounded-xl bg-amber-500/10 p-4 text-left">
                        <p class="text-sm font-medium text-amber-200">You are still on another shoot</p>
                        <p class="mt-1 text-sm text-amber-200/70">
                            &ldquo;{{ $blockedBy->title }}&rdquo; is still rolling. Wrap it before starting this one.
                        </p>
                        <a href="{{ route('my.shoots.run', $blockedBy) }}"
                           class="mt-3 inline-flex rounded-lg bg-amber-400/20 px-3 py-1.5 text-sm font-medium text-amber-100 hover:bg-amber-400/30">
                            Go to it
                        </a>
                    </div>
                @elseif (! $shoot->hasReached() && ! $shoot->hasDeparted())
                    {{-- Step 1: setting off. The Studio Map shows the crew on the road from now. --}}
                    <form method="POST" action="{{ route('my.shoots.depart', $shoot) }}" class="mt-10 w-full">
                        @csrf
                        <button type="submit"
                                class="w-full rounded-2xl bg-sky-400 px-6 py-5 text-lg font-semibold text-brand-900 shadow-lg shadow-sky-400/20 active:scale-[0.98] transition">
                            We're on the way
                        </button>
                    </form>
                    <p class="mt-3 text-xs text-white/40">Tap as you leave. The studio sees you and the crew travelling to the shoot.</p>

                    <form method="POST" action="{{ route('my.shoots.reached', $shoot) }}" class="mt-6">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-white/60 underline underline-offset-4 hover:text-white">
                            Already there? We've reached
                        </button>
                    </form>
                @elseif (! $shoot->hasReached())
                    {{-- Step 2: on location. Anyone on the crew can tap it. --}}
                    <div class="mt-8 w-full rounded-xl bg-sky-500/10 px-4 py-3 text-sm text-sky-200">
                        On the way since {{ $shoot->departed_at->format('g:i A') }}
                        @if ($shoot->departedBy) · {{ $shoot->departedBy->name }} @endif
                    </div>
                    <form method="POST" action="{{ route('my.shoots.reached', $shoot) }}" class="mt-6 w-full">
                        @csrf
                        <button type="submit"
                                class="w-full rounded-2xl bg-amber-400 px-6 py-5 text-lg font-semibold text-brand-900 shadow-lg shadow-amber-400/20 active:scale-[0.98] transition">
                            We've reached the location
                        </button>
                    </form>
                    <p class="mt-3 text-xs text-white/40">Lets the studio know the crew is on site. Start the shoot once you're set up.</p>

                    <form method="POST" action="{{ route('my.shoots.start', $shoot) }}" class="mt-6">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-white/60 underline underline-offset-4 hover:text-white">
                            Already set up? Start shoot now
                        </button>
                    </form>
                @else
                    {{-- Step 2: rolling. --}}
                    <div class="mt-8 w-full rounded-xl bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                        Reached at {{ $shoot->reached_at->format('g:i A') }}
                        @if ($shoot->reachedBy) · {{ $shoot->reachedBy->name }} @endif
                    </div>
                    <form method="POST" action="{{ route('my.shoots.start', $shoot) }}" class="mt-6 w-full">
                        @csrf
                        <button type="submit"
                                class="w-full rounded-2xl bg-red-500 px-6 py-5 text-lg font-semibold text-white shadow-lg shadow-red-500/20 active:scale-[0.98] transition">
                            Start shoot
                        </button>
                    </form>
                    <p class="mt-3 text-xs text-white/40">
                        This marks the shoot live @if ($shoot->isFromNotion()) and moves its Notion card to Shooting @endif.
                    </p>
                @endif
            </div>

        {{-- ================= WRAPPED ================= --}}
        @elseif ($wrapped)
            <div class="mx-auto max-w-sm text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/15">
                    <x-icon name="check-circle" class="h-7 w-7 text-emerald-300" />
                </div>
                <h2 class="mt-4 text-lg font-semibold">That's a wrap</h2>
                <p class="mt-1 text-sm text-white/50">
                    {{ $videos->count() }} {{ Str::plural('video', $videos->count()) }} ·
                    {{ $shoot->started_at?->diffForHumans($shoot->finished_at, true) }} on location
                </p>

                @include('my.shoots._video-list', ['videos' => $videos])
            </div>

        {{-- ================= RUNNING ================= --}}
        @else
            <div class="mx-auto max-w-sm"
                 x-data="shootScripts(@js(['shootId' => $shoot->id, 'liveUrl' => route('my.shoots.live', $shoot), 'liveVersion' => $liveVersion, 'videoIds' => $videos->pluck('id')->all(), 'me' => auth()->user()->name, 'oldSectionLog' => old('section_log', ''), 'scripts' => $scripts, 'oldName' => old('name', ''), 'oldScriptId' => old('script_id')]))">
                {{-- Live: what changed on another phone or over MCP. --}}
                <template x-teleport="body">
                    <div x-show="!!notice" x-cloak x-transition.opacity
                         class="fixed inset-x-3 top-3 z-[80] mx-auto max-w-sm rounded-xl bg-sky-500 px-4 py-3 text-sm font-semibold text-brand-900 shadow-lg"
                         @click="notice = ''" x-text="notice"></div>
                </template>
                <template x-if="newVideos > 0">
                    <a href="{{ route('my.shoots.run', $shoot) }}" class="mb-4 block rounded-xl bg-sky-500/15 px-4 py-3 text-sm text-sky-200">
                        <span x-text="newVideos"></span> new <span x-text="newVideos === 1 ? 'video' : 'videos'"></span> from the crew · <span class="underline">Refresh the list</span>
                    </a>
                </template>
                @if ($justSaved)
                    {{-- The fork: another one, or done. --}}
                    <div class="text-center">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/15">
                            <x-icon name="check-circle" class="h-7 w-7 text-emerald-300" />
                        </div>
                        <h2 class="mt-4 text-lg font-semibold">
                            Video {{ $videos->count() }} saved
                        </h2>
                        <p class="mt-1 text-sm text-white/50">{{ $videos->last()?->name }}</p>

                        <div class="mt-8 space-y-3">
                            <template x-if="todo.length > 0">
                                <button type="button" @click="open(todo[0])"
                                        class="block w-full rounded-2xl bg-amber-400 px-6 py-4 text-left text-brand-900 active:scale-[0.98] transition">
                                    <span class="block text-xs font-semibold uppercase tracking-wide text-brand-900/60">Read next script</span>
                                    <span class="block truncate text-base font-bold" x-text="'#' + todo[0].id + ' ' + todo[0].title"></span>
                                </button>
                            </template>
                            <a href="{{ route('my.shoots.run', $shoot) }}"
                               class="block w-full rounded-2xl bg-white px-6 py-4 text-base font-semibold text-brand-900 active:scale-[0.98] transition">
                                Next video
                            </a>

                            <form method="POST" action="{{ route('my.shoots.finish', $shoot) }}"
                                  onsubmit="return confirm('Wrap this shoot? You will not be able to add more videos.')">
                                @csrf
                                <button type="submit"
                                        class="w-full rounded-2xl border border-white/20 px-6 py-4 text-base font-medium text-white/80 hover:bg-white/5 active:scale-[0.98] transition">
                                    Finish shoot
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    {{-- Scripts: read on set, then "Shot it" names the video after the script. --}}
                    @if ($scripts)
                        <section class="mb-6">
                            <div class="flex items-baseline justify-between">
                                <p class="text-xs uppercase tracking-wide text-white/40">Scripts</p>
                                <p class="text-xs text-white/40"><span x-text="done.length"></span> of <span x-text="scripts.length"></span> shot</p>
                            </div>
                            <ul class="mt-2 space-y-2">
                                <template x-for="s in todo" :key="s.id">
                                    <li>
                                        <button type="button" @click="open(s)"
                                                class="flex w-full items-center gap-3 rounded-xl bg-white/5 px-3.5 py-3 text-left ring-1 ring-white/10 active:scale-[0.99] transition"
                                                :class="scriptId === s.id ? 'ring-2 ring-amber-400' : ''">
                                            <x-icon name="document" class="h-5 w-5 shrink-0 text-amber-300" />
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium"><span class="text-amber-300/80" x-text="'#' + s.id"></span> <span x-text="s.title"></span></span>
                                                <span class="block text-xs text-white/40" x-text="s.status"></span>
                                            </span>
                                            <span class="shrink-0 text-xs font-semibold text-amber-300">Read</span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                            <template x-if="todo.length === 0">
                                <p class="mt-2 rounded-xl bg-emerald-500/10 px-3.5 py-3 text-sm text-emerald-200">Every script has been shot.</p>
                            </template>

                            <template x-if="done.length > 0">
                                <div class="mt-2">
                                    <button type="button" @click="showShot = !showShot" class="py-1.5 text-xs text-white/50 underline underline-offset-4">
                                        <span x-text="showShot ? 'Hide' : 'Show'"></span> already shot (<span x-text="done.length"></span>)
                                    </button>
                                    <ul x-show="showShot" class="mt-1.5 space-y-1.5">
                                        <template x-for="s in done" :key="s.id">
                                            <li>
                                                <button type="button" @click="open(s)" class="flex w-full items-center gap-3 rounded-xl bg-white/[0.03] px-3.5 py-2.5 text-left">
                                                    <x-icon name="check-circle" class="h-4 w-4 shrink-0 text-emerald-400" />
                                                    <span class="min-w-0 flex-1 truncate text-sm text-white/60" x-text="'#' + s.id + ' ' + s.title"></span>
                                                    <span class="shrink-0 text-[11px] text-white/35" x-text="s.shot === 'here' ? 'this shoot' : 'earlier shoot'"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
                        </section>

                        {{-- The reader: full screen, big type, auto-scroll. --}}
                        <template x-teleport="body">
                            <div x-show="!!reading" x-cloak @keydown.escape.window="close()"
                                 class="fixed inset-0 z-[70] flex flex-col bg-black text-white">
                                <div class="flex items-center gap-2 border-b border-white/10 px-3 py-2.5">
                                    <button type="button" @click="close()" class="rounded-lg p-2 text-white/60 hover:bg-white/10" aria-label="Close script">
                                        <span class="block h-5 w-5 text-center text-lg leading-5" aria-hidden="true">✕</span>
                                    </button>
                                    <p class="min-w-0 flex-1 truncate text-sm font-semibold"><span class="text-amber-300" x-text="'#' + reading?.id"></span> <span x-text="reading?.title"></span></p>
                                    <button type="button" @click="smaller()" class="h-10 w-10 rounded-lg bg-white/10 text-sm font-bold" aria-label="Smaller text">A−</button>
                                    <button type="button" @click="bigger()" class="h-10 w-10 rounded-lg bg-white/10 text-lg font-bold" aria-label="Bigger text">A+</button>
                                </div>

                                <div x-ref="page" @click="stop()" class="flex-1 overflow-y-auto px-5 pb-[60vh] pt-6">
                                    <template x-for="(sec, i) in (reading?.sections || [])" :key="i">
                                        <div class="mb-8 rounded-xl p-2 -mx-2 transition"
                                             :class="isRunning(i) ? 'bg-red-500/10 ring-1 ring-red-500/40' : (log?.sections[i]?.shot ? 'bg-emerald-500/[0.06]' : '')">
                                            <div class="mb-2 flex items-center gap-2" @click.stop>
                                                <p class="min-w-0 flex-1 truncate text-xs font-semibold uppercase tracking-[0.18em] text-amber-300" x-text="sec.heading"></p>
                                                <span class="shrink-0 text-sm tabular-nums"
                                                      :class="isRunning(i) ? 'text-red-300' : 'text-white/50'"
                                                      x-show="isRunning(i) || secondsFor(i) > 0" x-text="clock(secondsFor(i))"></span>
                                                <template x-if="isRunning(i)">
                                                    <button type="button" @click="stopSection()"
                                                            class="flex h-10 shrink-0 items-center gap-1.5 rounded-lg bg-red-500 px-3.5 text-sm font-bold text-white">
                                                        <span class="h-2.5 w-2.5 rounded-[2px] bg-white"></span> Stop
                                                    </button>
                                                </template>
                                                <template x-if="!isRunning(i)">
                                                    <button type="button" @click="startSection(i)"
                                                            class="flex h-10 shrink-0 items-center gap-1.5 rounded-lg px-3.5 text-sm font-bold"
                                                            :class="log?.sections[i]?.takes ? 'bg-white/10 text-white/80' : 'bg-white text-black'">
                                                        <span>▶</span> <span x-text="log?.sections[i]?.takes ? 'Retake' : 'Start'"></span>
                                                    </button>
                                                </template>
                                                <button type="button" @click="toggleShot(i)" x-show="!isRunning(i)"
                                                        class="h-10 shrink-0 rounded-lg px-2.5 text-xs font-semibold"
                                                        :class="log?.sections[i]?.shot ? 'bg-emerald-500/20 text-emerald-300' : 'bg-white/5 text-white/40'"
                                                        x-text="log?.sections[i]?.shot ? '✓ Shot' : 'Not shot'"></button>
                                            </div>
                                            <p class="mb-2 text-[11px] text-white/35" x-show="(log?.sections[i]?.takes || 0) > 1"
                                               x-text="(log?.sections[i]?.takes || 0) + ' takes'"></p>
                                            <div class="leading-snug [&_p]:mb-4" :style="`font-size: ${size}px`" x-html="sec.body"></div>
                                        </div>
                                    </template>
                                </div>

                                <div class="border-t border-white/10 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="slower()" class="h-12 w-12 shrink-0 rounded-xl bg-white/10 text-lg" aria-label="Scroll slower">−</button>
                                        <button type="button" @click="toggle()"
                                                class="h-12 flex-1 rounded-xl text-sm font-bold"
                                                :class="scrolling ? 'bg-white/15 text-white' : 'bg-white/10 text-white/80'">
                                            <span x-text="scrolling ? 'Pause scroll' : 'Auto-scroll'"></span>
                                            <span class="ml-1 text-xs font-normal text-white/50" x-text="speed"></span>
                                        </button>
                                        <button type="button" @click="faster()" class="h-12 w-12 shrink-0 rounded-xl bg-white/10 text-lg" aria-label="Scroll faster">+</button>
                                    </div>
                                    <button type="button" @click="shotIt()"
                                            class="mt-2 flex h-14 w-full items-center justify-between rounded-xl bg-amber-400 px-4 text-brand-900 active:scale-[0.98] transition">
                                        <span class="text-left text-xs font-semibold leading-tight">
                                            <span x-text="shotCount"></span> of <span x-text="reading?.sections.length"></span> sections shot<br>
                                            <span class="tabular-nums" x-text="clock(totalSeconds)"></span>
                                        </span>
                                        <span class="text-base font-bold">Done — save video</span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    @endif

                    <form method="POST" action="{{ route('my.shoots.videos.store', $shoot) }}" x-ref="form"
                          enctype="multipart/form-data" class="space-y-5 scroll-mt-4" @submit="submitted($event)">
                        @csrf
                        <input type="hidden" name="script_id" :value="scriptId ?? ''">
                        <input type="hidden" name="section_log" :value="sectionLog">

                        <div>
                            <p class="text-xs uppercase tracking-wide text-white/40">
                                Video {{ $videos->count() + 1 }}
                            </p>
                            <input type="text" name="name" x-model="name" required
                                   placeholder="Video title"
                                   class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3.5 text-base text-white placeholder-white/30 focus:ring-2 focus:ring-white/40">
                            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                            <x-input-error :messages="$errors->get('script_id')" class="mt-1.5" />
                            <template x-if="!!linked">
                                <p class="mt-2 flex items-center gap-2 rounded-lg bg-amber-400/10 px-3 py-2 text-xs text-amber-200">
                                    <x-icon name="document" class="h-4 w-4 shrink-0" />
                                    <span class="min-w-0 flex-1 truncate">From script <span x-text="'#' + linked?.id"></span>: <span x-text="linked?.title"></span><template x-if="!!sectionLog"><span class="text-amber-200/60" x-text="' · ' + JSON.parse(sectionLog).filter(s => s.shot).length + ' of ' + JSON.parse(sectionLog).length + ' sections · ' + clock(JSON.parse(sectionLog).reduce((n, s) => n + s.seconds, 0))"></span></template></span>
                                    <button type="button" @click="unlink()" class="shrink-0 text-amber-200/60 underline">Unlink</button>
                                </p>
                            </template>
                        </div>

                        <div>
                            <label class="text-sm text-white/60">Photo of this video</label>
                            {{-- capture lets a phone open the camera straight away
                                 instead of the gallery, which is what is wanted
                                 standing on set. --}}
                            <input type="file" name="photo" x-ref="photo" accept="image/*" capture="environment" required
                                   class="mt-2 w-full rounded-xl bg-white/10 px-4 py-3 text-sm text-white/70 file:mr-3 file:rounded-lg file:border-0 file:bg-white/15 file:px-3 file:py-1.5 file:text-sm file:text-white">
                            <x-input-error :messages="$errors->get('photo')" class="mt-1.5" />
                        </div>

                        <div>
                            <label class="text-sm text-white/60">Notes</label>
                            <textarea name="notes" rows="3" placeholder="Anything worth remembering in the edit"
                                      class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-base text-white placeholder-white/30 focus:ring-2 focus:ring-white/40">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-1.5" />
                        </div>

                        <button type="submit" :disabled="saving"
                                class="w-full rounded-2xl bg-white px-6 py-4 text-base font-semibold text-brand-900 active:scale-[0.98] transition disabled:opacity-60">
                            <span x-text="saving ? 'Saving…' : 'Save video'">Save video</span>
                        </button>
                    </form>

                    @if ($videos->isNotEmpty())
                        @include('my.shoots._video-list', ['videos' => $videos])

                        <form method="POST" action="{{ route('my.shoots.finish', $shoot) }}" class="mt-6"
                              onsubmit="return confirm('Wrap this shoot? You will not be able to add more videos.')">
                            @csrf
                            <button type="submit"
                                    class="w-full rounded-2xl border border-white/20 px-6 py-4 text-base font-medium text-white/80 hover:bg-white/5">
                                Finish shoot
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        @endif
    </div>
</x-editor-layout>
