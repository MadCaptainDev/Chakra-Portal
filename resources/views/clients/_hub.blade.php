{{--
    The client at a glance (ClientHub): reels left and cover, this month
    against target, videos in the works, shoots and money owed.
--}}
@php
    use App\Support\ClientHub;
    $f = $hub['forecast'];
    $when = fn ($d) => $d === null ? '—' : ($d->isToday() ? 'today' : ($d->isTomorrow() ? 'tomorrow' : $d->format('D j M')));
    $cover = match ($f['status']) {
        'ok' => ['Covered'.($f['next_shoot_date'] ? ' · shoot '.$when($f['next_shoot_date']) : ''), 'text-emerald-300'],
        'critical' => ['Book a shoot now'.($f['book_by_date'] ? ' · by '.$when($f['book_by_date']) : ''), 'text-red-300'],
        'warning' => ['Book soon · runs out '.$when($f['depletion_date']), 'text-amber-200'],
        default => [$f['linked'] ? 'No monthly reel target' : 'Not connected to Notion', 'text-brand-100/55'],
    };
    $pct = $hub['month']['target'] > 0 ? min(100, round($hub['month']['out'] / $hub['month']['target'] * 100)) : null;
    $tile = 'flex flex-col gap-1 rounded-2xl border border-white/[0.06] bg-[#15303f] p-4';
    $panel = 'rounded-2xl border border-white/[0.06] bg-[#15303f] p-4 sm:p-5';
    $row = 'flex items-baseline justify-between gap-3 border-t border-white/[0.05] py-2.5 text-[13.5px]';
@endphp

<div class="space-y-5">
    {{-- Four numbers --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="{{ $tile }}">
            <span class="text-[13px] text-brand-100/65">Reels left to post</span>
            <span class="text-[26px] font-bold text-white">{{ $f['remaining'] }}</span>
            <span class="text-xs {{ $cover[1] }}">{{ $cover[0] }}</span>
        </div>
        <div class="{{ $tile }}">
            <span class="text-[13px] text-brand-100/65">Reels out this month</span>
            <span class="text-[26px] font-bold text-white">{{ $hub['month']['out'] }}@if ($hub['month']['target'])<span class="text-[15px] font-medium text-brand-100/55"> / {{ $hub['month']['target'] }}</span>@endif</span>
            @if ($pct !== null)
                <span class="mt-1 h-1.5 rounded-full bg-white/10"><span class="block h-1.5 rounded-full bg-brand-400" style="width: {{ $pct }}%"></span></span>
            @endif
        </div>
        <div class="{{ $tile }}">
            <span class="text-[13px] text-brand-100/65">In the works</span>
            <span class="text-[26px] font-bold text-white">{{ $hub['inWorksCount'] }}</span>
            <span class="text-xs text-brand-100/55">to shoot, edit or review</span>
        </div>
        @if (auth()->user()->isAdmin())
            <div class="{{ $tile }} {{ $hub['unpaidTotal'] > 0 ? '!border-red-400/30 !bg-red-500/[0.07]' : '' }}">
                <span class="text-[13px] {{ $hub['unpaidTotal'] > 0 ? 'text-red-200' : 'text-brand-100/65' }}">Unpaid</span>
                <span class="text-[26px] font-bold text-white">{{ ClientHub::rupees($hub['unpaidTotal']) }}</span>
                <span class="text-xs text-brand-100/55">{{ $hub['unpaid']->count() }} {{ Str::plural('invoice', $hub['unpaid']->count()) }}</span>
            </div>
        @else
            <div class="{{ $tile }}">
                <span class="text-[13px] text-brand-100/65">Shoots in the last 3 weeks</span>
                <span class="text-[26px] font-bold text-white">{{ $hub['shoots']->count() }}</span>
            </div>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Videos in the works --}}
        <section class="{{ $panel }}">
            <div class="mb-1 flex items-baseline justify-between">
                <h3 class="font-semibold text-white">Videos in the works</h3>
                @if (Route::has('planner.index') && auth()->user()->can('planner.view'))
                    <a href="{{ route('planner.index') }}" class="text-[13px] text-brand-300 hover:text-white">Planner</a>
                @endif
            </div>
            @forelse ($hub['inWorks'] as $item)
                <div class="{{ $row }}">
                    <span class="min-w-0 truncate text-white">{{ $item->title ?: 'Untitled' }}</span>
                    <span class="shrink-0 text-xs text-amber-200">{{ $item->status }}{{ $item->published_date ? ' · '.$when($item->published_date) : '' }}</span>
                </div>
            @empty
                <p class="border-t border-white/[0.05] py-4 text-sm text-brand-100/50">{{ $hub['ventures'] ? 'Nothing being made right now.' : 'No Notion venture is connected to this client\'s accounts, so their videos cannot be shown.' }}</p>
            @endforelse
        </section>

        {{-- Accounts this month --}}
        <section class="{{ $panel }}">
            <h3 class="mb-1 font-semibold text-white">Reels this month, by account</h3>
            @forelse ($hub['accounts'] as $a)
                <div class="{{ $row }}">
                    <span class="text-white">{{ $a['name'] }}</span>
                    <span class="{{ $a['target'] && $a['out'] >= $a['target'] ? 'text-emerald-300' : 'text-brand-100/75' }}">{{ $a['out'] }}{{ $a['target'] ? ' / '.$a['target'] : ' · no target' }}</span>
                </div>
            @empty
                <p class="border-t border-white/[0.05] py-4 text-sm text-brand-100/50">No content accounts yet.</p>
            @endforelse
        </section>

        {{-- Shoots --}}
        <section class="{{ $panel }}">
            <div class="mb-1 flex items-baseline justify-between">
                <h3 class="font-semibold text-white">Shoots</h3>
                @if (Route::has('shoots.create') && auth()->user()->can('shoots.create'))
                    <a href="{{ route('shoots.create', ['client_id' => $client->id]) }}" class="text-[13px] text-brand-300 hover:text-white">+ Book shoot</a>
                @endif
            </div>
            @forelse ($hub['shoots'] as $s)
                <a href="{{ route('shoots.show', $s) }}" class="{{ $row }} hover:bg-white/[0.02]">
                    <span class="min-w-0 truncate text-white">{{ trim($s->title) }}</span>
                    <span class="shrink-0 text-xs {{ $s->starts_at?->isFuture() || $s->starts_at?->isToday() ? 'text-brand-300' : 'text-brand-100/60' }}">
                        {{ $when($s->starts_at) }} · {{ $s->status === 'completed' ? $s->videos_count.' '.Str::plural('video', $s->videos_count) : strtolower(\App\Models\Shoot::STATUSES[$s->status] ?? $s->status) }}
                    </span>
                </a>
            @empty
                <p class="border-t border-white/[0.05] py-4 text-sm text-brand-100/50">No shoots in the last three weeks, none booked.</p>
            @endforelse
        </section>

        {{-- Money owed --}}
        @if (auth()->user()->isAdmin())
            <section class="{{ $panel }}">
                <div class="mb-1 flex items-baseline justify-between">
                    <h3 class="font-semibold text-white">Unpaid invoices</h3>
                    <button type="button" @click="tab = 'money'" class="text-[13px] text-brand-300 hover:text-white">All invoices</button>
                </div>
                @forelse ($hub['unpaid'] as $inv)
                    <a href="{{ route('invoices.show', $inv) }}" class="{{ $row }} hover:bg-white/[0.02]">
                        <span class="text-white">{{ $inv->invoice_number ?? 'Pending' }} · {{ $inv->invoice_date->format('j M') }}</span>
                        <span class="text-red-300">{{ ClientHub::rupees($inv->balanceDue()) }}</span>
                    </a>
                @empty
                    <p class="border-t border-white/[0.05] py-4 text-sm text-emerald-300/80">All paid up.</p>
                @endforelse
            </section>
        @endif
    </div>
</div>
