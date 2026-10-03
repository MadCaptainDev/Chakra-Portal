{{--
    How we work: where the studio's hours go, stage by stage (hoursPipeline
    in resources/js/home-studio.js), drawn from App\Support\StudioNumbers.

    Five stages -- Research, Shoot, Edit, Post, Track -- each with a small
    widget to play with: the shoot calendar, the edit by month, what gets
    published, and one reel's growth curve, which a finger or pointer can
    run along. It steps through the stages on its own until someone takes
    over.

    Real data, shown as its shape only: StudioNumbers::get() hands this page
    percentages, levels and multiples, never hours, counts or view totals.

    Expects: $studio (StudioNumbers::get()).
--}}
@php
    $share = collect($studio['split'])->keyBy('stage');
    $editShare = $share[2]['pct'] ?? null;
    $shootShare = $share[1]['pct'] ?? null;
    $growth = $studio['growth'];

    $stages = [
        ['Research', 'Script first', 'Angles, hooks and a written, approved script before anyone books a camera.',
            'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 3-4.3-4.3'],
        ['Shoot', $shootShare ? $shootShare.'% of our hours' : 'Planned days', 'Planned shoot days against the script, so nothing is discovered on set.',
            'M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1zm8 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
        ['Edit', $editShare ? $editShare.'% of our hours' : 'Cut and colour', 'Cut, colour, captions and sound, with your revisions before it ships.',
            'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.1 7.9 20 18M8.1 16.1 20 6'],
        ['Post', 'Every week', 'Scheduled, posted, and the comments and DMs answered after.',
            'M22 3 11 14M22 3l-7 19-4-8-8-4z'],
        ['Track', 'Synced daily', 'Every account we run is synced daily and reported back to you.',
            'M3 3v18h18M7 15l4-4 3 3 6-7'],
    ];

    $colours = [1 => 'bg-brand-300', 2 => 'bg-brand-400', 3 => 'bg-violet-400', 4 => 'bg-emerald-400'];

    $heat = [-1 => 'bg-transparent', 0 => 'bg-white/[0.05]', 1 => 'bg-brand-700', 2 => 'bg-brand-500', 3 => 'bg-brand-400', 4 => 'bg-brand-200'];
    $dayLabel = [0 => 'no shoot', 1 => 'a short shoot', 2 => 'a half day on set', 3 => 'a full day on set', 4 => 'a long day on set'];
@endphp

@push('styles')
    <style>
        @keyframes hw-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @keyframes hw-grow { from { transform: scaleY(0); } to { transform: scaleY(1); } }
        @keyframes hw-grow-x { from { transform: scaleX(0); } to { transform: scaleX(1); } }
        @keyframes hw-cell { from { opacity: 0; transform: scale(.3); } to { opacity: 1; transform: none; } }
        @keyframes hw-reveal { from { clip-path: inset(0 100% 0 0); } to { clip-path: inset(0 0 0 0); } }
        @keyframes hw-travel { from { left: 0%; } to { left: 100%; } }
        @keyframes hw-fan { from { transform: rotate(0deg) translateY(20px); opacity: 0; } to { transform: rotate(var(--r)) translateY(0); opacity: 1; } }
        @keyframes hw-scan { 0%, 100% { transform: translate(0, 0); } 33% { transform: translate(60px, 30px); } 66% { transform: translate(-20px, 60px); } }
        @keyframes hw-live { 50% { opacity: .35; } }

        .hw-in { animation: hw-in .5s cubic-bezier(.2,.8,.2,1) both; }
        .hw-grow { transform-origin: bottom; animation: hw-grow .8s cubic-bezier(.2,.8,.2,1) both; }
        .hw-grow-x { transform-origin: left; animation: hw-grow-x 1s cubic-bezier(.2,.8,.2,1) both; }
        .hw-cell { animation: hw-cell .35s ease-out both; }
        .hw-reveal { animation: hw-reveal 1.8s cubic-bezier(.4,0,.2,1) .2s both; }
        .hw-travel { animation: hw-travel 3.2s linear infinite; }
        .hw-fan { animation: hw-fan .6s cubic-bezier(.2,.8,.2,1) both; transform: rotate(var(--r)); }
        .hw-scan { animation: hw-scan 5s ease-in-out infinite; }
        .hw-fill { transform-origin: left; animation: hw-grow-x var(--d, 8s) linear both; }
        .hw-live { animation: hw-live 1.4s ease-in-out infinite; }

        @media (prefers-reduced-motion: reduce) {
            [class*="hw-"] { animation: none !important; }
        }
    </style>
@endpush

{{-- "Real data", wherever a chart is drawn from the records. --}}
@php
    $realData = '<span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 ring-1 ring-emerald-400/30 px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest text-emerald-300"><span class="hw-live w-1.5 h-1.5 rounded-full bg-emerald-400"></span>Real data</span>';
@endphp

<section id="process" x-data="hoursPipeline(@js($growth))" class="relative scroll-mt-20 py-16 sm:py-24 bg-brand-800/40 border-y border-white/10 overflow-hidden" aria-labelledby="process-heading">
    <div aria-hidden="true" class="pointer-events-none absolute -left-[15vw] bottom-0 w-[45vw] h-[45vw] max-w-[560px] max-h-[560px] rounded-full bg-brand-600/15 blur-3xl"></div>

    <div class="relative max-w-7xl mx-auto px-5 sm:px-8">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div>
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">How we work</p>
                <h2 id="process-heading" class="text-3xl sm:text-5xl font-extrabold max-w-2xl leading-[1.1]">Where the hours go.</h2>
            </div>
            <p class="text-sm text-brand-100/60 max-w-sm">
                Five stages, one team. Most of the work you never see happens in the edit. Pick a stage to look inside.
            </p>
        </div>

        {{-- The pipeline: five stages, a dot running along the line between them. --}}
        <div class="relative mt-10 sm:mt-14">
            <div aria-hidden="true" class="hidden lg:block absolute left-[10%] right-[10%] top-9 h-px bg-gradient-to-r from-brand-400/0 via-brand-400/50 to-brand-400/0">
                <span class="hw-travel absolute -top-[3px] w-[7px] h-[7px] rounded-full bg-brand-300 shadow-[0_0_12px_rgba(103,188,212,.9)]"></span>
            </div>

            <div role="tablist" aria-label="Stages" class="-mx-5 px-5 flex gap-3 overflow-x-auto snap-x [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:mx-0 lg:px-0 lg:grid lg:grid-cols-5 lg:overflow-visible">
                @foreach ($stages as $i => [$name, $tagline, $line, $icon])
                    {{-- Active styling comes only from :class -- a server-side
                         "first is active" class would never be taken off. --}}
                    <button type="button" role="tab" id="hw-tab-{{ $i }}" aria-controls="hw-panel"
                            :aria-selected="(active === {{ $i }}).toString()"
                            @click="pick({{ $i }})"
                            :class="active === {{ $i }} ? 'bg-brand-900 ring-brand-400/70 shadow-lg shadow-brand-400/10' : 'bg-brand-900/40 ring-white/10 hover:ring-white/25'"
                            class="relative snap-start shrink-0 w-[44%] sm:w-[30%] lg:w-auto text-left rounded-2xl ring-1 p-4 transition">
                        <span class="flex items-center gap-2">
                            <span :class="active === {{ $i }} ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-300'"
                                  class="relative z-10 w-10 h-10 rounded-xl flex items-center justify-center transition-colors">
                                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}" /></svg>
                            </span>
                            <span class="text-brand-400/70 text-xs font-extrabold tabular-nums">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        </span>
                        <span class="mt-3 block text-lg font-bold">{{ $name }}</span>
                        <span class="block text-xs font-semibold text-brand-200/80">{{ $tagline }}</span>
                        <template x-if="auto && active === {{ $i }}">
                            <span class="absolute left-4 right-4 bottom-0 h-0.5 rounded-full bg-white/10 overflow-hidden" aria-hidden="true">
                                <span class="hw-fill block h-full bg-brand-400"></span>
                            </span>
                        </template>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Every logged hour, as one bar of shares. --}}
        @if ($studio['split'])
            <div class="mt-8 rounded-2xl bg-brand-900/50 ring-1 ring-white/10 p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold">Every hour on our timesheets, split by stage</p>
                    {!! $realData !!}
                </div>
                <div class="mt-3 h-4 sm:h-5 rounded-full overflow-hidden bg-white/5">
                    <template x-if="seen"><div class="flex h-full">
                        @foreach ($studio['split'] as $segment)
                            <button type="button" @click="pick({{ $segment['stage'] }})" aria-label="{{ $segment['label'] }}: {{ $segment['pct'] }}%"
                                    :class="active === {{ $segment['stage'] }} ? 'brightness-125' : 'opacity-70 hover:opacity-100'"
                                    class="hw-grow-x h-full {{ $colours[$segment['stage']] }} transition" style="width: {{ $segment['pct'] }}%; animation-delay: {{ $loop->index * 0.15 }}s"></button>
                        @endforeach
                    </div></template>
                </div>
                <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-brand-100/70">
                    @foreach ($studio['split'] as $segment)
                        <li class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm {{ $colours[$segment['stage']] }}"></span>{{ $segment['label'] }} <span class="font-semibold text-white tabular-nums">{{ $segment['pct'] }}%</span></li>
                    @endforeach
                    <li class="flex items-center gap-1.5 text-brand-100/45">Research happens before the clock starts</li>
                </ul>
            </div>
        @endif

        {{-- The stage, opened up. --}}
        <div id="hw-panel" role="tabpanel" x-ref="panel" :aria-labelledby="'hw-tab-' + active"
             class="relative mt-6 rounded-[1.75rem] bg-brand-900 ring-1 ring-white/10 shadow-2xl shadow-black/30 p-5 sm:p-8 min-h-[23rem]">
            <div class="flex flex-wrap items-start justify-between gap-3">
                @foreach ($stages as $i => [$name, $tagline, $line])
                    <p x-show="active === {{ $i }}" @if ($i !== 0) style="display: none" @endif class="text-sm sm:text-base text-brand-100/75 max-w-2xl">
                        <span class="font-semibold text-white">{{ $name }}.</span> {{ $line }}
                    </p>
                @endforeach
                <span x-show="active !== 0">{!! $realData !!}</span>
            </div>

            {{-- 01 · Research: what comes before the camera. --}}
            <template x-if="active === 0"><div class="mt-6 grid gap-8 sm:grid-cols-[1fr_auto] items-center">
                <ol class="grid grid-cols-2 gap-3">
                    @foreach ([['Trend scan', 'What is working in your space this month.'], ['Audience', 'Who it is for, and what they already feel.'], ['Hook', 'The first second, written before anything else.'], ['Script', 'Beats and lines, approved by you.']] as [$step, $text])
                        <li class="hw-in rounded-2xl bg-white/5 p-4" style="animation-delay: {{ $loop->index * 0.15 }}s">
                            <span class="text-brand-400 text-xs font-extrabold">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <p class="mt-1 font-semibold">{{ $step }}</p>
                            <p class="mt-1 text-xs text-brand-100/60 leading-relaxed">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
                <div class="relative mx-auto w-44 h-48" aria-hidden="true">
                    @foreach ([-10, -4, 3] as $p => $r)
                        <div class="hw-fan absolute inset-x-4 top-2 bottom-4 rounded-xl bg-white shadow-xl p-3 space-y-2" style="--r: {{ $r }}deg; animation-delay: {{ $p * 0.15 }}s">
                            <p class="text-[9px] font-extrabold tracking-widest text-brand-600">{{ ['TREND', 'AUDIENCE', 'HOOK'][$p] }}</p>
                            @for ($l = 0; $l < 5; $l++)
                                <span class="block h-1.5 rounded {{ $l === 0 ? 'bg-brand-900/60' : 'bg-brand-900/20' }}" style="width: {{ [90, 75, 85, 60, 70][$l] }}%"></span>
                            @endfor
                        </div>
                    @endforeach
                    <div class="hw-scan absolute left-2 top-6 w-16 h-16 rounded-full border-4 border-brand-400 bg-brand-200/20">
                        <span class="absolute -right-4 -bottom-4 w-6 h-2 rounded-full bg-brand-400 rotate-45"></span>
                    </div>
                </div>
            </div></template>

            {{-- 02 · Shoot: six months of days on set. --}}
            <template x-if="active === 1"><div class="mt-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <p class="text-sm text-brand-100/70">Six months of our shoot calendar. The brighter the square, the longer the day on set.</p>
                    <p class="text-xs font-semibold text-brand-200 h-4" x-text="tip || 'Tap or hover a day'"></p>
                </div>
                <div class="mt-4 grid grid-flow-col grid-rows-7 gap-[3px] sm:gap-1" style="grid-template-columns: repeat({{ count($studio['shoot']) }}, minmax(0, 1fr))">
                    @foreach ($studio['shoot'] as $w => $week)
                        @foreach ($week as $day)
                            <span class="hw-cell aspect-square rounded-[3px] {{ $heat[$day['level']] }}" style="animation-delay: {{ round($w * 0.025, 3) }}s"
                                  @if ($day['level'] >= 0)
                                      @mouseenter="tip = @js($day['date'].' · '.$dayLabel[$day['level']])"
                                      @click="tip = @js($day['date'].' · '.$dayLabel[$day['level']])"
                                  @endif></span>
                        @endforeach
                    @endforeach
                </div>
                <div class="mt-3 flex items-center justify-end gap-1.5 text-[10px] text-brand-100/50">
                    Less
                    @foreach ([0, 1, 2, 3, 4] as $l)<span class="w-3 h-3 rounded-[3px] {{ $heat[$l] }}"></span>@endforeach
                    More time on set
                </div>
            </div></template>

            {{-- 03 · Edit: the biggest share, month by month. --}}
            <template x-if="active === 2"><div class="mt-6 grid gap-6 sm:grid-cols-[auto_1fr] sm:items-end">
                <div>
                    @if ($editShare)
                        <p class="text-6xl font-extrabold text-brand-200 tabular-nums" data-count>{{ $editShare }}%</p>
                        <p class="mt-1 text-sm text-brand-100/60">of every hour we log goes into the edit:<br class="hidden sm:inline"> cutting, colouring, captioning.</p>
                    @endif
                </div>
                <div>
                    <div class="flex items-end gap-2 sm:gap-4 h-44">
                        @foreach ($studio['edit'] as $m => $month)
                            <div class="group flex-1 h-full flex flex-col items-center justify-end">
                                <span class="hw-grow w-full rounded-t-lg bg-gradient-to-t from-brand-600 to-brand-300 group-hover:to-brand-100 transition-colors" style="height: {{ max(2, $month['pct']) }}%; animation-delay: {{ $m * 0.1 }}s"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex gap-2 sm:gap-4">
                        @foreach ($studio['edit'] as $month)
                            <span class="flex-1 text-center text-xs text-brand-100/50">{{ $month['label'] }}</span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-right text-[11px] text-brand-100/45">Edit time per month, against our busiest month</p>
                </div>
            </div></template>

            {{-- 04 · Post: what gets published. --}}
            <template x-if="active === 3"><div class="mt-6 grid gap-6 sm:grid-cols-[auto_1fr] sm:items-center">
                <div class="max-w-[14rem]">
                    <p class="text-2xl font-extrabold">Reels lead the mix.</p>
                    <p class="mt-1 text-sm text-brand-100/60">Everything we published for clients, by format.</p>
                </div>
                <div class="space-y-3">
                    @foreach ($studio['post'] as $row)
                        <div>
                            <div class="flex justify-between text-xs"><span class="text-brand-100/70">{{ $row['label'] }}</span><span class="font-bold tabular-nums" data-count>{{ $row['pct'] }}%</span></div>
                            <div class="mt-1 h-3 rounded-full bg-white/5 overflow-hidden">
                                <span class="hw-grow-x block h-full rounded-full bg-gradient-to-r from-violet-500 to-brand-300" style="width: {{ $row['pct'] }}%; animation-delay: {{ $loop->index * 0.12 }}s"></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div></template>

            {{-- 05 · Track: one reel's growth, to touch. --}}
            <template x-if="active === 4"><div class="mt-6 grid gap-6 lg:grid-cols-[1fr_14rem]">
                @if ($growth)
                    <div class="rounded-2xl bg-white/[0.04] ring-1 ring-white/10 p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-widest text-brand-300 font-semibold">Views, day by day</p>
                                <p class="mt-1 text-sm text-brand-100/70 truncate">{{ $growth['client'] ? $growth['client'].' · ' : '' }}one reel, as we tracked it</p>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl sm:text-3xl font-extrabold tabular-nums text-brand-200" x-text="times(current)">×{{ $growth['multiple'] }}</p>
                                <p class="text-xs text-brand-100/60" x-text="current.date + ' · vs. day one'">{{ last($growth['points'])['date'] }} · vs. day one</p>
                            </div>
                        </div>

                        <div class="relative mt-4 h-52 sm:h-60 cursor-crosshair touch-pan-y select-none"
                             @mousemove="touch($event)" @touchstart.passive="touch($event)" @touchmove.passive="touch($event)" @click="touch($event)">
                            <svg :viewBox="`0 0 ${w} ${h}`" viewBox="0 0 600 220" preserveAspectRatio="none" class="hw-reveal absolute inset-0 w-full h-full" aria-hidden="true">
                                <defs>
                                    <linearGradient id="hw-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#67BCD4" stop-opacity=".45" /><stop offset="1" stop-color="#67BCD4" stop-opacity="0" /></linearGradient>
                                </defs>
                                @foreach ([0.25, 0.5, 0.75] as $g)
                                    <line x1="0" x2="600" y1="{{ 220 * $g }}" y2="{{ 220 * $g }}" stroke="rgba(255,255,255,.07)" vector-effect="non-scaling-stroke" />
                                @endforeach
                                <path :d="area" fill="url(#hw-area)" />
                                <path :d="line" fill="none" stroke="#8ACCE0" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                            </svg>
                            <div class="pointer-events-none absolute inset-y-0 w-px bg-white/40 transition-[left] duration-100" :style="`left: ${x(cursor) / w * 100}%`">
                                <span class="absolute -left-[7px] w-3.5 h-3.5 rounded-full bg-white ring-4 ring-brand-400/50" :style="`top: calc(${y(current.pct) / h * 100}% - 7px)`"></span>
                            </div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] text-brand-100/50">
                            <span>{{ $growth['points'][0]['date'] }}</span>
                            <span class="font-semibold text-brand-200">Drag along the line ←→</span>
                            <span>{{ last($growth['points'])['date'] }}</span>
                        </div>
                    </div>
                @endif
                <div class="grid grid-cols-2 lg:grid-cols-1 gap-3 content-start">
                    @foreach ([['Every day', 'each account is synced: views, reach, shares, saves'], ['Every month', 'a report you can read in five minutes'], ['Every reel', 'tracked from the hour it goes live']] as [$big, $small])
                        <div class="hw-in rounded-2xl bg-white/5 p-4 {{ $loop->last ? 'col-span-2 lg:col-span-1' : '' }}" style="animation-delay: {{ $loop->index * 0.12 }}s">
                            <p class="text-xl font-extrabold text-brand-200">{{ $big }}</p>
                            <p class="mt-1 text-xs text-brand-100/60">{{ $small }}</p>
                        </div>
                    @endforeach
                </div>
            </div></template>
        </div>
    </div>
</section>
