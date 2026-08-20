@php
    /*
     * The studio's Notion integration, as an admin configures it once.
     *
     * Mirrors Setup -> Instagram/WhatsApp deliberately: same shape, same
     * reasoning about what is shown and what is never shown back.
     */
    $configured = $settings->isConfigured();
@endphp

<x-app-layout title="Notion">
    <x-slot name="header">
        <x-page-header
            title="Notion Integration"
            subtitle="One integration token for the whole studio. Content and shoots are pulled from Notion; nothing is ever written back." />
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">

        <x-card padding="md">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">API key</p>
                    @if ($configured)
                        <x-badge status="active">Set</x-badge>
                    @else
                        <x-badge status="overdue">Not set</x-badge>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Last synced</p>
                    <p class="text-sm font-medium text-gray-900">
                        {{ $lastSynced?->diffForHumans() ?? 'Never' }}
                    </p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Items cached</p>
                    <p class="text-sm font-medium text-gray-900">{{ number_format($counts->sum()) }}</p>
                </div>
            </div>
        </x-card>

        <x-card padding="md">
            <form method="POST" action="{{ route('notion.update') }}">
                @csrf
                @method('PUT')

                <x-section-heading
                    title="Integration token"
                    subtitle="notion.so/my-integrations → your integration → Internal Integration Secret." />

                <div class="mb-4">
                    <x-input-label for="api_key" value="Notion Integration Token" />
                    <x-text-input id="api_key" name="api_key" type="password" class="mt-1 w-full font-mono"
                                  autocomplete="new-password"
                                  placeholder="{{ $configured ? 'Saved — leave blank to keep it' : 'ntn_...' }}" />
                    <x-input-error :messages="$errors->get('api_key')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">
                        Stored encrypted and never shown again. Leaving this blank keeps the current one. Give it
                        <span class="font-medium">read content</span> access only — nothing here ever writes back
                        to Notion, and the token does not need to.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>Save</x-primary-button>
                    @if ($settings->updatedBy)
                        <span class="text-xs text-gray-500">
                            Last changed by {{ $settings->updatedBy->name }}, {{ $settings->updated_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </form>
        </x-card>

        <x-card padding="md">
            <div class="flex items-start justify-between gap-4">
                <x-section-heading
                    title="Databases the integration can see"
                    subtitle="Each one has to be shared with the integration inside Notion — this app cannot do that part." />
                @if ($configured)
                    <form method="POST" action="{{ route('notion.recheck') }}" class="shrink-0">
                        @csrf
                        <x-secondary-button type="submit">Re-check now</x-secondary-button>
                    </form>
                @endif
            </div>

            @if (! $configured)
                <x-empty-state message="Save an API key above to see which databases it can reach." />
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($sources as $key => $source)
                        <li class="py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">{{ $source['label'] }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ number_format($counts[$key] ?? 0) }} item(s) cached
                                </p>
                            </div>
                            @if ($availability[$key] ?? false)
                                <x-badge status="active">Shared</x-badge>
                            @else
                                <x-badge status="overdue">Not shared</x-badge>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if (in_array(false, $availability, true))
                    <p class="mt-4 text-xs text-amber-700 bg-amber-50 ring-1 ring-amber-100 rounded-lg px-3 py-2">
                        In Notion: open the database → ••• → Connections → add your integration. Then press
                        <span class="font-medium">Re-check now</span> above — sharing is not detected automatically
                        for up to 24 hours otherwise.
                    </p>
                @endif
            @endif
        </x-card>
    </div>
</x-app-layout>
