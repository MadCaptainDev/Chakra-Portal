{{--
    Shooting together: same place, same time, another brand (SVA Mart and
    SVA Silks). One trip, one kit list, both run at once. ShootController::together.
--}}
@php
    $partners = $shoot->partners();
    $sameDay = $shoot->starts_at
        ? \App\Models\Shoot::query()->whereKeyNot($shoot->id)->whereDate('starts_at', $shoot->starts_at->toDateString())
            ->where('status', '!=', \App\Models\Shoot::STATUS_CANCELLED)->whereNotIn('id', $partners->pluck('id'))
            ->with('client')->orderBy('starts_at')->get()
        : collect();
    $split = $partners->isNotEmpty() ? $shoot->focusMinutes() : [];
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
@endphp
@if ($partners->isNotEmpty() || ($sameDay->isNotEmpty() && auth()->user()->can('shoots.edit')))
    <x-card class="p-4 sm:p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-white">🔗 Shooting together</p>
                <p class="mt-0.5 text-xs text-brand-100/55">Same place, same time, another brand: one trip, one kit list, and the crew run both at once — switching with “Now shooting” so the hours split by brand.</p>
            </div>
        </div>

        @if ($partners->isNotEmpty())
            <ul class="mt-3 space-y-1.5">
                @foreach ($shoot->togetherGroup() as $s)
                    <li class="flex items-center justify-between gap-3 rounded-lg bg-white/5 px-3 py-2 text-sm">
                        <span class="min-w-0 truncate">
                            @if ($s->id === $shoot->id)
                                <span class="font-semibold text-white">{{ $s->title }}</span> <span class="text-xs text-brand-100/45">· this one</span>
                            @else
                                <a href="{{ route('shoots.show', $s) }}" class="font-semibold text-white hover:underline">{{ $s->title }}</a>
                            @endif
                            <span class="text-xs text-brand-100/50">· {{ $s->starts_at?->format('g:i A') }}{{ $s->kits()->exists() ? ' · has the kit list' : '' }}</span>
                        </span>
                        @if (isset($split[$s->id]) && $split[$s->id] > 0)
                            <span class="shrink-0 rounded-full bg-pink-400/15 px-2 py-0.5 text-xs font-semibold text-pink-200">{{ $hm($split[$s->id]) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @can('shoots.edit')
                <form method="POST" action="{{ route('shoots.untogether', $shoot) }}" class="mt-2" onsubmit="return confirm('Unlink this shoot? It goes back to its own trip and kit.')">
                    @csrf @method('DELETE')
                    <button class="text-xs text-brand-100/50 hover:text-red-300">Unlink this shoot</button>
                </form>
            @endcan
        @endif

        @can('shoots.edit')
            @if ($sameDay->isNotEmpty())
                <form method="POST" action="{{ route('shoots.together', $shoot) }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf
                    <select name="partner_id" required class="h-10 min-w-0 flex-1 rounded-lg border-0 bg-white/10 px-3 text-sm text-white focus:ring-2 focus:ring-pink-400">
                        <option value="">Link with another shoot that day…</option>
                        @foreach ($sameDay as $o)
                            <option value="{{ $o->id }}">{{ $o->starts_at?->format('g:i A') }} · {{ $o->title }}{{ $o->location ? ' · '.$o->location : '' }}</option>
                        @endforeach
                    </select>
                    <button class="h-10 rounded-lg bg-pink-500 px-4 text-sm font-bold text-white hover:bg-pink-400">Link</button>
                </form>
            @endif
        @endcan
    </x-card>
@endif
