@php
    $plain = session('saas_token_plain');
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
            <div class="rounded-xl bg-brand-50 ring-1 ring-brand-300 p-4" x-data="{ copied: false }">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">New token</p>
                <p class="mt-1 text-xs text-brand-900/70">
                    Copy it now and put it wherever this software's own backup/license-check script reads its config
                    from. It cannot be shown again — issuing a new one would silently retire this one.
                </p>
                <div class="mt-3">
                    <code class="block w-full overflow-x-auto rounded-md bg-white px-3 py-2.5 text-xs
                                 text-gray-900 ring-1 ring-gray-200 select-all">{{ $plain }}</code>
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
                    <p class="text-sm text-gray-600">
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
                    <p class="text-sm text-gray-600">
                        Billed yearly via <a href="{{ route('recurring.edit', $product->recurringInvoice) }}" class="font-semibold text-brand-600 hover:text-brand-800">its recurring invoice schedule</a>.
                        Paying that invoice in full extends AMC by a year automatically.
                    </p>
                @else
                    <p class="text-sm text-gray-600 mb-3">
                        Not set up yet. This creates a yearly recurring invoice schedule tied to this product —
                        each time it's paid in full, AMC is extended by a year.
                    </p>
                    <form method="POST" action="{{ route('saas-products.setup-amc', $product) }}"
                          class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_auto] gap-3 items-end">
                        @csrf
                        <div>
                            <x-input-label for="amount" value="Yearly amount" />
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
                <div class="divide-y divide-gray-100">
                    @foreach ($backups as $backup)
                        <div class="py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $backup->taken_at->format('j M Y, g:i A') }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">
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
