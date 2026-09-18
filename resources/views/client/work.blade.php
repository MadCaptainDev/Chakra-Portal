<x-app-layout title="Work delivered" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Work Delivered</h1>
            <p class="mt-2 text-sm text-brand-100/70">Everything published for you, newest first.</p>
        </div>

        {{-- One tab per type this client actually has, plus All. Hidden when
             there is only one type: a strip reading "All / Reels" over a list
             of nothing but reels is a control that does nothing. --}}
        @if ($tabs->count() > 1)
            <div class="-mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto">
                <div class="flex items-center gap-2 w-max">
                    @php $isAll = $type === 'all'; @endphp
                    <a href="{{ route('client.work') }}"
                       @class([
                           'shrink-0 inline-flex items-center gap-1.5 min-h-[36px] px-3.5 rounded-full text-xs font-semibold transition-colors',
                           'bg-brand-400/20 text-brand-100 ring-1 ring-brand-300/40' => $isAll,
                           'bg-white/5 text-brand-100/70 ring-1 ring-white/10 hover:bg-white/10' => ! $isAll,
                       ])>
                        All
                    </a>

                    @foreach ($tabs as $source => $tab)
                        @php $active = $type === $source; @endphp
                        <a href="{{ route('client.work', ['type' => $source]) }}"
                           @class([
                               'shrink-0 inline-flex items-center gap-1.5 min-h-[36px] px-3.5 rounded-full text-xs font-semibold transition-colors',
                               'bg-brand-400/20 text-brand-100 ring-1 ring-brand-300/40' => $active,
                               'bg-white/5 text-brand-100/70 ring-1 ring-white/10 hover:bg-white/10' => ! $active,
                           ])>
                            <x-brand-icon :name="$tab['platform']" class="w-4 h-4 shrink-0" />
                            {{ $tab['label'] }}
                            <span class="tabular-nums text-brand-100/60">{{ $tab['count'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3.5">
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ number_format($total) }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">{{ $countLabel }}</p>
            </div>
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $thisMonth }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">This month</p>
            </div>
        </div>

        {{-- Said once, under the tiles, because the per-type counts add up to
             more than the total and a client is owed the reason why. --}}
        @if ($type === 'all' && $tabs->count() > 1)
            <p class="-mt-3 text-xs text-brand-100/50">
                One piece counted once, however many places it went out.
            </p>
        @endif

        @forelse ($months as $month)
            <section>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">{{ $month['label'] }}</p>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($month['items'] as $piece)
                        <div class="p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <div class="flex items-start gap-3.5">
                                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-400/15">
                                    <x-brand-icon :name="head($piece['channels'])['platform']" class="w-5 h-5" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="font-medium truncate">{{ $piece['title'] }}</p>
                                    <p class="mt-0.5 text-xs text-brand-100/60">
                                        {{ $piece['date']->format('D j M') }}
                                        @if ($piece['note']) &middot; {{ $piece['note'] }} @endif
                                    </p>

                                    {{-- Where it went. A chip is a link when that
                                         destination has a page to open, so the
                                         YouTube cut is reachable and not just
                                         mentioned. --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        @foreach ($piece['channels'] as $channel)
                                            @if ($channel['url'])
                                                <a href="{{ $channel['url'] }}" target="_blank" rel="noopener noreferrer"
                                                   class="inline-flex items-center gap-1 min-h-[28px] px-2.5 rounded-full bg-white/10 ring-1 ring-white/15
                                                          text-[10px] font-semibold uppercase tracking-wide hover:bg-white/20 transition-colors">
                                                    <x-brand-icon :name="$channel['platform']" class="w-3.5 h-3.5 shrink-0" />
                                                    {{ $channel['label'] }}
                                                </a>
                                            @else
                                                <span class="inline-flex items-center gap-1 min-h-[28px] px-2.5 rounded-full bg-white/5 ring-1 ring-white/10
                                                             text-[10px] font-semibold uppercase tracking-wide text-brand-100/70">
                                                    <x-brand-icon :name="$channel['platform']" class="w-3.5 h-3.5 shrink-0" />
                                                    {{ $channel['label'] }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-14 text-center">
                <p class="text-sm text-brand-100/70">Nothing published yet.</p>
                <p class="mt-1 text-xs text-brand-100/50">Work appears here once it goes live.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
