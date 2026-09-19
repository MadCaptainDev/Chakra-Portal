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
        <a href="{{ url()->previous() === url()->current() ? route('dashboard') : url()->previous() }}"
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
            <div class="mx-auto flex h-full max-w-sm flex-col items-center justify-center text-center">
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
                @else
                    <form method="POST" action="{{ route('my.shoots.start', $shoot) }}" class="mt-10 w-full">
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
            <div class="mx-auto max-w-sm">
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
                    <form method="POST" action="{{ route('my.shoots.videos.store', $shoot) }}"
                          enctype="multipart/form-data" class="space-y-5">
                        @csrf

                        <div>
                            <p class="text-xs uppercase tracking-wide text-white/40">
                                Video {{ $videos->count() + 1 }}
                            </p>
                            <input type="text" name="name" value="{{ old('name') }}" required autofocus
                                   placeholder="What is this one?"
                                   class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3.5 text-base text-white placeholder-white/30 focus:ring-2 focus:ring-white/40">
                            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                        </div>

                        <div>
                            <label class="text-sm text-white/60">Photo</label>
                            {{-- capture lets a phone open the camera straight away
                                 instead of the gallery, which is what is wanted
                                 standing on set. --}}
                            <input type="file" name="photo" accept="image/*" capture="environment"
                                   class="mt-2 w-full rounded-xl bg-white/10 px-4 py-3 text-sm text-white/70 file:mr-3 file:rounded-lg file:border-0 file:bg-white/15 file:px-3 file:py-1.5 file:text-sm file:text-white">
                            <x-input-error :messages="$errors->get('photo')" class="mt-1.5" />
                        </div>

                        <div>
                            <label class="text-sm text-white/60">Notes</label>
                            <textarea name="notes" rows="3" placeholder="Anything worth remembering in the edit"
                                      class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-base text-white placeholder-white/30 focus:ring-2 focus:ring-white/40">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-1.5" />
                        </div>

                        <button type="submit"
                                class="w-full rounded-2xl bg-white px-6 py-4 text-base font-semibold text-brand-900 active:scale-[0.98] transition">
                            Save video
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
