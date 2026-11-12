{{--
    The shoot's day, in order: who did what and when, how long each stretch
    took, and where it ran late against the booking. Read from shoot_events
    (see App\Models\ShootEvent), which every screen and tool writes to.
--}}
@php
    $events = $shoot->events;
    $at = fn (string $type) => $events->firstWhere('type', $type)?->created_at;
    $span = function ($from, $to) {
        if (! $from || ! $to) return null;
        $m = (int) round($from->diffInMinutes($to, true));
        return $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    };

    $lateStart = $shoot->hasStartTime() && $shoot->started_at && $shoot->started_at->gt($shoot->starts_at)
        ? (int) round($shoot->starts_at->diffInMinutes($shoot->started_at, true)) : 0;
    $end = $shoot->expectedEnd();
    $overrun = $shoot->ends_at && $shoot->finished_at && $shoot->finished_at->gt($shoot->ends_at)
        ? (int) round($shoot->ends_at->diffInMinutes($shoot->finished_at, true)) : 0;

    $stats = array_filter([
        'Travel' => $span($shoot->departed_at, $shoot->reached_at),
        'Set-up' => $span($shoot->reached_at, $shoot->started_at),
        'Shooting' => $span($shoot->started_at, $shoot->finished_at),
        'Footage filed after' => $span($shoot->finished_at, $shoot->footage_filed_at),
    ]);
    $previousDay = null;
@endphp

@php $readiness = ! $shoot->started_at && ! $shoot->isCancelled() ? \App\Support\ShootReadiness::problems($shoot) : []; @endphp
@if (! $shoot->started_at && ! $shoot->isCancelled())
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl px-4 py-3 text-sm {{ $readiness ? 'bg-amber-400/10 ring-1 ring-amber-400/30' : 'bg-emerald-400/10 ring-1 ring-emerald-400/25' }}">
        <span class="font-semibold {{ $readiness ? 'text-amber-200' : 'text-emerald-200' }}">{{ $readiness ? 'Not ready yet:' : 'Ready to go' }}</span>
        @foreach ($readiness as $p)
            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $p['tone'] === 'red' ? 'bg-red-400/15 text-red-200' : 'bg-amber-400/15 text-amber-200' }}">{{ $p['label'] }}</span>
        @endforeach
        @if ($shoot->notionShoot && in_array('unconfirmed', array_column($readiness, 'key'), true))
            <span class="text-xs text-brand-100/50">Confirmation is read from Notion's "Client Conformation".</span>
        @endif
    </div>
@endif
<x-card class="p-4 sm:p-5">
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-sm font-semibold text-white">Shoot day timeline</h2>
        @if ($shoot->isInProgress())
            <span class="flex items-center gap-1.5 rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-medium text-red-300">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-400"></span> Rolling
            </span>
        @endif
        @if ($lateStart >= (int) config('shoots.late.start_minutes'))
            <span class="rounded-full bg-amber-400/15 px-2 py-0.5 text-xs font-medium text-amber-200">Started {{ $lateStart }}m late</span>
        @endif
        @if ($overrun >= (int) config('shoots.late.overrun_minutes'))
            <span class="rounded-full bg-amber-400/15 px-2 py-0.5 text-xs font-medium text-amber-200">Ran {{ $overrun }}m over</span>
        @endif
    </div>

    @if ($events->isEmpty())
        <p class="mt-3 text-sm text-brand-100/50">
            Nothing yet. The timeline fills in as the crew pick up the kit, leave, shoot and wrap.
        </p>
    @else
        @if ($stats)
            <dl class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($stats as $label => $value)
                    <div class="rounded-lg bg-white/5 px-3 py-2">
                        <dt class="text-[11px] uppercase tracking-wide text-brand-100/50">{{ $label }}</dt>
                        <dd class="text-sm font-semibold text-white tabular-nums">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <ol class="mt-4">
            @foreach ($events as $i => $event)
                @php
                    $day = $event->created_at->toDateString();
                    $gap = $i > 0 ? $span($events[$i - 1]->created_at, $event->created_at) : null;
                    $videoId = $event->meta['video_id'] ?? null;
                @endphp
                @if ($day !== $previousDay && ($previousDay !== null || ! $event->created_at->isSameDay($shoot->starts_at)))
                    <li class="pb-2 pl-7 text-[11px] font-semibold uppercase tracking-wide text-brand-100/40">{{ $event->created_at->format('D j M') }}</li>
                @endif
                @php $previousDay = $day; @endphp
                <li class="relative flex gap-3 pb-4 last:pb-0">
                    @unless ($loop->last)
                        <span class="absolute left-[7px] top-4 h-full w-px bg-white/10" aria-hidden="true"></span>
                    @endunless
                    <span class="relative mt-1 h-[15px] w-[15px] shrink-0 rounded-full bg-brand-900 ring-2 ring-current {{ $event->tone() }}" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                            <span class="font-medium {{ $event->tone() }}">{{ $event->label() }}</span>
                            <span class="tabular-nums text-brand-100/70">{{ $event->created_at->format('g:i A') }}</span>
                            @if ($event->user) <span class="text-brand-100/50">{{ $event->user->name }}</span> @endif
                            @if ($gap && $event->type !== \App\Models\ShootEvent::VIDEO)
                                <span class="text-xs text-brand-100/35">+{{ $gap }}</span>
                            @endif
                        </p>
                        @if ($event->note)
                            <p class="mt-0.5 text-xs text-brand-100/60">{{ $event->note }}</p>
                        @endif
                        @if ($event->type === \App\Models\ShootEvent::FOOTAGE_FILED && ! empty($event->meta['url']))
                            <a href="{{ $event->meta['url'] }}" target="_blank" rel="noopener" class="mt-0.5 inline-block text-xs text-sky-300 underline underline-offset-4">Open folder</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    {{-- What is still owed after the wrap. --}}
    @if ($shoot->hasWrapped())
        <div class="mt-4 flex flex-wrap gap-2 border-t border-white/10 pt-3 text-xs">
            <span class="rounded-full px-2.5 py-1 {{ $shoot->hasFootageFiled() ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-400/15 text-amber-200' }}">
                {{ $shoot->hasFootageFiled() ? 'Footage on '.$shoot->footage_drive : 'Footage not on a drive yet' }}
            </span>
            @if ($shoot->kits->isNotEmpty())
                <span class="rounded-full px-2.5 py-1 {{ $shoot->hasKitOut() ? 'bg-amber-400/15 text-amber-200' : 'bg-emerald-500/15 text-emerald-200' }}">
                    {{ $shoot->hasKitOut() ? 'Kit still out' : 'Kit back' }}
                </span>
            @endif
            @foreach ($shoot->crew as $member)
                @if ($member->after_wrap === \App\Models\ShootCrew::AFTER_NEXT_SHOOT)
                    <span class="rounded-full bg-sky-500/15 px-2.5 py-1 text-sky-200">{{ $member->user?->name }} → {{ $member->nextShoot?->title ?? 'next shoot' }}</span>
                @elseif ($member->back_at_office_at)
                    <span class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-emerald-200">{{ $member->user?->name }} back {{ $member->back_at_office_at->format('g:i A') }}</span>
                @elseif ($member->returning_at)
                    <span class="rounded-full bg-sky-500/15 px-2.5 py-1 text-sky-200">{{ $member->user?->name }} returning since {{ $member->returning_at->format('g:i A') }}</span>
                @endif
            @endforeach
        </div>
    @endif

    @if ($shoot->isCancelled() && $shoot->cancel_reason)
        <p class="mt-3 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-200">Cancelled: {{ $shoot->cancel_reason }}</p>
    @endif

    {{-- Admin only: let it leave without the kit check, or call it off. --}}
    @if (auth()->user()->isAdmin() && ! $shoot->hasWrapped() && ! $shoot->isCancelled())
        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-white/10 pt-3" x-data="{ open: null }">
            @if (config('shoots.require_kit') && ! $shoot->hasKitWaived() && ! $shoot->isPacked() && ! $shoot->started_at)
                <button type="button" @click="open = open === 'waive' ? null : 'waive'" class="text-xs text-brand-100/60 underline underline-offset-4 hover:text-white">Let it leave without kit check</button>
            @endif
            <button type="button" @click="open = open === 'cancel' ? null : 'cancel'" class="text-xs text-red-300/80 underline underline-offset-4 hover:text-red-200">Cancel shoot</button>

            <form x-show="open === 'waive'" x-cloak method="POST" action="{{ route('shoots.kit.waive', $shoot) }}" class="flex w-full gap-2">
                @csrf
                <input type="text" name="reason" required maxlength="500" placeholder="Why no kit check? (phone-only shoot, kit already on site…)"
                       class="min-w-0 flex-1 rounded-lg border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30">
                <x-btn type="submit" variant="secondary">Allow</x-btn>
            </form>
            <form x-show="open === 'cancel'" x-cloak method="POST" action="{{ route('shoots.cancel', $shoot) }}" class="flex w-full gap-2">
                @csrf
                <input type="text" name="reason" required maxlength="500" placeholder="Why is it cancelled?"
                       class="min-w-0 flex-1 rounded-lg border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30">
                <x-btn type="submit" variant="danger">Cancel shoot</x-btn>
            </form>
        </div>
    @endif
</x-card>
