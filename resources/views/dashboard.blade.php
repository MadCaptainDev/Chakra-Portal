@php
    use App\Models\TimesheetEntry;

    /*
    | The studio at a glance: leads, work, the website and money.
    |
    | Money used to be the whole page. It is now the last of four sections,
    | because an owner opening this in the morning needs to know a lead is
    | unanswered before they need a cashflow chart -- and every one of those
    | four things used to live on a screen of its own.
    |
    | Cards are `bg-white/5` over a `white/10` hairline on the brand-900 ground,
    | the same surface the public site and the staff dashboard use.
    */
    $money = fn ($value, $decimals = 2) => '₹'.number_format((float) $value, $decimals);

    // Action rows are tone-coded by cost of ignoring, not by which module
    // raised them. Amber for "today", red for "this is already costing you".
    $tones = [
        'red' => ['ring' => 'ring-red-400/40', 'bg' => 'bg-red-400/10', 'bar' => 'bg-red-400'],
        'amber' => ['ring' => 'ring-amber-400/40', 'bg' => 'bg-amber-400/10', 'bar' => 'bg-amber-400'],
        'brand' => ['ring' => 'ring-brand-400/40', 'bg' => 'bg-brand-400/10', 'bar' => 'bg-brand-400'],
        'green' => ['ring' => 'ring-emerald-400/40', 'bg' => 'bg-emerald-400/10', 'bar' => 'bg-emerald-400'],
    ];

    $sectionLabel = 'text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300';
    $cardClass = 'rounded-xl bg-white/5 ring-1 ring-white/10';
    $tileLabel = 'text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70';
@endphp

<x-app-layout title="Dashboard" dark>
    <div class="space-y-10">

        {{-- ——— Header ——— --}}
        <div class="animate-rise-in flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="{{ $sectionLabel }}">Studio</p>
                <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">{{ $month->format('F Y') }}</h1>
                <p class="mt-2 text-sm text-brand-100/70">
                    Leads, work, the website and money — what moved this month and what still needs you.
                </p>
            </div>

            <a href="{{ route('invoices.create') }}"
               class="inline-flex items-center justify-center gap-2 min-h-[44px] px-5 rounded-md
                      bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest
                      hover:bg-brand-500 transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                New invoice
            </a>
        </div>

        {{-- ——— Needs attention. Every domain feeds this one list. ——— --}}
        <section>
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <p class="{{ $sectionLabel }}">Needs attention</p>
                <p class="text-xs text-brand-100/60">Across the whole studio, worst first</p>
            </div>

            <div class="space-y-2.5">
                @foreach ($actionItems as $item)
                    @php $tone = $tones[$item['tone']] ?? $tones['brand']; @endphp
                    <a href="{{ $item['href'] }}"
                       class="group flex items-center gap-4 rounded-xl p-4 sm:px-5 ring-1 transition-colors
                              {{ $tone['bg'] }} {{ $tone['ring'] }} hover:bg-white/[0.09]">
                        <span class="shrink-0 w-2 h-9 rounded-full {{ $tone['bar'] }}"></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <p class="font-semibold">{{ $item['title'] }}</p>
                                <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-100/60
                                             border border-white/15 rounded-full px-2 py-0.5">
                                    {{ $item['domain'] ?? 'Money' }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-brand-100/70">{{ $item['detail'] }}</p>
                        </div>

                        <span class="shrink-0 inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-widest text-brand-200">
                            {{ $item['cta'] }}
                            <x-icon name="chevron-right" class="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ——— The month at a glance, across all four domains ——— --}}
        <section>
            <p class="{{ $sectionLabel }} mb-4">The month at a glance</p>

            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3.5">
                @php
                    $glance = [
                        ['domain' => 'Enquiries', 'value' => $monthEnquiries, 'label' => 'New this month',
                         'note' => $unreadEnquiries > 0 ? $unreadEnquiries.' unread' : 'All read',
                         'href' => route('enquiries.index'), 'accent' => $unreadEnquiries > 0],
                        ['domain' => 'Team', 'value' => TimesheetEntry::formatMinutes($teamMinutes), 'label' => 'Hours logged',
                         'note' => $teamHours->count().' '.Str::plural('person', $teamHours->count()),
                         'href' => route('timesheets.index'), 'accent' => false],
                        ['domain' => 'Website', 'value' => number_format($portfolioReach), 'label' => 'Portfolio reach',
                         'note' => $publishedCount.' published', 'href' => route('portfolio.index'), 'accent' => false],
                        ['domain' => 'Money', 'value' => $money($thisMonthRevenue, 0), 'label' => 'Collected',
                         'note' => $money($outstanding, 0).' still owed', 'href' => route('invoices.index'), 'accent' => false],
                        ['domain' => 'Money', 'value' => $money($outflowPending, 0), 'label' => 'Still to pay',
                         'note' => $outflowPending > 0 ? 'Clear before month end' : 'All settled',
                         'href' => route('expenses.index'), 'accent' => $outflowPending > 0],
                    ];
                @endphp

                @foreach ($glance as $tile)
                    <a href="{{ $tile['href'] }}"
                       @class([
                           'rounded-xl p-5 transition-colors ring-1',
                           'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40 hover:from-amber-400/30' => $tile['accent'],
                           'bg-white/5 ring-white/10 hover:bg-white/[0.08]' => ! $tile['accent'],
                       ])>
                        <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-brand-100/50">{{ $tile['domain'] }}</p>
                        <p class="mt-2.5 text-2xl sm:text-3xl font-extrabold leading-none tabular-nums tracking-tight">{{ $tile['value'] }}</p>
                        <p class="mt-2 {{ $tileLabel }}">{{ $tile['label'] }}</p>
                        <p @class(['mt-1.5 text-xs', 'text-amber-100/80' => $tile['accent'], 'text-brand-100/60' => ! $tile['accent']])>{{ $tile['note'] }}</p>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ——— Enquiries ——— --}}
        <section>
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <p class="{{ $sectionLabel }}">Enquiries</p>
                <a href="{{ route('enquiries.index') }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All enquiries</a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1.5fr_1fr] gap-3.5">
                <div class="{{ $cardClass }} overflow-hidden">
                    @forelse ($recentEnquiries as $enquiry)
                        <a href="{{ route('enquiries.show', $enquiry) }}"
                           class="flex items-start gap-3.5 p-4 hover:bg-white/5 transition-colors {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <span @class([
                                'shrink-0 w-2 h-2 rounded-full mt-2',
                                'bg-brand-400' => $enquiry->isUnread(),
                                'bg-white/20' => ! $enquiry->isUnread(),
                            ])></span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p @class(['truncate', 'font-bold' => $enquiry->isUnread(), 'font-medium' => ! $enquiry->isUnread()])>{{ $enquiry->name }}</p>
                                    @if ($enquiry->hasSource())
                                        <span class="text-[9px] font-semibold uppercase tracking-wider text-brand-100/70 bg-white/5 border border-white/15 rounded-full px-2 py-0.5">
                                            {{ $enquiry->sourceLabel() }}
                                        </span>
                                    @endif
                                </div>

                                @if ($enquiry->project)
                                    <p class="mt-1 text-xs text-brand-100/60 truncate">{{ $enquiry->project }}</p>
                                @endif

                                {{-- The visitor's own answer to "what made you get in
                                     touch?", quoted as such -- it is what they typed,
                                     not a link we resolved to a portfolio piece. --}}
                                @if ($enquiry->prompted_by)
                                    <p class="mt-1.5 text-xs text-brand-300 truncate">&ldquo;{{ $enquiry->prompted_by }}&rdquo;</p>
                                @endif
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-xs text-brand-100/60">{{ $enquiry->created_at->diffForHumans(short: true) }}</p>
                                <p @class([
                                    'mt-1 text-[10px] font-semibold uppercase tracking-wider',
                                    'text-brand-300' => $enquiry->isUnread(),
                                    'text-brand-100/40' => ! $enquiry->isUnread(),
                                ])>{{ $enquiry->isUnread() ? 'New' : 'Read' }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="py-12 text-center text-sm text-brand-100/60">No enquiries yet.</p>
                    @endforelse
                </div>

                <div class="{{ $cardClass }} p-5 sm:p-6">
                    <p class="{{ $tileLabel }}">Where they came from</p>
                    <p class="mt-1 text-xs text-brand-100/60">This month</p>

                    @php $sourceMax = max(1, $enquirySources->max() ?? 0); @endphp

                    <div class="mt-5 space-y-3.5">
                        @forelse ($enquirySources as $label => $count)
                            <div>
                                <div class="flex justify-between text-sm mb-1.5">
                                    <span>{{ $label }}</span>
                                    <span class="tabular-nums text-brand-100/70">{{ $count }}</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-brand-600 to-brand-400"
                                         style="width: {{ round($count / $sourceMax * 100) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="py-6 text-sm text-brand-100/60">Nothing came in this month.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        {{-- ——— Team ——— --}}
        <section>
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <p class="{{ $sectionLabel }}">Team</p>
                <a href="{{ route('timesheets.index') }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All timesheets</a>
            </div>

            {{-- The shape of the year first, then this month's detail. The
                 heatmap answers "is this normal for us", which is the question
                 the per-person bars underneath cannot. --}}
            <div class="mb-3.5">
                <x-charts.work-heatmap :graphs="$workGraph" />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5">
                <div class="{{ $cardClass }} p-5 sm:p-6">
                    <p class="{{ $tileLabel }}">Hours this month</p>

                    @php $hoursMax = max(1, (int) $teamHours->max('minutes')); @endphp

                    <div class="mt-5 space-y-3.5">
                        @forelse ($teamHours as $row)
                            <a href="{{ route('timesheets.show', $row['employee']) }}" class="block group">
                                <div class="flex items-center gap-2.5 mb-1.5">
                                    <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" size="sm" class="shrink-0" />
                                    <span class="text-sm flex-1 truncate group-hover:text-brand-200 transition-colors">{{ $row['employee']->name }}</span>
                                    @if ($row['pending'] > 0)
                                        <span class="text-[10px] text-amber-300">{{ $row['pending'] }} to decide</span>
                                    @endif
                                    <span class="text-sm tabular-nums text-brand-100/80">{{ TimesheetEntry::formatMinutes($row['minutes']) }}</span>
                                </div>
                                <div class="ml-[38px] h-1.5 rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-brand-600 to-brand-400"
                                         style="width: {{ round($row['minutes'] / $hoursMax * 100) }}%"></div>
                                </div>
                            </a>
                        @empty
                            <p class="py-6 text-sm text-brand-100/60">No employee logins yet.</p>
                        @endforelse
                    </div>
                </div>

                <div @class([
                    'rounded-xl p-5 sm:p-6 ring-1',
                    'bg-amber-400/10 ring-amber-400/40' => $teamBehind->isNotEmpty(),
                    'bg-white/5 ring-white/10' => $teamBehind->isEmpty(),
                ])>
                    <p @class(['text-[10px] font-semibold uppercase tracking-[0.16em]', 'text-amber-100' => $teamBehind->isNotEmpty(), 'text-brand-100/70' => $teamBehind->isEmpty()])>
                        Gone quiet this week
                    </p>
                    <p class="mt-1 text-xs text-brand-100/60">
                        {{ $teamBehind->isNotEmpty() ? 'Longest silence first' : 'Nobody to chase' }}
                    </p>

                    @if ($teamBehind->isEmpty())
                        <p class="mt-6 text-sm text-brand-100/70">Everyone has logged something since Monday.</p>
                    @else
                        <div class="mt-4 divide-y divide-white/10">
                            @foreach ($teamBehind as $row)
                                <div class="flex items-center gap-3 py-2.5">
                                    <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" size="sm" class="shrink-0" />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold truncate">{{ $row['employee']->name }}</p>
                                        <p class="text-[11px] text-brand-100/60">
                                            @if ($row['last'])
                                                Last logged {{ $row['last']->format('d M') }}
                                                @if ($row['daysSince'] !== null) &middot; {{ $row['daysSince'] }} {{ Str::plural('day', $row['daysSince']) }} ago @endif
                                            @else
                                                Has never logged an entry
                                            @endif
                                        </p>
                                    </div>
                                    <a href="{{ route('timesheets.show', $row['employee']) }}"
                                       class="shrink-0 inline-flex items-center min-h-[36px] px-3 rounded-md border border-white/20
                                              text-[10px] font-semibold uppercase tracking-wider hover:bg-white/10 transition-colors">
                                        Open
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>

        {{-- ——— Website ——— --}}
        <section>
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <p class="{{ $sectionLabel }}">Website</p>
                <a href="{{ route('portfolio.index') }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">Manage portfolio</a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5">
                <div class="{{ $cardClass }} p-5 sm:p-6">
                    <p class="{{ $tileLabel }}">What is live</p>

                    <div class="mt-5 grid grid-cols-2 gap-5">
                        @foreach ([
                            ['value' => $publishedCount, 'label' => 'Published', 'accent' => false],
                            ['value' => $hiddenCount, 'label' => 'Hidden', 'accent' => false],
                            ['value' => number_format($portfolioReach), 'label' => 'Total reach', 'accent' => true],
                            ['value' => number_format($portfolioEnquiries), 'label' => 'Enquiries driven', 'accent' => true],
                        ] as $stat)
                            <div>
                                <p @class(['text-2xl font-extrabold leading-none tabular-nums', 'text-brand-300' => $stat['accent']])>{{ $stat['value'] }}</p>
                                <p class="mt-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-100/60">{{ $stat['label'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($missingThumbnails > 0)
                        <p class="mt-5 px-3.5 py-2.5 rounded-md bg-amber-400/10 border border-amber-400/30 text-xs text-amber-100">
                            {{ $missingThumbnails }} published {{ Str::plural('piece', $missingThumbnails) }}
                            {{ $missingThumbnails === 1 ? 'has' : 'have' }} no thumbnail — the grid falls back to a video frame.
                        </p>
                    @endif
                </div>

                <div class="{{ $cardClass }} p-5 sm:p-6">
                    <p class="{{ $tileLabel }}">Pieces earning their keep</p>
                    <p class="mt-1 text-xs text-brand-100/60">By the enquiries recorded against them</p>

                    <div class="mt-5 space-y-3.5">
                        @forelse ($topPieces as $piece)
                            <a href="{{ route('portfolio.edit', $piece) }}" class="flex items-center gap-3 group">
                                <div class="w-9 h-12 shrink-0 rounded-md bg-white/10 overflow-hidden">
                                    @if ($piece->thumbnailUrl())
                                        <img src="{{ $piece->thumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold truncate group-hover:text-brand-200 transition-colors">{{ $piece->title }}</p>
                                    <p class="mt-0.5 text-[11px] text-brand-100/60 truncate">
                                        {{ $piece->clientLabel() ?: 'No client linked' }}
                                        @if ($piece->reach) &middot; {{ number_format($piece->reach) }} reach @endif
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-base font-bold tabular-nums text-brand-300">{{ $piece->enquiries }}</p>
                                    <p class="text-[9px] font-semibold uppercase tracking-wider text-brand-100/50">enquiries</p>
                                </div>
                            </a>
                        @empty
                            <p class="py-6 text-sm text-brand-100/60">
                                No piece has enquiries recorded yet. Add them on a piece to see what is working.
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        {{-- ——— Money ——— --}}
        <section>
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <p class="{{ $sectionLabel }}">Money</p>
                {{-- Echoed rather than written as literal markup so the
                     apostrophe is escaped, which DashboardTest pins. --}}
                <p class="text-xs text-brand-100/60">{{ "This Month's Outflow — ".$month->format('F Y') }}</p>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                @php
                    $outflowTiles = [
                        ['label' => 'Total due', 'value' => $money($outflowDue), 'note' => null, 'accent' => false],
                        ['label' => 'Paid', 'value' => $money($outflowPaid), 'accent' => false,
                         'note' => $outflowDue > 0 ? round($outflowPaid / $outflowDue * 100).'% settled' : null],
                        ['label' => 'Still pending', 'value' => $money($outflowPending), 'accent' => $outflowPending > 0,
                         'note' => $outflowPending > 0 ? 'Clear before month end' : 'All settled'],
                        ['label' => 'EMI portion', 'value' => $money($emiThisMonth), 'accent' => false,
                         'note' => $outflowDue > 0 ? round($emiThisMonth / $outflowDue * 100).'% of the total' : null],
                    ];
                @endphp

                @foreach ($outflowTiles as $tile)
                    <div @class([
                        'rounded-xl p-5 ring-1',
                        'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40' => $tile['accent'],
                        'bg-white/5 ring-white/10' => ! $tile['accent'],
                    ])>
                        <p @class(['text-[10px] font-semibold uppercase tracking-[0.16em]', 'text-amber-100' => $tile['accent'], 'text-brand-100/70' => ! $tile['accent']])>{{ $tile['label'] }}</p>
                        <p class="mt-3 text-xl sm:text-2xl font-extrabold leading-none tabular-nums tracking-tight">{{ $tile['value'] }}</p>
                        @if ($tile['note'])
                            <p class="mt-2 text-xs text-brand-100/60">{{ $tile['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Unpaid invoices. The one list on this page about money coming
                 IN rather than going out, so it keeps its own block rather
                 than collapsing into the "still owed" figure above. --}}
            <div class="{{ $cardClass }} p-5 sm:p-6 mt-3.5">
                <div class="flex items-baseline justify-between gap-4">
                    <div>
                        <p class="{{ $tileLabel }}">Unpaid invoices</p>
                        <p class="mt-1 text-xs text-brand-100/60">{{ $money($outstanding) }} open</p>
                    </div>
                    <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}"
                       class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All</a>
                </div>

                <div class="mt-4 divide-y divide-white/10">
                    @forelse ($recentUnpaid as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}"
                           class="flex items-center justify-between gap-3 py-3 group">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold truncate group-hover:text-brand-200 transition-colors">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-brand-100/60 truncate">
                                    {{ $invoice->client->name }}
                                    @if ($invoice->isOverdue())
                                        <span class="text-red-300 font-semibold">&middot; overdue</span>
                                    @endif
                                    @if ($invoice->isPartiallyPaid())
                                        <span class="text-amber-300 font-semibold">&middot; part paid</span>
                                    @endif
                                </p>
                            </div>
                            <p class="shrink-0 text-sm font-bold tabular-nums">{{ $money($invoice->balanceDue()) }}</p>
                        </a>
                    @empty
                        <p class="py-8 text-center text-sm text-brand-100/60">Everything is collected.</p>
                    @endforelse
                </div>
            </div>

            {{-- Collected vs paid out --}}
            <div class="{{ $cardClass }} p-5 sm:p-6 mt-3.5">
                <div class="flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <p class="{{ $tileLabel }}">Collected vs paid out</p>
                        <p class="mt-1 text-xs text-brand-100/60">Last 6 months</p>
                    </div>
                    <div class="flex gap-4 text-[11px] text-brand-100/60">
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-brand-400"></span>Collected</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-white/25"></span>Paid out</span>
                    </div>
                </div>

                @php $peak = max(1, $monthlyCashflow->flatMap(fn ($p) => [$p['income'], $p['expense']])->max()); @endphp

                <div class="mt-6 flex items-end gap-3 sm:gap-6 h-48">
                    @foreach ($monthlyCashflow as $point)
                        <div class="flex-1 flex flex-col items-center h-full">
                            <div class="flex-1 w-full flex items-end justify-center gap-1.5">
                                <div class="w-1/4 min-w-[10px] rounded-t bg-gradient-to-b from-brand-300 to-brand-600"
                                     style="height: {{ max(1, round($point['income'] / $peak * 100)) }}%"
                                     title="Collected {{ $money($point['income'], 0) }}"></div>
                                <div class="w-1/4 min-w-[10px] rounded-t bg-white/20"
                                     style="height: {{ max(1, round($point['expense'] / $peak * 100)) }}%"
                                     title="Paid out {{ $money($point['expense'], 0) }}"></div>
                            </div>
                            <p class="mt-2 text-[11px] text-brand-100/60">{{ $point['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Where cash is stuck --}}
            @if (count($bottlenecks) > 0)
                @php $stuckMax = max(1, collect($bottlenecks)->max('value')); @endphp

                <div class="{{ $cardClass }} p-5 sm:p-6 mt-3.5">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="{{ $tileLabel }}">Where cash is stuck</p>
                        <p class="text-xs text-brand-100/60">Tap a row to go and clear it</p>
                    </div>

                    <div class="mt-5 space-y-4">
                        @foreach ($bottlenecks as $row)
                            <a href="{{ $row['href'] }}" class="block group">
                                <div class="flex justify-between items-baseline text-sm mb-1.5">
                                    <span class="group-hover:text-brand-200 transition-colors">{{ $row['label'] }}</span>
                                    <span class="font-semibold tabular-nums">{{ $money($row['value']) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full" style="width: {{ max(3, round($row['value'] / $stuckMax * 100)) }}%; background: {{ $row['color'] }}"></div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Expense and income mix --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5 mt-3.5">
                @php
                    $mixes = [
                        ['title' => 'Expense mix', 'subtitle' => "This month's due, by type", 'items' => $expenseSplit,
                         'empty' => 'No expenses due this month.'],
                        ['title' => 'Income mix', 'items' => $incomeSplit,
                         'subtitle' => $incomeMode === 'collected'
                            ? 'Collections this month, by client'
                            : 'Invoiced this month, by client — nothing collected yet',
                         'empty' => 'No income recorded this month.'],
                    ];
                @endphp

                @foreach ($mixes as $mix)
                    @php
                        $rows = collect($mix['items'])->filter(fn ($i) => (float) ($i['value'] ?? 0) > 0)->sortByDesc('value')->take(6);
                        $mixMax = max(1, (float) ($rows->max('value') ?? 0));
                    @endphp

                    <div class="{{ $cardClass }} p-5 sm:p-6">
                        <p class="{{ $tileLabel }}">{{ $mix['title'] }}</p>
                        <p class="mt-1 text-xs text-brand-100/60">{{ $mix['subtitle'] }}</p>

                        <div class="mt-5 space-y-3.5">
                            @forelse ($rows as $row)
                                <div>
                                    <div class="flex justify-between items-baseline gap-3 text-sm mb-1.5">
                                        <span class="truncate">{{ $row['label'] }}</span>
                                        <span class="shrink-0 tabular-nums text-brand-100/70">{{ $money($row['value']) }}</span>
                                    </div>
                                    {{-- Per-type colour, the same key the "where cash
                                         is stuck" rows use, so a colour means the
                                         same thing everywhere on the page. --}}
                                    <div class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                                        <div class="h-full rounded-full"
                                             style="width: {{ round($row['value'] / $mixMax * 100) }}%; background-color: {{ $row['color'] ?? '#67BCD4' }}"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="py-6 text-sm text-brand-100/60">{{ $mix['empty'] }}</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
