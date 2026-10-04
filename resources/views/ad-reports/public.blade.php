{{--
    A month of paid-ads results on its no-login link, results/{token}.

    Everything shown comes from the stored report JSON (see AdReportImporter
    for its shape) and is printed with {{ }} only: the report is produced by
    an AI connector, so its text is treated as untrusted. Any field may be
    missing or null -- a null number prints as a dash, never as 0, because
    "not available" and "zero" mean different things to a client.

    This is the CLIENT's view, so the report's internal parts are left out on
    purpose: insights and recommendations (the studio's own read of what went
    wrong and what to change), who made each change, the timeline's internal
    flags, and the ad account ids. They stay in the stored JSON.
--}}
@php
    $d = $report->data;
    $meta = is_array($d['report'] ?? null) ? $d['report'] : [];
    $summary = is_array($d['summary'] ?? null) ? $d['summary'] : [];
    $funds = is_array($d['funds'] ?? null) ? $d['funds'] : [];
    $campaigns = collect($d['campaigns'] ?? [])->filter(fn ($c) => is_array($c))->values();

    $money = fn ($v, int $dp = 2) => is_numeric($v) ? '₹'.number_format((float) $v, $dp) : '—';
    // Daily budgets are whole rupees in practice; no ".00" on those.
    $budget = fn ($v) => is_numeric($v) ? '₹'.number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 2) : '—';
    $count = fn ($v) => is_numeric($v) ? number_format((float) $v) : '—';
    $dec = fn ($v) => is_numeric($v) ? number_format((float) $v, 2) : '—';
    $pct = fn ($v) => is_numeric($v) ? number_format((float) $v, 2).'%' : '—';
    $date = function ($v, string $format = 'j M Y') {
        try {
            return is_string($v) && $v !== '' ? \Illuminate\Support\Carbon::parse($v)->format($format) : '—';
        } catch (\Throwable) {
            return (string) $v;
        }
    };
    $status = fn ($v) => ucfirst(strtolower(str_replace('_', ' ', (string) $v)));
    $lines = fn ($v) => collect(is_array($v) ? $v : [])->filter(fn ($line) => is_string($line) && trim($line) !== '')->values();

    $clientName = $report->client?->name ?? ($meta['client'] ?? 'Client');
    $platform = $meta['platform'] ?? $report->platform;
    $resultsLabel = ucfirst(str_replace('_', ' ', (string) ($summary['results_type'] ?? 'results')));

    /* ---- the month, one column per day ---- */
    $start = $report->period_start->copy();
    $end = $report->period_end->copy();

    $charges = [];
    foreach ((array) ($funds['daily_charges'] ?? []) as $charge) {
        if (is_array($charge) && is_string($charge['date'] ?? null) && is_numeric($charge['amount'] ?? null)) {
            $charges[$charge['date']] = ($charges[$charge['date']] ?? 0) + (float) $charge['amount'];
        }
    }
    $gaps = array_flip(array_filter((array) ($funds['no_charge_dates'] ?? []), 'is_string'));
    $topUps = collect($funds['top_ups'] ?? [])->filter(fn ($t) => is_array($t) && is_string($t['date'] ?? null));
    $upsByDay = $topUps->groupBy('date');
    $maxCharge = max([1.0, ...array_values($charges)]);

    $days = [];
    for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
        $key = $day->toDateString();
        $days[] = [
            'date' => $key,
            'n' => $day->day,
            'amount' => $charges[$key] ?? null,
            'gap' => isset($gaps[$key]),
            'up' => $upsByDay->get($key, collect())->sum(fn ($t) => (float) ($t['amount'] ?? 0)),
        ];
    }

    /* ---- campaigns: cost per result, cheapest marked ---- */
    $averageCpr = is_numeric($summary['cost_per_result'] ?? null) ? (float) $summary['cost_per_result'] : null;
    $withCpr = $campaigns->filter(fn ($c) => is_numeric($c['cost_per_result'] ?? null) && (float) ($c['results'] ?? 0) > 0);
    $maxCpr = max([1.0, ...$withCpr->map(fn ($c) => (float) $c['cost_per_result'])->all()]);

    $budgetChanges = collect($d['budget_changes'] ?? [])->filter(fn ($b) => is_array($b))->values();
    $timeline = collect($d['timeline'] ?? [])->filter(fn ($t) => is_array($t))->values();
@endphp

<x-public-layout :title="$platform.' report — '.$clientName.' — '.$report->periodLabel()"
                 description="Your monthly advertising results, prepared by Chakra Productions.">
    @push('styles')
        @vite('resources/css/ad-report.css')
        <meta name="robots" content="noindex, nofollow">
    @endpush

    <div class="ar-doc">
        <header class="ar-head">
            <div>
                <div class="ar-eyebrow">{{ $platform }} report</div>
                <h1>{{ $clientName }}</h1>
                <div class="ar-head__period">
                    {{ $report->periodLabel() }} · {{ $start->format('j M') }} – {{ $end->format('j M Y') }}
                </div>
            </div>
            @if ($report->client?->logoUrl())
                <img src="{{ $report->client->logoUrl() }}" alt="{{ $clientName }}" class="ar-head__logo">
            @endif
        </header>

        <div class="ar-actions ar-noprint">
            <button type="button" class="ar-btn" onclick="window.print()">Print / save as PDF</button>
        </div>

        {{-- ============================================== At a glance --}}
        <section class="ar-sheet">
            <h2>At a glance</h2>
            <p class="ar-sheet__lead">Everything the ads did in {{ $report->periodLabel() }}.</p>

            <div class="ar-kpis ar-kpis--hero">
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Amount spent</div>
                    <div class="ar-kpi__value">{{ $money($summary['amount_spent'] ?? null) }}</div>
                    <div class="ar-kpi__hint">{{ $count($summary['campaigns_with_spend'] ?? null) }} campaigns ran</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">{{ $resultsLabel }}</div>
                    <div class="ar-kpi__value">{{ $count($summary['results'] ?? null) }}</div>
                    <div class="ar-kpi__hint">The result these ads were run for</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Cost per result</div>
                    <div class="ar-kpi__value">{{ $money($summary['cost_per_result'] ?? null) }}</div>
                    <div class="ar-kpi__hint">Amount spent ÷ results</div>
                </div>
            </div>

            <div class="ar-kpis">
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Reach</div>
                    <div class="ar-kpi__value">{{ $count($summary['reach'] ?? null) }}</div>
                    <div class="ar-kpi__hint">People who saw an ad, counted once</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Impressions</div>
                    <div class="ar-kpi__value">{{ $count($summary['impressions'] ?? null) }}</div>
                    <div class="ar-kpi__hint">Times an ad was shown</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Frequency</div>
                    <div class="ar-kpi__value">{{ $dec($summary['frequency'] ?? null) }}</div>
                    <div class="ar-kpi__hint">Times each person saw one, on average</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Clicks</div>
                    <div class="ar-kpi__value">{{ $count($summary['clicks'] ?? null) }}</div>
                    <div class="ar-kpi__hint">{{ $count($summary['link_clicks'] ?? null) }} of them on the link</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Click-through rate</div>
                    <div class="ar-kpi__value">{{ $pct($summary['ctr_percent'] ?? null) }}</div>
                    <div class="ar-kpi__hint">Share of impressions clicked</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Cost per click</div>
                    <div class="ar-kpi__value">{{ $money($summary['cpc'] ?? null) }}</div>
                    <div class="ar-kpi__hint">Amount spent ÷ clicks</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Cost per 1,000 views</div>
                    <div class="ar-kpi__value">{{ $money($summary['cpm'] ?? null) }}</div>
                    <div class="ar-kpi__hint">CPM — price of attention</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Money added</div>
                    <div class="ar-kpi__value">{{ $money($funds['total_added'] ?? null) }}</div>
                    <div class="ar-kpi__hint">{{ $topUps->count() }} top-ups this month</div>
                </div>
            </div>
        </section>

        {{-- ================================================ Campaigns --}}
        @if ($campaigns->isNotEmpty())
            <section class="ar-sheet">
                <h2>Campaigns</h2>
                <p class="ar-sheet__lead">What each campaign spent and what it brought in.</p>

                @if ($withCpr->isNotEmpty())
                    <h3>Cost per result, by campaign</h3>
                    <div class="ar-bars" role="img"
                         aria-label="Cost per result for each campaign{{ $averageCpr !== null ? ', against an average of '.$money($averageCpr) : '' }}">
                        @foreach ($withCpr as $c)
                            @php
                                $cpr = (float) $c['cost_per_result'];
                                $fill = ($c['best_performer'] ?? false) === true
                                    ? 'ar-bar__fill--best'
                                    : ($averageCpr !== null && $cpr > $averageCpr ? 'ar-bar__fill--high' : '');
                            @endphp
                            <div class="ar-bar">
                                <span class="ar-bar__label">{{ $c['name'] }}</span>
                                <span class="ar-bar__track">
                                    <span class="ar-bar__fill {{ $fill }}" style="width: {{ round($cpr / $maxCpr * 100, 1) }}%"></span>
                                </span>
                                <span class="ar-bar__value">{{ $money($cpr) }}</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($averageCpr !== null)
                        <p class="ar-fine">
                            Average across all campaigns: <strong>{{ $money($averageCpr) }}</strong>.
                            Green is the best performer; amber costs more than the average.
                        </p>
                    @endif
                @endif

                @foreach ($campaigns as $c)
                    @php $best = ($c['best_performer'] ?? false) === true; @endphp
                    <div class="ar-camp {{ $best ? 'ar-camp--best' : '' }}">
                        <div class="ar-camp__top">
                            <span class="ar-camp__name">{{ $c['name'] }}</span>
                            @if (filled($c['status'] ?? null))
                                <span class="ar-pill {{ strtoupper((string) $c['status']) === 'ACTIVE' ? 'ar-pill--active' : '' }}">{{ $status($c['status']) }}</span>
                            @endif
                            @if ($best)
                                <span class="ar-pill ar-pill--best">Best performer</span>
                            @endif
                        </div>
                        <div class="ar-camp__meta">
                            @if (filled($c['theme'] ?? null)){{ $c['theme'] }} · @endif
                            @if (filled($c['launched'] ?? null))Launched {{ $date($c['launched']) }} · @endif
                            {{ $status($c['objective'] ?? '') }}
                        </div>
                        <div class="ar-camp__nums">
                            <div><b>{{ $money($c['amount_spent'] ?? null) }}</b><span>spent</span></div>
                            <div><b>{{ $count($c['results'] ?? null) }}</b><span>results</span></div>
                            <div><b>{{ $money($c['cost_per_result'] ?? null) }}</b><span>per result</span></div>
                        </div>
                        @if (filled($c['note'] ?? null))
                            <div class="ar-camp__note">{{ $c['note'] }}</div>
                        @endif
                    </div>
                @endforeach

                <h3>Delivery, campaign by campaign</h3>
                <div class="ar-scroll">
                    <table class="ar-table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th class="num">Reach</th>
                                <th class="num">Impressions</th>
                                <th class="num">Freq.</th>
                                <th class="num">Clicks</th>
                                <th class="num">Link clicks</th>
                                <th class="num">CTR</th>
                                <th class="num">CPC</th>
                                <th class="num">CPM</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($campaigns as $c)
                                <tr>
                                    <td>{{ $c['name'] }}</td>
                                    <td class="num">{{ $count($c['reach'] ?? null) }}</td>
                                    <td class="num">{{ $count($c['impressions'] ?? null) }}</td>
                                    <td class="num">{{ $dec($c['frequency'] ?? null) }}</td>
                                    <td class="num">{{ $count($c['clicks'] ?? null) }}</td>
                                    <td class="num">{{ $count($c['link_clicks'] ?? null) }}</td>
                                    <td class="num">{{ $pct($c['ctr_percent'] ?? null) }}</td>
                                    <td class="num">{{ $money($c['cpc'] ?? null) }}</td>
                                    <td class="num">{{ $money($c['cpm'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="ar-fine">
                    Reach is not added up across campaigns: the same person can see more than one,
                    so the account's reach at the top counts each person once.
                </p>
            </section>
        @endif

        {{-- ==================================================== Funds --}}
        <section class="ar-sheet">
            <h2>Money in, money out</h2>
            <p class="ar-sheet__lead">The ad account is prepaid: money is added, and Meta charges it day by day.</p>

            <div class="ar-kpis">
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Added</div>
                    <div class="ar-kpi__value">{{ $money($funds['total_added'] ?? null) }}</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Charged for ads</div>
                    <div class="ar-kpi__value">{{ $money($funds['total_billed'] ?? null) }}</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Difference</div>
                    <div class="ar-kpi__value">{{ $money($funds['difference'] ?? null) }}</div>
                </div>
                <div class="ar-kpi">
                    <div class="ar-kpi__label">Days with no charge</div>
                    <div class="ar-kpi__value">{{ count($gaps) }}</div>
                </div>
            </div>
            @if (filled($funds['difference_note'] ?? null))
                <p class="ar-fine">{{ $funds['difference_note'] }}</p>
            @endif

            <h3>Charges through the month</h3>
            <div class="ar-chart-max">Tallest bar: {{ $money($maxCharge) }}</div>
            <div class="ar-days" role="img"
                 aria-label="Daily ad charges for {{ $report->periodLabel() }}, with {{ count($gaps) }} days without a charge">
                @foreach ($days as $day)
                    <div class="ar-day"
                         title="{{ $date($day['date'], 'j M') }}: {{ $day['amount'] !== null ? $money($day['amount']).' charged' : ($day['gap'] ? 'no charge' : 'no data') }}{{ $day['up'] > 0 ? ' · '.$money($day['up']).' added' : '' }}">
                        @if ($day['up'] > 0)
                            <span class="ar-day__up" aria-hidden="true">+</span>
                        @endif
                        @if ($day['amount'] !== null)
                            <span class="ar-day__bar" style="height: {{ max(2, round($day['amount'] / $maxCharge * 100, 1)) }}%"></span>
                        @elseif ($day['gap'])
                            <span class="ar-day__gap"></span>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="ar-axis" aria-hidden="true">
                @foreach ($days as $day)
                    <span>{{ $day['n'] === 1 || $day['n'] % 5 === 0 ? $day['n'] : '' }}</span>
                @endforeach
            </div>
            <div class="ar-legend">
                <span><i class="is-bar"></i>Charged that day</span>
                <span><i class="is-gap"></i>No charge</span>
                <span><i class="is-up"></i>Money added</span>
            </div>
            @if (filled($funds['no_charge_note'] ?? null))
                <div class="ar-note">{{ $funds['no_charge_note'] }}</div>
            @endif

            @if ($topUps->isNotEmpty())
                <h3>Money added</h3>
                <div class="ar-scroll">
                    <table class="ar-table">
                        <thead>
                            <tr><th>Date</th><th>Time</th><th>Method</th><th class="num">Amount</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($topUps as $t)
                                <tr>
                                    <td>{{ $date($t['date']) }}</td>
                                    <td>{{ $t['time'] ?? '—' }}</td>
                                    <td>{{ $t['method'] ?? '—' }}</td>
                                    <td class="num">{{ $money($t['amount'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ================================================= Changes --}}
        @if ($budgetChanges->isNotEmpty() || $timeline->isNotEmpty())
            <section class="ar-sheet">
                <h2>What changed on the account</h2>
                <p class="ar-sheet__lead">Campaigns started, paused and re-budgeted during the month.</p>

                @if ($budgetChanges->isNotEmpty())
                    <h3>Daily budget changes</h3>
                    <div class="ar-scroll">
                        <table class="ar-table">
                            <thead>
                                <tr><th>When</th><th>Ad set</th><th class="num">From</th><th class="num">To</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($budgetChanges as $b)
                                    <tr>
                                        <td>{{ $date($b['date'] ?? null, 'j M') }}{{ filled($b['time'] ?? null) ? ', '.$b['time'] : '' }}</td>
                                        <td>{{ $b['ad_set'] ?? '—' }}</td>
                                        <td class="num">{{ $budget($b['daily_budget_from'] ?? null) }}</td>
                                        <td class="num">{{ $budget($b['daily_budget_to'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($timeline->isNotEmpty())
                    <h3>Timeline</h3>
                    <ol class="ar-tl">
                        @foreach ($timeline as $t)
                            <li>
                                <div class="ar-tl__date">
                                    {{ $date($t['date'] ?? null, 'j M') }}
                                </div>
                                <div>{{ $t['event'] ?? '' }}</div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif

        <footer class="ar-foot">
            Prepared by Chakra Productions
            @if (filled($meta['source'] ?? null)) · Source: {{ $meta['source'] }} @endif
            @if (filled($meta['generated_at'] ?? null)) · Generated {{ $date($meta['generated_at']) }} @endif
            @if (filled($meta['attribution'] ?? null))
                <div>Results counted using {{ $meta['attribution'] }}.</div>
            @endif
        </footer>
    </div>
</x-public-layout>
