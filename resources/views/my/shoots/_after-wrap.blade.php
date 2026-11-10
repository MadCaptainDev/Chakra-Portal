{{--
    The day after "that's a wrap": where the crew went, the footage onto a
    drive, the kit back on the shelf. Three cards, each done or not.
--}}
@php
    $kitOut = $kit->filter(fn ($l) => $l->isCheckedOut());
    $kitDone = $kit->isNotEmpty() && $kitOut->isEmpty() && $kit->every(fn ($l) => $l->returned_at !== null || $l->checked_out_at === null);
    $wentOn = $myCrew?->after_wrap === \App\Models\ShootCrew::AFTER_NEXT_SHOOT;
    $tick = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full';
@endphp
<div class="mt-8 space-y-3 text-left">

    @unless ($kitOnly ?? false)
    {{-- 1. Where next --}}
    <section class="rounded-2xl bg-white/[0.04] p-4 ring-1 ring-white/10">
        <div class="flex items-center gap-2.5">
            <span class="{{ $tick }} {{ $wentOn || $myCrew?->back_at_office_at ? 'bg-emerald-400 text-brand-900' : 'ring-2 ring-white/25' }}">
                @if ($wentOn || $myCrew?->back_at_office_at) <x-icon name="check" class="h-3.5 w-3.5" /> @endif
            </span>
            <h3 class="text-sm font-semibold">Where next?</h3>
        </div>

        @if ($wentOn)
            <p class="mt-2 text-sm text-white/60">
                Went straight on to
                @if ($myCrew->nextShoot)
                    <a href="{{ route('my.shoots.run', $myCrew->nextShoot) }}" class="font-medium text-sky-300 underline underline-offset-4">{{ $myCrew->nextShoot->title }}</a>
                @else the next shoot
                @endif
            </p>
        @elseif ($myCrew?->back_at_office_at)
            <p class="mt-2 text-sm text-white/60">
                Left {{ $myCrew->returning_at?->format('g:i A') }} · back at office {{ $myCrew->back_at_office_at->format('g:i A') }}
            </p>
        @elseif ($myCrew?->returning_at)
            <p class="mt-2 text-sm text-sky-200">Returning to office since {{ $myCrew->returning_at->format('g:i A') }}</p>
            <form method="POST" action="{{ route('my.shoots.office', $shoot) }}" class="mt-3">
                @csrf <input type="hidden" name="arrived" value="1">
                <button type="submit" class="w-full rounded-xl bg-emerald-400 px-4 py-3.5 text-sm font-semibold text-brand-900 active:scale-[0.98] transition">Reached office</button>
            </form>
        @else
            <div class="mt-3 space-y-2">
                @if ($nextShoot)
                    <form method="POST" action="{{ route('my.shoots.next', $shoot) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-xl bg-sky-400 px-4 py-3.5 text-left text-brand-900 active:scale-[0.98] transition">
                            <span class="block text-[11px] font-semibold uppercase tracking-wide text-brand-900/60">On the way to the next shoot</span>
                            <span class="block truncate text-sm font-bold">{{ $nextShoot->title }}@if ($nextShoot->hasStartTime()) · {{ $nextShoot->starts_at->format('g:i A') }}@endif</span>
                            @if ($nextShoot->location) <span class="block truncate text-xs text-brand-900/70">{{ $nextShoot->location }}</span> @endif
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('my.shoots.office', $shoot) }}">
                    @csrf
                    <button type="submit" class="w-full rounded-xl {{ $nextShoot ? 'border border-white/20 text-white/80' : 'bg-white text-brand-900' }} px-4 py-3.5 text-sm font-semibold active:scale-[0.98] transition">
                        Returning to office
                    </button>
                </form>
            </div>
        @endif
    </section>

    {{-- 2. Footage --}}
    <section class="rounded-2xl bg-white/[0.04] p-4 ring-1 ring-white/10">
        <div class="flex items-center gap-2.5">
            <span class="{{ $tick }} {{ $shoot->hasFootageFiled() ? 'bg-emerald-400 text-brand-900' : 'ring-2 ring-white/25' }}">
                @if ($shoot->hasFootageFiled()) <x-icon name="check" class="h-3.5 w-3.5" /> @endif
            </span>
            <h3 class="text-sm font-semibold">Footage on the drive</h3>
        </div>

        @if ($shoot->hasFootageFiled())
            <p class="mt-2 text-sm text-white/60">
                {{ $videos->count() }} {{ Str::plural('video', $videos->count()) }} on <span class="font-medium text-white/80">{{ $shoot->footage_drive }}</span>
                · {{ $shoot->footage_filed_at->format('g:i A') }}
                @if ($shoot->footage_url) · <a href="{{ $shoot->footage_url }}" target="_blank" rel="noopener" class="text-sky-300 underline underline-offset-4">Open folder</a> @endif
            </p>
        @else
            <p class="mt-1.5 text-xs text-white/45">Split the videos into their own folders, copy them, then tick each one.</p>
            <form method="POST" action="{{ route('my.shoots.footage', $shoot) }}" class="mt-3 space-y-3"
                  x-data="{ all: false }">
                @csrf
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($drives as $drive)
                        <label class="cursor-pointer">
                            <input type="radio" name="drive" value="{{ $drive }}" class="peer sr-only" required @checked(old('drive') === $drive)>
                            <span class="block rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-white/15 text-white/70 peer-checked:bg-white peer-checked:text-brand-900 peer-checked:ring-white">{{ $drive }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('drive')" />

                <input type="url" name="url" value="{{ old('url') }}" placeholder="Folder link (optional)" inputmode="url"
                       class="w-full rounded-xl border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:ring-2 focus:ring-white/40">
                <x-input-error :messages="$errors->get('url')" />

                @if ($videos->isNotEmpty())
                    <ul class="space-y-1">
                        @foreach ($videos as $video)
                            <li>
                                <label class="flex items-center gap-3 rounded-lg bg-white/5 px-3 py-2.5">
                                    <input type="checkbox" name="videos[]" value="{{ $video->id }}" :checked="all || {{ in_array($video->id, (array) old('videos', [])) ? 'true' : 'false' }}"
                                           class="h-5 w-5 rounded border-white/30 bg-transparent text-emerald-400 focus:ring-emerald-400">
                                    <span class="min-w-0 flex-1 truncate text-sm">{{ $video->position }}. {{ $video->name }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" @click="all = true" class="text-xs text-white/50 underline underline-offset-4">Tick all {{ $videos->count() }}</button>
                @endif

                <button type="submit" class="w-full rounded-xl bg-white px-4 py-3 text-sm font-semibold text-brand-900 active:scale-[0.98] transition">
                    Footage is on the drive
                </button>
            </form>
        @endif
    </section>

    {{-- 2b. Which planner reel each clip is, so every video is followed through editing. --}}
    @if (($reels ?? collect())->isNotEmpty() && $videos->isNotEmpty())
        @php $unmatched = $videos->reject(fn ($v) => isset($clipReel[$v->id]))->count(); @endphp
        <section class="rounded-2xl bg-white/[0.04] p-4 ring-1 ring-white/10">
            <div class="flex items-center gap-2.5">
                <span class="{{ $tick }} {{ $unmatched === 0 ? 'bg-emerald-400 text-brand-900' : 'ring-2 ring-white/25' }}">
                    @if ($unmatched === 0) <x-icon name="check" class="h-3.5 w-3.5" /> @endif
                </span>
                <h3 class="text-sm font-semibold">Videos in the planner</h3>
                <span class="ml-auto text-xs text-white/45">{{ $videos->count() - $unmatched }} of {{ $videos->count() }}</span>
            </div>
            <p class="mt-1.5 text-xs text-white/45">Pick the planner video each one is, so its editing can be followed.</p>
            <ul class="mt-3 space-y-1.5">
                @foreach ($videos as $video)
                    <li>
                        <form method="POST" action="{{ route('my.shoots.videos.match', [$shoot, $video]) }}" class="flex items-center gap-2 rounded-lg bg-white/5 px-3 py-2">
                            @csrf
                            <span class="min-w-0 flex-1 truncate text-sm">{{ $video->position }}. {{ $video->name }}</span>
                            <select name="content_item_id" onchange="this.form.submit()"
                                    class="w-40 shrink-0 rounded-lg border-0 bg-white/10 py-1.5 pl-2.5 pr-7 text-xs text-white focus:ring-2 focus:ring-white/40">
                                <option value="" class="text-black">Not in planner</option>
                                @foreach ($reels as $reel)
                                    <option value="{{ $reel->id }}" class="text-black" @selected(($clipReel[$video->id] ?? null) === $reel->id)>{{ Str::limit($reel->title, 40) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @endunless

    {{-- 3. Kit back --}}
    @if ($kit->isNotEmpty())
        <section class="rounded-2xl bg-white/[0.04] p-4 ring-1 ring-white/10">
            <div class="flex items-center gap-2.5">
                <span class="{{ $tick }} {{ $kitDone ? 'bg-emerald-400 text-brand-900' : 'ring-2 ring-white/25' }}">
                    @if ($kitDone) <x-icon name="check" class="h-3.5 w-3.5" /> @endif
                </span>
                <h3 class="text-sm font-semibold">Kit returned</h3>
                <span class="ml-auto text-xs text-white/45">{{ $kit->count() - $kitOut->count() }} of {{ $kit->count() }}</span>
            </div>

            @if ($kitOut->isNotEmpty())
                <ul class="mt-3 space-y-1.5">
                    @foreach ($kitOut as $line)
                        <li class="rounded-xl bg-white/5 p-2.5" x-data="{ problem: false }">
                            <div class="flex items-center gap-3">
                                @include('my.shoots._kit-thumb', ['item' => $line->item, 'small' => true])
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $line->item?->name }}@if ($line->quantity > 1) <span class="text-white/50">×{{ $line->quantity }}</span>@endif</span>
                                <button type="button" @click="problem = !problem" class="shrink-0 rounded-lg px-2 py-1.5 text-xs text-amber-300/80 hover:bg-white/5">Problem?</button>
                                <form method="POST" action="{{ route('my.shoots.kit.return', $shoot) }}">
                                    @csrf <input type="hidden" name="kit_id" value="{{ $line->id }}">
                                    <button type="submit" class="shrink-0 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold">Back</button>
                                </form>
                            </div>
                            <form x-show="problem" x-cloak method="POST" action="{{ route('my.shoots.kit.return', $shoot) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                @csrf <input type="hidden" name="kit_id" value="{{ $line->id }}">
                                <select name="condition" required class="rounded-lg border-0 bg-white/10 py-1.5 pl-2.5 pr-8 text-xs text-white focus:ring-2 focus:ring-amber-400">
                                    @foreach (\App\Models\ShootKit::CONDITIONS as $value => $label)
                                        <option value="{{ $value }}" class="text-black">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="condition_note" maxlength="1000" placeholder="What happened?"
                                       class="min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-2.5 py-1.5 text-xs text-white placeholder-white/30 focus:ring-2 focus:ring-amber-400">
                                <button type="submit" class="rounded-lg bg-amber-400 px-3 py-1.5 text-xs font-semibold text-brand-900">Report</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('my.shoots.kit.return', $shoot) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="w-full rounded-xl bg-white px-4 py-3 text-sm font-semibold text-brand-900 active:scale-[0.98] transition">
                        Everything is back, in good order
                    </button>
                </form>
            @elseif ($shoot->hasKitProblems())
                <p class="mt-2 text-sm text-amber-200">
                    Reported: {{ $kit->filter(fn ($l) => $l->condition)->map(fn ($l) => $l->item?->name.' ('.Str::lower($l->conditionLabel()).')')->implode(', ') }}
                </p>
            @endif
        </section>
    @endif
</div>
