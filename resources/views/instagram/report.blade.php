@php
    use App\Models\Client;

    /*
     * The monthly Instagram report for one client -- read entirely from
     * local caches, see MonthlyReportController for why nothing here calls
     * Instagram. "Sync now" is the Instagram Insights screen's own action,
     * reused here rather than duplicated.
     *
     * Two contexts share this view: staff
     * (App\Http\Controllers\MonthlyReportController, every var below
     * defaulted to that behaviour) and a client viewing their own report
     * self-service (App\Http\Controllers\Client\MonthlyReportController,
     * $selfService and the route names/URLs passed explicitly). The
     * Studio/Client toggle further down is a STAFF-ONLY preview of what a
     * client sees (Alpine, client-side, no reload); isClient starts (and,
     * for self-service, stays) true either way, so the account strip's
     * Sync button and every studio-only CARD (sections & WhatsApp
     * delivery, the note's edit form, Shoots) share one x-show mechanism
     * -- the difference is that for $selfService those blocks are also
     * conditionally skipped, omitted from the page entirely rather than
     * merely CSS-hidden, since unlike a quiet Sync button these are whole
     * forms and a client's-own-shoots repeat that have no business
     * reaching a client's page source at all. Section selection
     * is also never a client's to change here: always
     * Client::defaultReportSections(), the studio's own standing choice
     * -- see Client\MonthlyReportController.
     */
    $monthParam = $month->format('Y-m');
    $selfService ??= false;
    $reportRouteName ??= 'instagram.report';

    // Carried on every link that should keep showing the same section
    // selection after navigating (Download PDF, the month arrows) --
    // sections_form is what tells the controller "this request has an
    // opinion", see MonthlyReportController::resolveSections(). Never
    // carried for a client: there is no override for them to preserve.
    $sectionParams = $selfService ? [] : ['sections_form' => 1, 'sections' => $enabledSections];
    $monthNavParams ??= $selfService ? [] : ['client' => $client] + $sectionParams;
    $reportPdfUrl ??= $selfService
        ? route('client.instagram.report.pdf', ['month' => $monthParam])
        : route('instagram.report.pdf', ['client' => $client, 'month' => $monthParam] + $sectionParams);
    $backUrl ??= $selfService ? route('client.social') : route('clients.show', $client).'#social';
@endphp

<x-app-layout title="Monthly Report">
    <x-slot name="header">
        <x-page-header :title="$client->name" eyebrow="Monthly Report">
            <x-slot name="actions">
                <a href="{{ $backUrl }}"
                   class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    ← Back
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    @if (! $account)
        <x-card padding="md" class="max-w-lg">
            <p class="text-sm text-brand-100/70">
                @if ($selfService)
                    No Instagram account is connected yet.
                @else
                    No Instagram account is connected for {{ $client->name }} yet.
                @endif
            </p>
            <a href="{{ $backUrl }}"
               class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                Connect Instagram
            </a>
        </x-card>
    @else
        <div class="space-y-6" x-data="{ isClient: {{ $selfService ? 'true' : 'false' }} }">

            {{-- Month switcher + (staff-only) Studio/Client preview + Print --}}
            <div class="flex flex-wrap items-center justify-between gap-4">
                <x-month-nav :route="$reportRouteName" :month="$month" :params="$monthNavParams" class="max-w-xs" />

                <div class="flex flex-wrap items-center gap-2" data-chrome>
                    @if (! $selfService)
                        <div class="inline-flex items-center gap-1 p-1 rounded-xl bg-white/10 ring-1 ring-white/10">
                            <button type="button" @click="isClient = false"
                                    :class="! isClient ? 'bg-white/5 text-white shadow-sm' : 'text-brand-100/60'"
                                    class="min-h-[40px] px-3 rounded-lg text-sm font-semibold transition-colors">
                                Studio view
                            </button>
                            <button type="button" @click="isClient = true"
                                    :class="isClient ? 'bg-white/5 text-white shadow-sm' : 'text-brand-100/60'"
                                    class="min-h-[40px] px-3 rounded-lg text-sm font-semibold transition-colors">
                                Client preview
                            </button>
                        </div>
                    @endif
                    <a href="{{ $reportPdfUrl }}"
                       class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-md bg-brand-500 text-white text-sm font-semibold shadow-sm hover:bg-brand-600">
                        <x-icon name="document" class="w-4 h-4" />
                        Print / PDF
                    </a>
                </div>
            </div>

            {{-- Account strip --}}
            <x-card padding="md" data-chrome x-show="! isClient">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        @if ($account->profile_picture_url)
                            <img src="{{ $account->profile_picture_url }}" alt="" onerror="this.remove()"
                                 class="w-10 h-10 rounded-full object-cover ring-1 ring-white/10">
                        @endif
                        <div>
                            <p class="text-sm font-semibold text-white">{{ $account->handle() }}</p>
                            <p class="text-xs text-brand-100/60">
                                @if ($account->last_synced_at)
                                    Synced {{ $account->last_synced_at->format('j M Y, g:i A') }} IST
                                    ({{ $account->last_synced_at->diffForHumans() }})
                                @else
                                    Never synced
                                @endif
                            </p>
                        </div>
                    </div>
                    @if (! $selfService)
                        <div class="flex items-center gap-3">
                            <p class="text-xs text-brand-100/50">Instagram Graph API · cached</p>
                            <form method="POST" action="{{ route('instagram.insights.sync', $client) }}">
                                @csrf
                                <x-secondary-button type="submit" :disabled="! $account->canSyncNow()">
                                    Sync now
                                </x-secondary-button>
                            </form>
                        </div>
                    @endif
                </div>
                <p class="mt-3 text-xs text-brand-100/60">
                    Showing <span class="font-medium text-brand-100/80">{{ $since->format('j M Y') }}</span>
                    to <span class="font-medium text-brand-100/80">{{ $until->format('j M Y') }}</span> (Asia/Kolkata).
                </p>
            </x-card>

            {{-- Sections + WhatsApp delivery: studio-only controls, not part of
                 the report itself. Omitted entirely for a client, not merely
                 hidden -- unlike the account strip's Sync button above, this
                 one form is worth keeping out of the page source rather than
                 just off screen. --}}
            @if (! $selfService)
            <x-card padding="md" data-chrome x-show="! isClient">
                <details {{ $errors->has('phone') ? 'open' : '' }}>
                    <summary class="cursor-pointer text-sm font-semibold text-white select-none">
                        Report sections &amp; delivery
                    </summary>

                    <div class="mt-4 space-y-5">
                        {{-- One checkbox set, two destinations: "Apply" reloads this
                             page with the ticked sections (a one-off, this download/
                             send only); "Save as default" persists the same ticks as
                             this client's standing preference via formmethod/formaction
                             overriding the form's own GET, so there is nothing to keep
                             in sync between two separate forms. --}}
                        <form method="GET" action="{{ route('instagram.report', $client) }}">
                            @csrf
                            <input type="hidden" name="month" value="{{ $monthParam }}">
                            <input type="hidden" name="sections_form" value="1">

                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">
                                Include in this report
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 mb-4">
                                @foreach (Client::REPORT_SECTIONS as $key => $label)
                                    <label class="flex items-center gap-2.5 rounded-md bg-white/[0.03] ring-1 ring-white/10 px-3 py-2.5 cursor-pointer hover:bg-white/[0.06]">
                                        <input type="checkbox" name="sections[]" value="{{ $key }}"
                                               @checked(in_array($key, $enabledSections, true))
                                               class="rounded border-white/20 bg-white/5 text-brand-400 focus:ring-brand-400">
                                        <span class="text-sm text-white">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <x-secondary-button type="submit">Apply to this report</x-secondary-button>
                                <button type="submit" formmethod="POST" formaction="{{ route('instagram.report.sections', $client) }}"
                                        class="text-sm font-semibold text-brand-300 hover:text-brand-200">
                                    Save as default for {{ $client->name }}
                                </button>
                            </div>
                        </form>

                        <div class="pt-4 border-t border-white/10">
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">
                                Send via WhatsApp
                            </p>
                            @if ($note->whatsapp_sent_at)
                                <p class="text-xs text-emerald-300 mb-2">
                                    Last sent {{ $note->whatsapp_sent_at->format('d M Y, g:i A') }}.
                                </p>
                            @endif
                            <form method="POST" action="{{ route('instagram.report.whatsapp', $client) }}"
                                  class="flex flex-col sm:flex-row sm:items-start gap-3"
                                  onsubmit="return confirm('Send the currently ticked sections as a PDF to this number on WhatsApp?');">
                                @csrf
                                <input type="hidden" name="month" value="{{ $monthParam }}">
                                @foreach ($enabledSections as $key)
                                    <input type="hidden" name="sections[]" value="{{ $key }}">
                                @endforeach
                                <div class="flex-1">
                                    <x-input-label for="phone" value="WhatsApp number" />
                                    <x-text-input id="phone" name="phone" type="text" class="mt-1 w-full"
                                        value="{{ old('phone', $client->phone) }}"
                                        placeholder="e.g. 9876543210" required />
                                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                                    <p class="mt-1 text-[11px] text-brand-100/50">
                                        Sends the ticked sections above as a PDF attachment. Only reaches a number that has messaged the studio in the last 24 hours.
                                    </p>
                                </div>
                                <x-primary-button class="mt-1 sm:mt-6">Send</x-primary-button>
                            </form>
                        </div>
                    </div>
                </details>
            </x-card>
            @endif

            {{-- Note --}}
            <x-card padding="md" class="bg-white/5 ring-1 ring-brand-400/20" id="note">
                <div class="flex items-baseline justify-between gap-3 mb-2">
                    <h3 class="text-sm font-semibold text-white">The month in one paragraph</h3>
                    @if (! $selfService)
                        <p class="text-[11px] text-brand-100/60" data-chrome x-show="! isClient">
                            @if ($note->updated_at)
                                Last edited {{ $note->updated_at->diffForHumans() }}
                            @else
                                Not written yet
                            @endif
                        </p>
                    @endif
                </div>

                @if (! $selfService)
                    <div data-chrome x-show="! isClient">
                        <form method="POST" action="{{ route('instagram.report.note', $client) }}">
                            @csrf
                            <input type="hidden" name="month" value="{{ $monthParam }}">
                            <x-textarea name="note" rows="4"
                                        placeholder="What carried the month, what's planned next -- this is what the client sees on the PDF."
                                        class="w-full">{{ old('note', $note->note) }}</x-textarea>
                            <div class="mt-2 flex justify-end">
                                <x-secondary-button type="submit">Save note</x-secondary-button>
                            </div>
                        </form>
                    </div>
                @endif

                <div x-show="isClient" x-cloak>
                    @if ($note->note)
                        <p class="text-sm text-brand-100/80 leading-relaxed max-w-3xl">{{ $note->note }}</p>
                    @else
                        <p class="text-sm text-brand-100/50 italic">No note written for this month yet.</p>
                    @endif
                </div>
            </x-card>

            {{-- KPIs -- always shown, not a toggleable section: this is the report's
                 headline, the same five numbers regardless of which detail
                 sections below are switched on or off. --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <x-stat-card label="Followers" :value="number_format($overview['followers'])" icon="users" accent="gray">at {{ $until->format('j M') }}</x-stat-card>
                <x-stat-card label="Follower growth"
                    :value="$overview['follower_growth'] !== null ? ($overview['follower_growth'] >= 0 ? '+' : '').number_format($overview['follower_growth']) : '—'"
                    icon="trending-up"
                    :accent="($overview['follower_growth'] ?? 0) > 0 ? 'green' : (($overview['follower_growth'] ?? 0) < 0 ? 'red' : 'gray')">net new this month</x-stat-card>
                <x-stat-card label="Engagement" :value="number_format($overview['engagement'])" icon="sparkles" accent="green">likes, comments, saves, shares</x-stat-card>
                <x-stat-card label="Accounts reached" :value="number_format($overview['reach'])" icon="trending-up" accent="brand" />
                <x-stat-card label="Published" :value="number_format($content->count())" icon="check-circle" accent="brand">posts and reels</x-stat-card>
            </div>

            @php
                $showGrowth = in_array('follower_growth', $enabledSections, true);
                $showBreakdown = in_array('engagement_breakdown', $enabledSections, true);
            @endphp

            {{-- Growth + engagement --}}
            @if ($showGrowth || $showBreakdown)
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                    @if ($showGrowth)
                        <div class="{{ $showBreakdown ? 'lg:col-span-3' : 'lg:col-span-5' }}">
                            <x-charts.metric-trend :days="$followerTrend" title="Follower growth, day by day"
                                :empty="$selfService ? 'No follower data for this month yet — check back after the next sync.' : 'No follower data cached for this month yet — press Sync now.'" />
                        </div>
                    @endif
                    @if ($showBreakdown)
                        <x-card padding="md" class="{{ $showGrowth ? 'lg:col-span-2' : 'lg:col-span-5' }}">
                            <x-section-heading title="Engagement breakdown"
                                subtitle="What the {{ number_format($overview['engagement']) }} total interactions were made of." />
                            <x-charts.bar-list :items="$breakdown" empty="No engagement data cached for this month yet." />
                        </x-card>
                    @endif
                </div>
            @endif

            @php
                $audienceColumns = [];
                if (in_array('age_breakdown', $enabledSections, true)) {
                    $audienceColumns['Age, %'] = ['items' => $ageBreakdown, 'decimals' => '0', 'note' => null];
                }
                if (in_array('gender_breakdown', $enabledSections, true)) {
                    $audienceColumns['Gender, %'] = ['items' => $genderBreakdown, 'decimals' => '0', 'note' => 'Instagram reports gender only where a follower has set it.'];
                }
                if (in_array('top_cities', $enabledSections, true)) {
                    // 0, matching bar-list's own default -- topCities never
                    // passed a decimals prop before this, and city follower
                    // counts are whole numbers anyway.
                    $audienceColumns['Top cities'] = ['items' => $topCities, 'decimals' => 0, 'note' => null];
                }
            @endphp

            {{-- Audience -- omitted entirely once nothing in it is switched on,
                 not just an empty card with a heading and nothing under it. --}}
            @if ($audienceColumns !== [])
                <x-card padding="md">
                    <x-section-heading title="Who is following"
                        subtitle="{{ number_format($overview['followers']) }} followers, as of the last sync." />

                    @if ($audienceSyncedAt)
                        {{-- grid-template-columns is set inline rather than a
                             Tailwind grid-cols-N class: the column count here is
                             1-3 depending on which sections are ticked, and a
                             dynamically interpolated class name would never be
                             found by Tailwind's static file scan. --}}
                        <div class="grid grid-cols-1 gap-6" style="grid-template-columns: repeat({{ count($audienceColumns) }}, minmax(0, 1fr))">
                            @foreach ($audienceColumns as $label => $column)
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">{{ $label }}</p>
                                    <x-charts.bar-list :items="$column['items']" :decimals="$column['decimals']" />
                                    @if ($column['note'])
                                        <p class="mt-3 text-[11px] text-brand-100/50">{{ $column['note'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-4 text-[11px] text-brand-100/50">Audience synced {{ $audienceSyncedAt->diffForHumans() }}.</p>
                    @else
                        <x-empty-state :message="$selfService ? 'Audience demographics haven\'t been synced for this account yet.' : 'Audience demographics haven\'t been synced for this account yet — press Sync now.'" />
                    @endif
                </x-card>
            @endif

            {{-- Publishing summary --}}
            @if (in_array('formats_published', $enabledSections, true))
                <x-card padding="md">
                    <x-section-heading title="What we published" subtitle="{{ $content->count() }} piece(s) this month." />
                    @if ($formats)
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @foreach ($formats as $format)
                                <div class="rounded-lg bg-brand-900/40 ring-1 ring-white/10 px-4 py-3">
                                    <p class="text-2xl font-bold text-white tabular-nums">{{ $format['count'] }}</p>
                                    <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">{{ $format['label'] }}{{ $format['count'] === 1 ? '' : 's' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-empty-state message="Nothing was posted in this month." />
                    @endif
                </x-card>
            @endif

            {{-- Top posts --}}
            @if (in_array('top_posts', $enabledSections, true))
                <x-card padding="none">
                    <div class="p-4 sm:p-5 pb-0">
                        <x-section-heading title="The posts that worked hardest" subtitle="Ranked by accounts reached." />
                    </div>

                    @if ($content->isEmpty())
                        <div class="p-4 sm:p-5">
                            <x-empty-state message="Nothing was posted in this month." />
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                        <th class="px-4 sm:px-5 py-2.5">Content</th>
                                        <th class="px-3 py-2.5">Type</th>
                                        <th class="px-3 py-2.5 text-right">Reach</th>
                                        <th class="px-3 py-2.5 text-right">Views</th>
                                        <th class="px-3 py-2.5 text-right">Engagement</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                    @foreach ($content->take(5) as $item)
                                        <tr>
                                            <td class="px-4 sm:px-5 py-2.5">
                                                <div class="min-w-0">
                                                    @if ($item->permalink)
                                                        <a href="{{ $item->permalink }}" target="_blank" rel="noopener"
                                                           class="text-white font-medium hover:text-brand-300 truncate block max-w-xs">
                                                            {{ $item->shortCaption() }}
                                                        </a>
                                                    @else
                                                        <span class="text-white font-medium truncate block max-w-xs">{{ $item->shortCaption() }}</span>
                                                    @endif
                                                    <span class="text-xs text-brand-100/50">{{ $item->posted_at?->format('j M Y') }}</span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2.5 whitespace-nowrap">
                                                <x-badge color="{{ $item->isReel() ? 'bg-purple-400/15 text-purple-200' : 'bg-white/10 text-brand-100/70' }}">
                                                    {{ $item->typeLabel() }}
                                                </x-badge>
                                            </td>
                                            <td class="px-3 py-2.5 text-right tabular-nums text-white">
                                                {{ $item->metricValue('reach') !== null ? number_format($item->metricValue('reach')) : '—' }}
                                            </td>
                                            <td class="px-3 py-2.5 text-right tabular-nums text-white">
                                                {{ $item->metricValue('views') !== null ? number_format($item->metricValue('views')) : '—' }}
                                            </td>
                                            <td class="px-3 py-2.5 text-right tabular-nums text-white">
                                                {{ $item->metricValue('total_interactions') !== null ? number_format($item->metricValue('total_interactions')) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-card>
            @endif

            {{-- Shoots: studio-only, same reasoning as the sections/WhatsApp
                 card above -- a client sees their own shoots on the
                 dedicated Shoots page already (client.shoots), so this
                 would only repeat it. --}}
            @if (! $selfService && in_array('shoots', $enabledSections, true) && $shoots->isNotEmpty())
                <x-card padding="md" data-chrome x-show="! isClient">
                    <x-section-heading title="Shoots this month" />
                    <ul class="divide-y divide-white/10">
                        @foreach ($shoots as $shoot)
                            <li class="py-2.5 flex items-center gap-3">
                                <span class="text-xs font-semibold text-brand-300 w-14 shrink-0 tabular-nums">{{ $shoot->starts_at?->format('j M') }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-white">{{ $shoot->title }}</p>
                                    @if ($shoot->location)
                                        <p class="text-xs text-brand-100/60">{{ $shoot->location }}</p>
                                    @endif
                                </div>
                                <x-badge status="{{ $shoot->status }}" />
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>
    @endif
</x-app-layout>
