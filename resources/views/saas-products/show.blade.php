@php
    $plain = session('saas_token_plain');
    $amcFrequencyLabel = $product->recurringInvoice
        ? \App\Models\RecurringInvoice::FREQUENCIES[$product->recurringInvoice->frequency]
        : null;
    $formatBytes = function (int $bytes): string {
        if ($bytes < 1024) return $bytes.' B';
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024) return number_format($value, 1).' '.$unit;
        }
        return number_format($value, 1).' TB';
    };
@endphp

<x-app-layout title="{{ $product->name }}">
    <x-slot name="header">
        <x-page-header :title="$product->name" eyebrow="App Studio"
                       :subtitle="$product->client->name">
            <x-slot name="actions">
                <x-btn :href="route('saas-products.index')" variant="secondary">All products</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        {{-- The one and only sighting of a freshly issued token. --}}
        @if ($plain)
            <div class="rounded-xl bg-white/5 ring-1 ring-brand-300 p-4" x-data="{ copied: false }">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-200">New token</p>
                <p class="mt-1 text-xs text-brand-100/70">
                    Copy it now and put it wherever this software's own backup/license-check script reads its config
                    from. It cannot be shown again — issuing a new one would silently retire this one.
                </p>
                <div class="mt-3">
                    <code class="block w-full overflow-x-auto rounded-md bg-white/5 px-3 py-2.5 text-xs
                                 text-white ring-1 ring-white/10 select-all">{{ $plain }}</code>
                    <button type="button"
                            @click="navigator.clipboard.writeText($el.previousElementSibling.textContent.trim());
                                    copied = true; setTimeout(() => copied = false, 2000)"
                            class="mt-2 inline-flex items-center min-h-[36px] px-3 rounded-md bg-brand-400 text-brand-900
                                   text-[11px] font-semibold uppercase tracking-wider hover:bg-brand-500 transition-colors">
                        <span x-show="! copied">Copy token</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
            </div>
        @endif

        <x-card padding="md">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-badge :status="$product->status()" class="text-sm" />
                    <p class="text-sm text-brand-100/70">
                        @if ($product->is_suspended)
                            Suspended {{ $product->suspended_at->diffForHumans() }} by {{ $product->suspendedBy->name ?? 'an admin' }}.
                            Its own software will see this on its next license check.
                        @elseif ($product->amc_paid_until)
                            AMC paid until {{ $product->amc_paid_until->format('j M Y') }}.
                        @else
                            AMC has never been billed for this product.
                        @endif
                    </p>
                </div>

                @can('saas-products.manage')
                    <div class="flex items-center gap-2">
                        @if ($product->is_suspended)
                            <form method="POST" action="{{ route('saas-products.reinstate', $product) }}">
                                @csrf
                                <x-btn type="submit" size="sm">Reinstate</x-btn>
                            </form>
                        @else
                            <form method="POST" action="{{ route('saas-products.suspend', $product) }}"
                                  onsubmit="return confirm('Suspend {{ $product->name }}? Its own software will start showing this the next time it checks its license.');">
                                @csrf
                                <x-btn type="submit" size="sm" variant="secondary">Suspend</x-btn>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>
        </x-card>

        @can('saas-products.manage')
            <x-card padding="md">
                <x-section-heading title="AMC billing" />

                @if ($product->recurringInvoice)
                    <p class="text-sm text-brand-100/70">
                        Billed {{ strtolower($amcFrequencyLabel) }}
                        via <a href="{{ route('recurring.edit', $product->recurringInvoice) }}" class="font-semibold text-brand-300 hover:text-brand-200">its recurring invoice schedule</a>.
                        Paying that invoice in full extends AMC by one {{ strtolower($amcFrequencyLabel) }} period automatically.
                    </p>
                @else
                    <p class="text-sm text-brand-100/70 mb-3">
                        Not set up yet. This creates a recurring invoice schedule tied to this product —
                        each time it's paid in full, AMC is extended by one billing period.
                    </p>
                    <form method="POST" action="{{ route('saas-products.setup-amc', $product) }}"
                          class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_1fr_auto] gap-3 items-end">
                        @csrf
                        <div>
                            <x-input-label for="frequency" value="Bill" />
                            <x-select id="frequency" name="frequency" class="mt-1 w-full">
                                <option value="monthly" @selected(old('frequency') === 'monthly')>Monthly</option>
                                <option value="quarterly" @selected(old('frequency') === 'quarterly')>Quarterly</option>
                                <option value="yearly" @selected(old('frequency', 'yearly') === 'yearly')>Yearly</option>
                            </x-select>
                            <x-input-error :messages="$errors->get('frequency')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="amount" value="Amount per period" />
                            <x-text-input id="amount" name="amount" type="number" step="0.01" min="0"
                                          class="mt-1 w-full" value="{{ old('amount') }}" />
                            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="next_run_on" value="First invoice on" />
                            <x-text-input id="next_run_on" name="next_run_on" type="date"
                                          class="mt-1 w-full" value="{{ old('next_run_on') }}" />
                            <x-input-error :messages="$errors->get('next_run_on')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="due_days" value="Due within (days)" />
                            <x-text-input id="due_days" name="due_days" type="number" min="0"
                                          class="mt-1 w-full" placeholder="e.g. 14" value="{{ old('due_days') }}" />
                        </div>
                        <x-btn type="submit">Set up billing</x-btn>
                    </form>
                @endif
            </x-card>
        @endcan

        <x-card padding="md">
            <div class="flex items-center justify-between">
                <x-section-heading title="Backups" :subtitle="$backups->count().' version(s), '.$formatBytes($totalBytes).' total. Keeps the last '.$product->backup_retention_count.'.'" />
            </div>

            @if ($backups->isEmpty())
                <x-empty-state message="No backups uploaded yet. They land here automatically once the software's own backup script starts pushing to the API." />
            @else
                <div class="divide-y divide-white/10">
                    @foreach ($backups as $backup)
                        <div class="py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-white">{{ $backup->taken_at->format('j M Y, g:i A') }}</p>
                                <p class="text-xs text-brand-100/60 mt-0.5">
                                    {{ $formatBytes($backup->size_bytes) }}
                                    &middot; {{ substr($backup->checksum, 0, 12) }}&hellip;
                                </p>
                            </div>
                            <x-btn :href="route('saas-products.backups.download', [$product, $backup])" size="sm" variant="secondary">Download</x-btn>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        @can('saas-products.manage')
            @php
                $tokenPlaceholder = $plain ?? '<YOUR_TOKEN>';
                $curlHeader = "-H \"Authorization: Bearer {$tokenPlaceholder}\"";
            @endphp
            <x-card padding="md">
                <div class="flex items-center justify-between gap-3">
                    <x-section-heading title="API access" subtitle="What this product's own backup/license-check script calls, and the token it authenticates with." />
                    <form method="POST" action="{{ route('saas-products.reissue-token', $product) }}"
                          onsubmit="return confirm('Issue a new token for {{ $product->name }}? The current one stops working immediately — anything still using it will start failing until it is updated.');">
                        @csrf
                        <x-btn type="submit" size="sm" variant="secondary">Reissue token</x-btn>
                    </form>
                </div>

                <div class="mt-3 space-y-4 text-xs">
                    <div>
                        <p class="font-semibold text-brand-100/80 mb-1">Upload a backup</p>
                        <code class="block overflow-x-auto rounded-md bg-brand-900/40 px-3 py-2 text-white ring-1 ring-white/10">curl -X POST {{ route('api.saas.backups.store') }} \<br>
&nbsp;&nbsp;{{ $curlHeader }} \<br>
&nbsp;&nbsp;-F "file=@/path/to/backup.sql.gz"</code>
                    </div>
                    <div>
                        <p class="font-semibold text-brand-100/80 mb-1">List backups (for a restore script to pick a version)</p>
                        <code class="block overflow-x-auto rounded-md bg-brand-900/40 px-3 py-2 text-white ring-1 ring-white/10">curl {{ route('api.saas.backups.index') }} {{ $curlHeader }}</code>
                    </div>
                    <div>
                        <p class="font-semibold text-brand-100/80 mb-1">Check the license (call on startup, then on a timer)</p>
                        <code class="block overflow-x-auto rounded-md bg-brand-900/40 px-3 py-2 text-white ring-1 ring-white/10">curl {{ route('api.saas.license') }} {{ $curlHeader }}</code>
                    </div>
                    <div>
                        <p class="font-semibold text-brand-100/80 mb-1">Fetch this product's own configuration</p>
                        <code class="block overflow-x-auto rounded-md bg-brand-900/40 px-3 py-2 text-white ring-1 ring-white/10">curl {{ route('api.saas.config') }} {{ $curlHeader }}</code>
                        <p class="mt-1 text-brand-100/60">Returns <code>name</code>, <code>backup_retention_count</code> and <code>amc_frequency</code> -- read fresh every call, so changing the retention count here takes effect without redeploying the client software.</p>
                    </div>
                </div>

                @if (Route::has('developer.index'))
                    <p class="mt-4 text-xs text-brand-100/60">
                        Full interactive reference, every endpoint: <a href="{{ route('developer.index') }}" class="font-semibold text-brand-300 hover:text-brand-200">Developer</a>.
                    </p>
                @endif
            </x-card>

            {{-- A self-contained prompt for an AI coding agent working in
                 THIS product's own codebase, not Chakra Portal's -- it has
                 no access to this app's docs/ folder or a logged-in session
                 to fetch the Swagger JSON from, so everything it needs to
                 implement the integration has to travel in the one paste.
                 Real name, base URL and (fresh) token substituted in; the
                 rest is the same shape as docs/SAAS_INTEGRATION.md,
                 condensed into an imperative task list rather than prose. --}}
            @php
                // Plain PHP, not Blade mustaches -- this whole block is a
                // heredoc, which Blade never re-scans once it's assigned to
                // a string, so every dynamic value has to be a real PHP
                // variable interpolated by PHP itself, computed up front.
                $storeUrl = route('api.saas.backups.store');
                $indexUrl = route('api.saas.backups.index');
                $licenseUrl = route('api.saas.license');
                $configUrl = route('api.saas.config');
                $baseUrl = rtrim((string) config('app.url'), '/');
                $productName = $product->name;

                $aiGuide = <<<GUIDE
                    You're adding backup + license-check integration to "{$productName}" so Chakra Portal
                    (the studio maintaining this software) can track backups and enforce AMC billing status.

                    Base URL: {$baseUrl}
                    Auth: every request needs `Authorization: Bearer {$tokenPlaceholder}` -- no session, no cookie, no CSRF.
                    Rate limit: 20 requests/minute per token (generous for scheduled backups + hourly license checks).

                    Implement all three of these:

                    1. Backup upload (cron, twice a day or whatever fits this software's own backup cycle)
                       POST {$storeUrl}
                       multipart/form-data: `file` (the backup itself, any name, up to 1 GB), `taken_at` (optional
                       ISO 8601, defaults to now -- send when the backup actually started if it takes a while).
                       Returns 201 with {id, taken_at, size_bytes, checksum} -- checksum is SHA-256 of the exact
                       bytes received; compare it locally if you want to confirm nothing got mangled in transit.
                       Retention is automatic server-side -- nothing to call to prune old backups.

                    2. License check (on startup, then on a timer -- hourly is plenty, this is not per-request)
                       GET {$licenseUrl}
                       Returns {status, message, amc_paid_until}. status is one of:
                         - "active"    -> run normally
                         - "overdue"   -> keep running, but show `message` somewhere visible in this software's own UI
                         - "suspended" -> Chakra Portal can only ever answer truthfully; it cannot reach into this
                                          server and stop anything itself. YOU decide what "suspended" does here --
                                          typically a hard block screen or refusing writes. This is the one status
                                          that needs a real product decision, not just wiring.
                       Show `message` verbatim rather than writing your own copy for overdue/suspended -- it already
                       says the right thing and changes if Chakra's wording changes.
                       Cache the last known answer so a momentary network blip doesn't read as a suspension.

                    3. Restore support (list + download, for whenever this is actually needed)
                       GET {$indexUrl} -> {"data": [{id, taken_at, size_bytes, checksum}, ...]}, newest first
                       GET {$indexUrl}/{id}/download -> the raw file, streamed

                    Optional: GET {$configUrl} returns {name, backup_retention_count, amc_frequency}
                    if this software wants to read its own retention/billing config rather than hardcoding it.

                    If the token above still says <YOUR_TOKEN>, stop and ask a Chakra Portal admin to issue one from
                    this product's own page (SaaS Products -> {$productName}) -- it's shown once, on screen,
                    right after creation or a reissue, and cannot be retrieved again after that.
                    GUIDE;
            @endphp
            <x-card padding="md" x-data="{ copied: false }">
                <x-section-heading title="AI setup guide"
                                   subtitle="A self-contained prompt for Claude (or any coding agent) working in this product's own codebase -- paste it as the first message." />

                <div class="mt-3">
                    <pre class="whitespace-pre-wrap overflow-x-auto rounded-md bg-brand-900/40 px-3 py-2.5 text-xs text-white ring-1 ring-white/10 max-h-96 overflow-y-auto">{{ $aiGuide }}</pre>
                    <button type="button"
                            @click="navigator.clipboard.writeText($el.previousElementSibling.textContent.trim());
                                    copied = true; setTimeout(() => copied = false, 2000)"
                            class="mt-2 inline-flex items-center min-h-[36px] px-3 rounded-md bg-brand-400 text-brand-900
                                   text-[11px] font-semibold uppercase tracking-wider hover:bg-brand-500 transition-colors">
                        <span x-show="! copied">Copy AI setup prompt</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
            </x-card>
        @endcan

        @can('saas-products.delete')
            <x-card padding="md">
                <x-section-heading title="Danger zone" />
                <form method="POST" action="{{ route('saas-products.destroy', $product) }}"
                      onsubmit="return confirm('Delete {{ $product->name }}? This deletes every backup on file and cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <x-btn type="submit" size="sm" variant="danger">Delete this product</x-btn>
                </form>
            </x-card>
        @endcan
    </div>
</x-app-layout>
