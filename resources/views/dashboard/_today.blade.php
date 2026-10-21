{{--
    The top of the admin dashboard, as designed: a greeting, what needs you
    (DashboardController's action items), then the week's shoots, who is
    editing, what is due on the planner today and late money (TodayBoard).
--}}
@php
    $t = \App\Support\TodayBoard::data();
    $hour = (int) now()->format('G');
    $hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $first = strtok(auth()->user()->name, ' ');
    $needs = collect($actionItems);
    $tone = fn ($t) => match ($t) {
        'red' => 'bg-red-500/[0.08] border-red-400/30',
        'amber' => 'bg-amber-400/[0.06] border-amber-400/25',
        default => 'bg-[#15303f] border-white/[0.06]',
    };
    $cta = fn ($t) => $t === 'red' ? 'bg-red-400 text-[#2a0b0b] hover:bg-red-300' : 'bg-white/10 text-white hover:bg-white/15';
    $panel = 'flex flex-col rounded-2xl border border-white/[0.06] bg-[#15303f] p-5';
    $row = 'flex items-center justify-between gap-3 border-t border-white/[0.05] py-2.5 text-[13.5px]';
    $rupees = fn ($v) => \App\Support\ClientHub::rupees((float) $v);
@endphp

<div class="space-y-8">
    {{-- Greeting --}}
    <div class="animate-rise-in flex flex-wrap items-end justify-between gap-5">
        <div>
            <p class="text-sm text-brand-100/55">{{ now()->format('l j F') }}</p>
            <h1 class="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ $hello }}, {{ $first }}</h1>
            <p class="mt-1.5 text-[15px] text-brand-100/70">
                {{ $needs->count() ? $needs->count().' '.Str::plural('thing', $needs->count()).' need you' : 'Nothing needs you right now' }}.
                {{ $t['shootsToday'] ? $t['shootsToday'].' '.Str::plural('shoot', $t['shootsToday']).' today.' : 'No shoots today.' }}
                {{ $t['due']->count() }} {{ Str::plural('video', $t['due']->count()) }} due on the planner.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" @click="$dispatch('open-modal', 'customize-dashboard')"
                    class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl border border-white/15 px-4 text-sm font-semibold text-brand-100/80 transition hover:bg-white/[0.09]">
                <x-icon name="cog" class="h-4 w-4" /> Customise
            </button>
            <a href="{{ route('invoices.create') }}" class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl bg-brand-400 px-5 text-sm font-bold text-brand-900 transition hover:bg-brand-300">
                <x-icon name="plus" class="h-4 w-4" /> New invoice
            </a>
        </div>
    </div>

    {{-- Needs you --}}
    <section aria-labelledby="needs-you">
        <h2 id="needs-you" class="mb-3 text-[17px] font-semibold text-white">{{ $needs->count() ? 'Needs you' : 'All clear' }}</h2>
        <div class="space-y-2.5">
            @forelse ($needs as $n)
                <a href="{{ $n['href'] }}" class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-2xl border px-5 py-4 transition hover:brightness-110 {{ $tone($n['tone'] ?? 'brand') }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-semibold text-white">{{ $n['title'] }}</p>
                        @if (! empty($n['detail']))<p class="mt-0.5 text-[13px] text-brand-100/70">{{ $n['detail'] }}</p>@endif
                    </div>
                    <span class="inline-flex min-h-[40px] shrink-0 items-center rounded-xl px-4 text-[13px] font-bold {{ $cta($n['tone'] ?? 'brand') }}">{{ $n['cta'] ?? 'Open' }}</span>
                </a>
            @empty
                <p class="rounded-2xl border border-emerald-400/25 bg-emerald-400/[0.06] px-5 py-5 text-sm text-emerald-100/85">No reviews waiting, nothing overdue, nobody stuck.</p>
            @endforelse
        </div>
    </section>

    {{-- The day, in four panels --}}
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="{{ $panel }}" aria-labelledby="t-shoots">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 id="t-shoots" class="font-semibold text-white">Shoots this week</h2>
                <a href="{{ route('shoots.calendar') }}" class="text-[13px] text-brand-300 hover:text-white">All shoots</a>
            </div>
            @forelse ($t['shoots'] as $s)
                <a href="{{ route('shoots.show', $s) }}" class="flex items-center gap-3.5 border-t border-white/[0.05] py-2.5 hover:bg-white/[0.02]">
                    <span class="flex w-12 shrink-0 flex-col items-center rounded-xl py-1 {{ $s->starts_at->isToday() ? 'bg-brand-400/20' : 'bg-white/[0.06]' }}">
                        <span class="text-[10px] font-bold uppercase {{ $s->starts_at->isToday() ? 'text-brand-200' : 'text-brand-100/55' }}">{{ $s->starts_at->format('D') }}</span>
                        <span class="text-lg font-extrabold leading-tight text-white">{{ $s->starts_at->format('j') }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-white">{{ trim($s->title) }}</span>
                        <span class="block truncate text-xs text-brand-100/60">{{ $s->client?->name ?? 'No client' }}{{ $s->starts_at->isToday() ? ' · today' : '' }}{{ $s->isInProgress() ? ' · rolling now' : '' }}</span>
                    </span>
                </a>
            @empty
                <p class="border-t border-white/[0.05] py-5 text-sm text-brand-100/50">No shoots in the next 7 days. <a href="{{ route('forecast.index') }}" class="text-brand-300 hover:text-white">See who needs one →</a></p>
            @endforelse
        </section>

        <section class="{{ $panel }}" aria-labelledby="t-editing">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 id="t-editing" class="font-semibold text-white">Editing now</h2>
                <a href="{{ route('editor-updates') }}" class="text-[13px] text-brand-300 hover:text-white">Editor Updates</a>
            </div>
            @forelse ($t['editors'] as $e)
                @php
                    [$word, $pill] = match ($e['state']) {
                        'working' => ['Editing', 'bg-amber-400/15 text-amber-200'],
                        'hold' => ['On hold', 'bg-violet-400/15 text-violet-200'],
                        default => [$e['last'] ? 'Idle' : 'Not started', 'bg-white/10 text-brand-100/75'],
                    };
                @endphp
                <a href="{{ $e['user_id'] ? route('editor-updates.editor', $e['user_id']) : route('editor-updates') }}" class="flex items-center gap-3 border-t border-white/[0.05] py-2.5 hover:bg-white/[0.02]">
                    <x-avatar :name="$e['person']" :src="$e['photo']" size="md" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-white">{{ $e['person'] }}</span>
                        <span class="block truncate text-xs text-brand-100/60">{{ $e['state'] === 'working' ? $e['title'] : ($e['holding'][0]['title'] ?? ($e['next'][0] ?? 'Nothing on the board')) }}</span>
                    </span>
                    <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $pill }}">{{ $word }}</span>
                </a>
            @empty
                <p class="border-t border-white/[0.05] py-5 text-sm text-brand-100/50">Nobody has used My Editing in the last two weeks.</p>
            @endforelse
            <div class="mt-auto grid grid-cols-3 gap-2 pt-3">
                @foreach ([['review', 'in review'], ['ready', 'ready'], ['out', 'out today']] as [$k, $label])
                    <div class="rounded-xl bg-[#0f2230] px-3 py-2.5"><p class="text-xl font-extrabold text-white">{{ $t['flow'][$k] ?? 0 }}</p><p class="text-[11px] text-brand-100/60">{{ $label }}</p></div>
                @endforeach
            </div>
        </section>

        <section class="{{ $panel }}" aria-labelledby="t-due">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 id="t-due" class="font-semibold text-white">Due on the planner today</h2>
                @if (Route::has('planner.index'))<a href="{{ route('planner.index') }}" class="text-[13px] text-brand-300 hover:text-white">Planner</a>@endif
            </div>
            @forelse ($t['due']->take(5) as $c)
                <div class="{{ $row }}">
                    <span class="min-w-0 truncate text-white">{{ $c->title ?: 'Untitled' }}</span>
                    <span class="shrink-0 text-xs {{ in_array($c->status, ['To Be Edited', 'To Be Shooted'], true) ? 'text-amber-200' : 'text-brand-300' }}">{{ $c->status }}</span>
                </div>
            @empty
                <p class="border-t border-white/[0.05] py-5 text-sm text-emerald-200/80">Nothing left to publish today.</p>
            @endforelse
            @if ($t['due']->count() > 5)
                <a href="{{ route('planner.index') }}" class="pt-2 text-[13px] text-brand-300 hover:text-white">+ {{ $t['due']->count() - 5 }} more</a>
            @endif
        </section>

        <section class="{{ $panel }}" aria-labelledby="t-money">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 id="t-money" class="font-semibold text-white">Money due{{ $t['lateCount'] ? ' · '.$rupees($t['lateTotal']).' late' : '' }}</h2>
                <a href="{{ route('invoices.index') }}" class="text-[13px] text-brand-300 hover:text-white">Invoices</a>
            </div>
            @forelse ($t['late'] as $inv)
                @php $days = (int) $inv->due_date->diffInDays(today()); @endphp
                <a href="{{ route('invoices.show', $inv) }}" class="{{ $row }} hover:bg-white/[0.02]">
                    <span class="min-w-0 truncate text-white">{{ $inv->client?->name ?? 'Client' }} · {{ $inv->invoice_number ?? 'Pending' }}</span>
                    <span class="shrink-0 text-xs {{ $days > 7 ? 'text-red-300' : 'text-amber-200' }}">{{ $rupees($inv->balanceDue()) }} · {{ $days }} {{ Str::plural('day', $days) }} late</span>
                </a>
            @empty
                <p class="border-t border-white/[0.05] py-5 text-sm text-emerald-200/80">Nothing overdue.</p>
            @endforelse
            @if ($t['lateCount'] > 4)
                <a href="{{ route('invoices.index') }}" class="pt-2 text-[13px] text-brand-300 hover:text-white">+ {{ $t['lateCount'] - 4 }} more</a>
            @endif
        </section>
    </div>
</div>
