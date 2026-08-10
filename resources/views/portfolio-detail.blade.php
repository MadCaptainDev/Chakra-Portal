@php
    use App\Support\Metric;

    /*
    | The public case study for one piece of work.
    |
    | Every section is optional. Staff fill in whatever they actually have, and
    | anything left blank drops out rather than rendering an empty shell -- so
    | the same screen serves a fully measured campaign and a piece with nothing
    | behind it but a title and a film.
    */

    /*
    | Platform, format and objective come from the master lists, falling back
    | to whatever was typed before those lists existed -- so a piece the
    | backfill could not match still reads correctly. clientLabel() prefers the
    | linked client record over the typed name.
    */
    $platform = $item->platformLabel();
    $format = $item->formatLabel();
    $objective = $item->objectiveLabel();
    $clientName = $item->clientLabel();
    $tags = $item->tags;

    $engagements = $item->engagements();
    $growth = $item->salesGrowth();
    $benchmarkRows = $item->benchmarkRows();
    $beforeAfter = $item->beforeAfterRows();
    $creative = $item->creativeNotes();

    $hasEngagement = $item->engagement_rate !== null
        || $item->completion_rate !== null
        || $item->watch_hours !== null
        || $item->avg_watch_seconds !== null
        || $engagements !== null;

    // Sales are only ever printed when the piece is set to show them.
    $hasBusiness = $item->hasBusinessImpact();
    $showsSales = $hasBusiness && $item->sales_amount !== null;
    $hasBeforeAfter = ($showsSales && $item->sales_before_amount) || $beforeAfter !== [];
    $hasSpec = filled($platform) || filled($format)
        || $item->duration_seconds !== null || filled($objective);

    // Sections are numbered as they appear, so hiding one never leaves a gap.
    $step = 0;
    $number = function () use (&$step) {
        return str_pad((string) ++$step, 2, '0', STR_PAD_LEFT);
    };

    // Interaction bars are scaled against the largest of them. The square root
    // keeps a small count visible next to a very large one.
    $bars = array_filter([
        'Likes' => $item->likes,
        'Saves' => $item->saves,
        'Comments' => $item->comments,
        'Shares' => $item->shares,
    ], fn ($value) => $value !== null);
    $barMax = $bars ? max($bars) : 0;
    $barWidth = fn ($value) => $barMax > 0 ? round(sqrt($value / $barMax) * 100) : 0;

    // The funnel narrows from views to sales, so each stage is measured
    // against the widest one above it.
    $funnel = array_filter([
        'Views' => $item->views,
        'Reach' => $item->reach,
        'Engagements' => $engagements,
        'Enquiries' => $item->enquiries,
        'Orders' => $item->orders,
    ], fn ($value) => $value !== null);
    $funnelMax = $funnel ? max($funnel) : 0;
    $funnelWidth = fn ($value) => $funnelMax > 0 ? max(28, round(sqrt($value / $funnelMax) * 100)) : 0;

    // A Reel is shot vertical; anything else reads better in the usual frame.
    $vertical = str_contains((string) $format, '9:16');
    $coverRatio = $vertical ? 'aspect-[9/16]' : 'aspect-video';

    $playback = $item->playbackUrl();
@endphp

<x-public-layout
    :title="$item->title.' — Chakra Productions'"
    :description="$item->summary ?: $item->description ?: 'A piece of work from Chakra Productions.'">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Hero: the film, who it was for, and the headline numbers.           --}}
    {{-- ------------------------------------------------------------------ --}}
    <section class="relative overflow-hidden border-b border-white/10"
             x-data="portfolioPlayer(@js($playback), @js($item->isUploaded() ? 'file' : 'link'))">

        {{-- A soft teal wash behind the fold, the brand's version of the
             mockup's glow. Decorative only. --}}
        <div aria-hidden="true"
             class="pointer-events-none absolute inset-x-0 top-0 h-[420px] bg-gradient-to-b from-brand-400/20 via-brand-400/5 to-transparent"></div>

        <div class="relative max-w-7xl mx-auto px-5 sm:px-8 py-10 sm:py-16">
            <a href="{{ route('portfolio') }}"
               class="inline-flex items-center gap-2 min-h-[44px] text-sm font-semibold text-brand-100/60 hover:text-white transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                All work
            </a>

            {{-- On a phone the title leads and the cover follows it, so the
                 first screen says what this is rather than filling itself with
                 a 9:16 still. On a desktop the cover takes the left column. --}}
            <div class="mt-6 flex flex-col gap-8 lg:grid lg:grid-cols-[minmax(0,360px)_1fr] lg:grid-rows-[auto_auto] lg:gap-x-14 lg:gap-y-8 lg:items-start">
                {{-- Cover --}}
                <div class="order-2 lg:order-none lg:col-start-1 lg:row-start-1 lg:row-span-2">
                    <div class="relative {{ $coverRatio }} rounded-xl overflow-hidden bg-brand-800 border border-white/10">
                        {{-- With no thumbnail uploaded the cover used to be an
                             empty box with a play button floating in it, which
                             read as a broken page rather than as a reel. The
                             uploaded file stands in for the still: metadata
                             preload plus a #t= seek paints the frame without
                             pulling the whole video down, and it is inert --
                             the modal below is what actually plays. --}}
                        @if ($item->thumbnailUrl())
                            <img src="{{ $item->thumbnailUrl() }}" alt="{{ $item->title }}"
                                 class="w-full h-full object-cover">
                        @elseif ($item->isUploaded())
                            <video src="{{ $playback }}#t=0.1" preload="metadata"
                                   muted playsinline tabindex="-1" aria-hidden="true"
                                   class="w-full h-full object-cover pointer-events-none"></video>
                        @endif

                        <div aria-hidden="true"
                             class="absolute inset-0 bg-gradient-to-b from-brand-900/50 via-transparent to-brand-900/80"></div>

                        <div class="absolute top-3 inset-x-3 flex items-center justify-between gap-2 pointer-events-none">
                            @if ($platform)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-brand-900/70 backdrop-blur text-[10px] font-semibold uppercase tracking-widest">
                                    {{ $platform }}
                                </span>
                            @endif
                            @if ($item->duration_seconds !== null)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-brand-900/70 backdrop-blur text-[11px] font-semibold tabular-nums">
                                    {{ Metric::duration($item->duration_seconds) }}
                                </span>
                            @endif
                        </div>

                        @if ($playback)
                            <button type="button" @click="open()"
                                    class="absolute inset-0 flex items-center justify-center group"
                                    aria-label="Play {{ $item->title }}">
                                <span class="inline-flex items-center justify-center w-16 h-16 sm:w-[74px] sm:h-[74px] rounded-full bg-white/90 text-brand-900 shadow-lg group-hover:scale-110 transition-transform">
                                    <svg class="w-6 h-6 ml-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z" />
                                    </svg>
                                </span>
                            </button>
                        @endif
                    </div>

                    @if ($clientName || $item->published_on)
                        <div class="mt-4">
                            @if ($clientName)
                                <p class="font-semibold">{{ $clientName }}</p>
                            @endif
                            @if ($item->published_on)
                                <p class="text-sm text-brand-100/50">Published {{ $item->published_on->format('j F Y') }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Headline --}}
                <div class="order-1 lg:order-none lg:col-start-2 lg:row-start-1">
                    <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">
                        {{ $item->hasPerformance() ? 'Per-video performance' : 'Case study' }}
                    </p>

                    <h1 class="mt-4 text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-[1.08]">
                        {{ $item->title }}
                    </h1>

                    @if ($item->summary || $item->description)
                        <p class="mt-5 text-base sm:text-lg text-brand-100/70 max-w-2xl leading-relaxed">
                            {{ $item->summary ?: $item->description }}
                        </p>
                    @endif

                    @if ($item->category || $format || $item->duration_seconds !== null || $objective || $tags->isNotEmpty())
                        <div class="mt-6 flex flex-wrap gap-2">
                            @foreach (array_filter([
                                $item->category?->name,
                                $format,
                                $item->duration_seconds !== null ? $item->duration_seconds.' seconds' : null,
                                ...$tags->pluck('name')->all(),
                            ]) as $chip)
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full bg-white/5 border border-white/15 text-xs font-semibold text-brand-100/70">
                                    {{ $chip }}
                                </span>
                            @endforeach
                            @if ($objective)
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full bg-brand-400/15 border border-brand-400/40 text-xs font-semibold text-brand-200">
                                    {{ $objective }}
                                </span>
                            @endif
                        </div>
                    @endif

                </div>

                {{-- Headline numbers. Their own cell so that on a phone they
                     land after the film rather than pushing it off screen. --}}
                @if ($item->hasPerformance())
                    <div class="order-3 lg:order-none lg:col-start-2 lg:row-start-2">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @if ($item->views !== null)
                                <div class="col-span-2 rounded-xl bg-white/5 border border-white/10 p-5">
                                    <p class="text-4xl sm:text-5xl font-extrabold tabular-nums leading-none">{{ Metric::count($item->views) }}</p>
                                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Views</p>
                                </div>
                            @endif
                            @if ($item->reach !== null)
                                <div class="col-span-2 rounded-xl bg-white/5 border border-white/10 p-5">
                                    <p class="text-4xl sm:text-5xl font-extrabold tabular-nums leading-none">{{ Metric::count($item->reach) }}</p>
                                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Reach</p>
                                </div>
                            @endif

                            @foreach (array_filter([
                                'Likes' => $item->likes,
                                'Shares' => $item->shares,
                                'Saves' => $item->saves,
                                'Comments' => $item->comments,
                                'Profile visits' => $item->profile_visits,
                            ], fn ($value) => $value !== null) as $label => $value)
                                <div class="rounded-xl bg-white/5 border border-white/10 p-4">
                                    <p class="text-2xl font-bold tabular-nums leading-none">{{ Metric::count($value) }}</p>
                                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">{{ $label }}</p>
                                </div>
                            @endforeach

                            @if ($item->enquiries !== null)
                                <div class="rounded-xl bg-brand-400/15 border border-brand-400/40 p-4">
                                    <p class="text-2xl font-bold tabular-nums leading-none">{{ Metric::count($item->enquiries) }}</p>
                                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-widest text-brand-200">Enquiries</p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Player. Rebuilt each time it opens so the file starts from the top. --}}
        <div x-show="playing" x-cloak @keydown.escape.window="close()"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-8">
            <div class="absolute inset-0 bg-black/80" @click="close()"></div>

            <div class="relative w-full {{ $vertical ? 'max-w-md' : 'max-w-4xl' }}">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <p class="font-semibold text-white truncate">{{ $item->title }}</p>
                    <button type="button" @click="close()"
                            class="shrink-0 inline-flex items-center justify-center min-h-[44px] min-w-[44px] rounded-md text-white/70 hover:text-white hover:bg-white/10"
                            aria-label="Close video">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <template x-if="playing">
                    <video controls autoplay playsinline class="w-full max-h-[75vh] rounded-lg bg-black"
                           src="{{ $playback }}"></video>
                </template>
            </div>
        </div>
    </section>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Engagement                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($hasEngagement)
        <section class="bg-brand-800/40 border-b border-white/10">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">{{ $number() }} — Engagement</p>
                <h2 class="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold">How this film performed</h2>
                <p class="mt-3 text-brand-100/60 max-w-2xl leading-relaxed">
                    Watch behaviour and interaction, as the platform reported them.
                </p>

                <div class="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-5">
                    @if ($item->engagement_rate !== null || $bars)
                        <div class="rounded-xl bg-white/5 border border-white/10 p-6 sm:p-8">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Engagement rate</p>

                            @if ($item->engagement_rate !== null)
                                <p class="mt-3 text-5xl sm:text-6xl font-extrabold tabular-nums leading-none">
                                    {{ Metric::percent($item->engagement_rate) }}
                                </p>
                            @endif

                            @if ($bars)
                                <div class="mt-8 space-y-4">
                                    @foreach ($bars as $label => $value)
                                        <div>
                                            <div class="flex items-baseline justify-between text-sm mb-2">
                                                <span>{{ $label }}</span>
                                                <span class="text-brand-100/50 tabular-nums">{{ Metric::count($value) }}</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-white/10 overflow-hidden">
                                                <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-300"
                                                     style="width: {{ $barWidth($value) }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-5 content-start">
                        @if ($item->completion_rate !== null)
                            @php $ring = 327 * (1 - min(100, max(0, $item->completion_rate)) / 100); @endphp
                            <div class="rounded-xl bg-white/5 border border-white/10 p-6 sm:p-8 flex items-center gap-6">
                                <div class="relative w-[120px] h-[120px] shrink-0">
                                    <svg viewBox="0 0 120 120" class="w-[120px] h-[120px] -rotate-90" aria-hidden="true">
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.12)" stroke-width="10" />
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="#67BCD4" stroke-width="10"
                                                stroke-linecap="round" stroke-dasharray="327" stroke-dashoffset="{{ $ring }}" />
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-2xl font-bold tabular-nums">{{ Metric::percent($item->completion_rate) }}</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Completion rate</p>
                                    <p class="mt-2 text-sm text-brand-100/60 leading-relaxed">
                                        Viewers who stayed to the final frame, where the call to action sits.
                                    </p>
                                </div>
                            </div>
                        @endif

                        @if ($item->watch_hours !== null || $item->avg_watch_seconds !== null)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                @if ($item->watch_hours !== null)
                                    <div class="rounded-xl bg-white/5 border border-white/10 p-6">
                                        <p class="text-3xl font-bold tabular-nums leading-none">{{ Metric::count($item->watch_hours) }} hrs</p>
                                        <p class="mt-3 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Total watch time</p>
                                    </div>
                                @endif
                                @if ($item->avg_watch_seconds !== null)
                                    <div class="rounded-xl bg-white/5 border border-white/10 p-6">
                                        <p class="text-3xl font-bold tabular-nums leading-none">{{ rtrim(rtrim(number_format($item->avg_watch_seconds, 1), '0'), '.') }}s</p>
                                        <p class="mt-3 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Avg. watch duration</p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Business impact                                                     --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($hasBusiness)
        <section class="border-b border-white/10">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">{{ $number() }} — Business impact</p>
                <h2 class="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold">From views to sales</h2>
                <p class="mt-3 text-brand-100/60 max-w-2xl leading-relaxed">
                    Every step this one film carried people through, from first impression to closed sale.
                </p>

                <div class="mt-10 grid grid-cols-1 lg:grid-cols-[1.2fr_1fr] gap-8 lg:gap-10 items-start">
                    @if ($funnel)
                        <div class="space-y-2.5">
                            @foreach ($funnel as $label => $value)
                                @php $last = $loop->last; @endphp
                                <div class="flex items-center gap-4">
                                    <div class="h-14 rounded-lg flex items-center px-5 border {{ $last ? 'bg-brand-500/40 border-brand-400/60' : 'bg-white/5 border-white/10' }}"
                                         style="width: {{ $funnelWidth($value) }}%">
                                        <span class="text-lg sm:text-xl font-bold tabular-nums">{{ Metric::count($value) }}</span>
                                    </div>
                                    <span class="text-[11px] font-semibold uppercase tracking-widest {{ $last ? 'text-brand-200' : 'text-brand-100/50' }}">
                                        {{ $label }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="rounded-xl p-6 sm:p-8 bg-gradient-to-br from-brand-500/25 to-brand-800/60 border border-brand-400/40">
                        @if ($item->sales_amount !== null)
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-200">Attributed sales</p>
                            <p class="mt-3 text-5xl sm:text-6xl font-extrabold tabular-nums leading-none">{{ Metric::money($item->sales_amount) }}</p>
                        @endif

                        @if ($growth !== null)
                            <p class="mt-5 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-brand-400/20 text-sm font-semibold">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 12 12" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2 9L10 3M10 3H5.5M10 3v4.5" />
                                </svg>
                                {{ ($growth >= 0 ? '+' : '').Metric::percent($growth) }} in monthly sales
                            </p>
                        @endif

                        @php
                            $impact = array_filter([
                                'Leads generated' => $item->leads !== null ? Metric::count($item->leads) : null,
                                'WhatsApp enquiries' => $item->whatsapp_enquiries !== null ? Metric::count($item->whatsapp_enquiries) : null,
                                'Calls' => $item->calls !== null ? Metric::count($item->calls) : null,
                                'Store visits' => $item->store_visits !== null ? Metric::count($item->store_visits) : null,
                                'Orders' => $item->orders !== null ? Metric::count($item->orders) : null,
                                'Return on spend' => $item->roi !== null ? rtrim(rtrim(number_format($item->roi, 1), '0'), '.')."\u{00D7}" : null,
                            ]);
                        @endphp

                        @if ($impact)
                            <div class="mt-7 pt-7 border-t border-white/15 grid grid-cols-2 gap-5">
                                @foreach ($impact as $label => $value)
                                    <div>
                                        <p class="text-xl font-bold tabular-nums leading-none">{{ $value }}</p>
                                        <p class="mt-1.5 text-[10px] font-semibold uppercase tracking-widest text-brand-100/60">{{ $label }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Before vs after                                                     --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($hasBeforeAfter)
        <section class="bg-brand-800/40 border-b border-white/10">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">{{ $number() }} — Before vs after</p>
                <h2 class="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold">The month either side</h2>

                @if ($showsSales && $item->sales_before_amount)
                    @php
                        $peak = max($item->sales_amount, $item->sales_before_amount);
                        $beforeWidth = round($item->sales_before_amount / $peak * 100);
                        $afterWidth = round($item->sales_amount / $peak * 100);
                    @endphp

                    <div class="mt-8 grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-5 md:gap-7 items-center">
                        <div class="rounded-xl bg-white/5 border border-white/10 p-6 sm:p-8">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Before</p>
                            <p class="mt-4 text-4xl font-extrabold tabular-nums leading-none text-brand-100/60">{{ Metric::money($item->sales_before_amount) }}</p>
                            <p class="mt-2 text-xs text-brand-100/40">Monthly sales</p>
                            <div class="mt-6 h-2 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full bg-white/30" style="width: {{ $beforeWidth }}%"></div>
                            </div>
                        </div>

                        @if ($growth !== null)
                            <div class="text-center">
                                <p class="text-3xl font-extrabold tabular-nums text-brand-300">{{ ($growth >= 0 ? '+' : '').Metric::percent($growth) }}</p>
                                <p class="mt-1.5 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">Growth</p>
                            </div>
                        @endif

                        <div class="rounded-xl p-6 sm:p-8 bg-gradient-to-br from-brand-500/25 to-brand-800/60 border border-brand-400/40">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-200">After</p>
                            <p class="mt-4 text-4xl font-extrabold tabular-nums leading-none">{{ Metric::money($item->sales_amount) }}</p>
                            <p class="mt-2 text-xs text-brand-100/60">Monthly sales</p>
                            <div class="mt-6 h-2 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-300" style="width: {{ $afterWidth }}%"></div>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($beforeAfter)
                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        @foreach ($beforeAfter as $row)
                            <div class="rounded-lg bg-white/5 border border-white/10 p-5">
                                <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">{{ $row['label'] }}</p>
                                <p class="mt-3 flex items-baseline gap-2">
                                    <span class="text-brand-100/40">{{ $row['before'] }}</span>
                                    <span class="text-brand-100/30" aria-hidden="true">&rarr;</span>
                                    <span class="text-lg font-bold">{{ $row['after'] }}</span>
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- The work                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($hasSpec || $creative)
        <section class="border-b border-white/10">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_1.35fr] gap-10 lg:gap-14 items-start">
                    <div>
                        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">{{ $number() }} — The work</p>
                        <h2 class="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold">What we created</h2>

                        @if ($hasSpec)
                            <dl class="mt-7">
                                @foreach (array_filter([
                                    'Content type' => $item->category?->name,
                                    'Format' => $format,
                                    'Duration' => $item->duration_seconds !== null ? $item->duration_seconds.' seconds' : null,
                                    'Platform' => $platform,
                                    'Objective' => $objective,
                                ]) as $label => $value)
                                    <div class="flex items-baseline justify-between gap-4 py-3 border-b border-white/10 last:border-0">
                                        <dt class="text-sm text-brand-100/50">{{ $label }}</dt>
                                        <dd class="text-sm text-right">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>

                    @if ($creative)
                        <div>
                            <h3 class="text-lg font-semibold">Creative strategy</h3>
                            <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                @foreach ($creative as $label => $note)
                                    <div class="rounded-lg bg-white/5 border border-white/10 p-5">
                                        <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-300">{{ $label }}</p>
                                        <p class="mt-2.5 text-sm text-brand-100/70 leading-relaxed">{{ $note }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Headline figure                                                     --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($item->views !== null)
        <section class="relative overflow-hidden border-b border-white/10">
            <div aria-hidden="true"
                 class="pointer-events-none absolute -right-24 -bottom-40 w-[520px] h-[520px] rounded-full bg-brand-400/15 blur-3xl"></div>

            <div class="relative max-w-7xl mx-auto px-5 sm:px-8 py-16 sm:py-24">
                @if ($clientName)
                    {{-- "SVA Jewels'" rather than "SVA Jewels's". --}}
                    @php $possessive = $clientName.(str_ends_with($clientName, 's') ? "'" : "'s"); @endphp
                    <p class="max-w-md text-xl sm:text-2xl font-semibold leading-snug text-brand-200">
                        One of {{ $possessive }} highest-performing pieces of content.
                    </p>
                @endif
                <p class="mt-8 text-6xl sm:text-8xl lg:text-9xl font-extrabold tabular-nums leading-none tracking-tight">
                    {{ Metric::count($item->views) }}
                </p>
                <p class="mt-3 text-sm font-semibold uppercase tracking-[0.35em] text-brand-100/60">Views</p>

                @php
                    $closing = array_filter([
                        $item->enquiries !== null ? Metric::count($item->enquiries).' enquiries' : null,
                        $showsSales ? Metric::money($item->sales_amount).' attributed sales' : null,
                    ]);
                @endphp

                @if ($closing)
                    <p class="mt-7 text-base sm:text-lg text-brand-100/80">{{ implode(' · ', $closing) }}</p>
                @endif
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Comparison against the client's average piece                       --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($benchmarkRows)
        <section class="bg-brand-800/40 border-b border-white/10">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">{{ $number() }} — Comparison</p>
                <h2 class="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold">How this film compared</h2>
                <p class="mt-3 text-brand-100/60 max-w-2xl leading-relaxed">
                    Measured against the average piece published on the same account.
                </p>

                <div class="mt-10 grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-8 items-start">
                    {{-- Phone: one card per metric. A four-column table does not
                         survive 420px, and this is the screen most clients open. --}}
                    <div class="lg:hidden space-y-3">
                        @foreach ($benchmarkRows as $row)
                            <div class="rounded-lg bg-white/5 border border-white/10 p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="font-semibold">{{ $row['label'] }}</p>
                                    <span class="text-brand-300 font-bold tabular-nums">{{ $row['multiple'] }}</span>
                                </div>
                                <p class="mt-2 flex items-baseline gap-2 text-sm">
                                    <span class="text-lg font-bold tabular-nums">{{ $row['actual'] }}</span>
                                    <span class="text-brand-100/40">vs {{ $row['benchmark'] }} average</span>
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <div class="hidden lg:block rounded-xl bg-white/5 border border-white/10 overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/10 text-[10px] font-semibold uppercase tracking-widest text-brand-100/50">
                                    <th class="px-6 py-4 text-left">Metric</th>
                                    <th class="px-6 py-4 text-right">This film</th>
                                    <th class="px-6 py-4 text-right">Average</th>
                                    <th class="px-6 py-4 text-right">Multiple</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                @foreach ($benchmarkRows as $row)
                                    <tr>
                                        <td class="px-6 py-4">{{ $row['label'] }}</td>
                                        <td class="px-6 py-4 text-right font-semibold tabular-nums">{{ $row['actual'] }}</td>
                                        <td class="px-6 py-4 text-right text-brand-100/50 tabular-nums">{{ $row['benchmark'] }}</td>
                                        <td class="px-6 py-4 text-right font-bold text-brand-300 tabular-nums">{{ $row['multiple'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded-xl bg-white/5 border border-brand-400/30 p-6 sm:p-8">
                        <p class="text-5xl sm:text-6xl font-extrabold tabular-nums leading-none text-brand-300">{{ $benchmarkRows[0]['multiple'] }}</p>
                        <p class="mt-4 text-sm text-brand-100/70 leading-relaxed">
                            more {{ strtolower($benchmarkRows[0]['label']) }} than the client's average piece, from the
                            same account and the same audience.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- More work                                                           --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($related->isNotEmpty())
        <section class="border-b border-white/10">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">{{ $number() }} — More work</p>
                        <h2 class="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold">
                            {{ $clientName ? 'Other films for '.$clientName : 'More from the studio' }}
                        </h2>
                    </div>
                    <a href="{{ route('portfolio') }}"
                       class="inline-flex items-center min-h-[44px] px-5 rounded-md border border-white/20 text-xs font-semibold uppercase tracking-widest text-brand-100/80 hover:text-white hover:border-white/40 transition-colors">
                        View all work
                    </a>
                </div>

                <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                    @foreach ($related as $other)
                        <a href="{{ route('portfolio.detail', $other) }}"
                           class="group rounded-xl overflow-hidden bg-white/5 border border-white/10 hover:border-brand-400/40 transition-colors">
                            <div class="relative aspect-video bg-brand-900/60 overflow-hidden">
                                @if ($other->thumbnailUrl())
                                    <img src="{{ $other->thumbnailUrl() }}" alt="{{ $other->title }}" loading="lazy"
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                @endif
                            </div>
                            <div class="p-4">
                                <p class="font-semibold">{{ $other->title }}</p>
                                @php
                                    $line = array_filter([
                                        $other->views !== null ? Metric::count($other->views).' views' : null,
                                        $other->enquiries !== null ? Metric::count($other->enquiries).' enquiries' : null,
                                        $other->hasBusinessImpact() && $other->sales_amount !== null
                                            ? Metric::money($other->sales_amount) : null,
                                    ]);
                                @endphp
                                <p class="mt-1 text-sm text-brand-100/50 tabular-nums">
                                    {{ $line ? implode(' · ', $line) : ($other->category?->name ?? 'View case study') }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Closing call to action                                              --}}
    {{-- ------------------------------------------------------------------ --}}
    <section class="bg-brand-800/40">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-16 sm:py-20 text-center">
            <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold">Want something like this?</h2>
            <p class="mt-4 text-brand-100/60 max-w-xl mx-auto leading-relaxed">
                Tell us roughly what you have in mind and we will come back with how we would approach it.
            </p>
            <a href="{{ route('home', ['from' => 'case-study']) }}#contact"
               class="mt-8 inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-white text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                Start a project
            </a>
        </div>
    </section>

    @push('scripts')
        <script>
            function portfolioPlayer(src, kind) {
                return {
                    playing: false,

                    open() {
                        if (! src) return;

                        // A linked film lives on someone else's player, so send
                        // the visitor there rather than embedding it.
                        if (kind === 'link') {
                            window.open(src, '_blank', 'noopener');
                            return;
                        }

                        this.playing = true;
                        document.body.classList.add('overflow-hidden');
                    },

                    close() {
                        this.playing = false;
                        document.body.classList.remove('overflow-hidden');
                    },
                };
            }
        </script>
    @endpush
</x-public-layout>
