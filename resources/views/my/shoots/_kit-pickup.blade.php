{{--
    Step 0 of the shoot day: the kit. Nothing leaves until every line is
    ticked (ShootRun::requireKit). Each row is one big tap; the crew can add
    what the producer forgot, because they are the ones loading the bag.
--}}
@php
    $picked = $kit->whereNotNull('checked_out_at')->count();
    $locked = $shoot->hasDeparted() || $shoot->hasReached();
@endphp
<section class="w-full rounded-2xl bg-white/[0.04] p-4 text-left ring-1 ring-white/10">
    <div class="flex items-baseline justify-between">
        <h2 class="text-sm font-semibold">Kit</h2>
        <p class="text-xs {{ $kit->isNotEmpty() && $picked === $kit->count() ? 'text-emerald-300' : 'text-white/50' }}">
            @if ($kit->isEmpty()) Nothing listed
            @elseif ($picked === $kit->count()) All {{ $picked }} picked up
            @else {{ $picked }} of {{ $kit->count() }} picked up
            @endif
        </p>
    </div>

    @if ($kit->isEmpty())
        <p class="mt-2 text-sm text-amber-200/80">Every shoot needs its kit listed. Add what you are taking.</p>
    @else
        <ul class="mt-3 space-y-1.5">
            @foreach ($kit as $line)
                @php $on = $line->checked_out_at !== null; @endphp
                <li class="flex items-stretch gap-1.5">
                    <form method="POST" action="{{ route('my.shoots.kit.pick', $shoot) }}" class="min-w-0 flex-1">
                        @csrf
                        <input type="hidden" name="kit_id" value="{{ $line->id }}">
                        @if ($on) <input type="hidden" name="undo" value="1"> @endif
                        <button type="submit" @disabled($on && $locked)
                                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition active:scale-[0.99] {{ $on ? 'bg-emerald-500/10 ring-1 ring-emerald-400/30' : 'bg-white/5 ring-1 ring-white/10' }}">
                            @include('my.shoots._kit-thumb', ['item' => $line->item])
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ $line->item?->name ?? 'Removed item' }}@if ($line->quantity > 1) <span class="text-white/50">×{{ $line->quantity }}</span>@endif</span>
                                <span class="block truncate text-xs text-white/40">
                                    @if ($on) {{ $line->checkedOutBy?->name ?? 'Picked up' }} · {{ $line->checked_out_at->format('g:i A') }}
                                    @else Tap when it is in the bag
                                    @endif
                                </span>
                            </span>
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $on ? 'bg-emerald-400 text-brand-900' : 'ring-2 ring-white/25' }}">
                                @if ($on) <x-icon name="check" class="h-4 w-4" /> @endif
                            </span>
                        </button>
                    </form>
                    @if (! $on)
                        <form method="POST" action="{{ route('my.shoots.kit.remove', [$shoot, $line]) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="h-full rounded-xl px-2.5 text-white/30 hover:bg-white/5 hover:text-white/70" aria-label="Remove {{ $line->item?->name }} from the kit">✕</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($picked < $kit->count())
            <form method="POST" action="{{ route('my.shoots.kit.pick', $shoot) }}" class="mt-3">
                @csrf
                <button type="submit" class="w-full rounded-xl bg-white px-4 py-3 text-sm font-semibold text-brand-900 active:scale-[0.98] transition">
                    Picked up everything
                </button>
            </form>
        @endif
    @endif

    @if (($usualKit ?? collect())->isNotEmpty())
        {{-- What this client's last shoots actually took. --}}
        <form method="POST" action="{{ route('my.shoots.kit.add', $shoot) }}" class="mt-3 rounded-xl bg-violet-500/10 p-3 ring-1 ring-violet-400/20">
            @csrf <input type="hidden" name="usual" value="1">
            <p class="text-xs font-semibold text-violet-200">Usual kit for {{ $shoot->clientLabel() ?? 'this client' }}</p>
            <div class="mt-2 flex gap-1.5 overflow-x-auto pb-1">
                @foreach ($usualKit->take(10) as $row)
                    <span class="flex shrink-0 items-center gap-1.5 rounded-lg bg-white/5 py-1 pl-1 pr-2 text-xs text-white/80">
                        @include('my.shoots._kit-thumb', ['item' => $row['item'], 'small' => true])
                        {{ $row['item']->name }}
                    </span>
                @endforeach
            </div>
            <button type="submit" class="mt-2 w-full rounded-lg bg-violet-400 px-4 py-2.5 text-sm font-semibold text-brand-900 active:scale-[0.98] transition">
                Add all {{ $usualKit->count() }}
            </button>
        </form>
    @endif

    @if ($equipment->isNotEmpty())
        <details class="mt-3" x-data="{ q: '' }" @if ($kit->isEmpty()) open @endif>
            <summary class="cursor-pointer list-none rounded-xl bg-white/5 px-3 py-2.5 text-sm font-medium text-white/80 ring-1 ring-white/10">
                + Add kit
            </summary>
            <form method="POST" action="{{ route('my.shoots.kit.add', $shoot) }}" class="mt-2">
                @csrf
                <input type="search" x-model="q" placeholder="Search kit"
                       class="w-full rounded-xl border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:ring-2 focus:ring-white/40">
                <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                    @foreach ($equipment as $item)
                        <li x-show="!q || @js(Str::lower($item->name)).includes(q.toLowerCase())">
                            <label class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-white/5">
                                <input type="checkbox" name="items[]" value="{{ $item->id }}" class="h-5 w-5 rounded border-white/30 bg-transparent text-emerald-400 focus:ring-emerald-400">
                                @include('my.shoots._kit-thumb', ['item' => $item, 'small' => true])
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{ $item->name }}</span>
                                    @if ($item->bundle->isNotEmpty())
                                        <span class="block truncate text-[11px] text-violet-200/70">+ {{ $item->bundle->pluck('name')->implode(', ') }}</span>
                                    @endif
                                </span>
                                @if (! $item->isAvailable())
                                    <span class="shrink-0 text-xs text-amber-300">{{ $item->statusLabel() }}</span>
                                @endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                <button type="submit" class="mt-2 w-full rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white active:scale-[0.98] transition">
                    Add to this shoot
                </button>
            </form>
        </details>
    @endif
</section>
