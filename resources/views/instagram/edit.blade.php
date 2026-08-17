@php
    /*
     * The studio's Instagram app, as an admin configures it once.
     *
     * Mirrors Setup → WhatsApp deliberately: same shape, same reasoning about
     * what is shown and what is never shown back.
     */
    $configured = $settings->isConfigured();
@endphp

<x-app-layout title="Instagram">
    <x-slot name="header">
        <x-page-header
            title="Instagram Integration"
            subtitle="One app for the whole studio. Individual accounts are connected on each client's page." />
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">

        <x-card padding="md">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">App credentials</p>
                    @if ($configured)
                        <x-badge status="active">Set</x-badge>
                    @else
                        <x-badge status="overdue">Not set</x-badge>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">First connection</p>
                    <p class="text-sm font-medium text-gray-900">
                        {{ $settings->verified_at?->diffForHumans() ?? 'None yet' }}
                    </p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Accounts connected</p>
                    <p class="text-sm font-medium text-gray-900">{{ $connected->count() }}</p>
                </div>
            </div>
        </x-card>

        <x-card padding="md">
            <x-section-heading
                title="Paste this into Meta"
                subtitle="Meta dashboard → your Instagram app → Instagram → API setup with Instagram login." />

            <div x-data="{ copied: false }">
                <x-input-label value="Valid OAuth Redirect URI" />
                <div class="mt-1 flex gap-2">
                    <input type="text" readonly value="{{ $settings->callbackUrl() }}" x-ref="uri"
                           class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-xs font-mono text-gray-800">
                    <x-secondary-button type="button"
                            @click="$refs.uri.select(); navigator.clipboard.writeText($refs.uri.value); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </x-secondary-button>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    Meta matches this character for character. It is built from APP_URL, so it does not change
                    with the address you signed in through — a mismatch here is the most common reason the
                    connection fails, and Meta's error for it names nothing useful.
                </p>
            </div>
        </x-card>

        <x-card padding="md">
            <form method="POST" action="{{ route('instagram-settings.update') }}">
                @csrf
                @method('PUT')

                <x-section-heading
                    title="From the Meta dashboard"
                    subtitle="The Instagram app ID and secret — not the Meta app's own, which are different values on the same page." />

                <div class="mb-4">
                    <x-input-label for="app_id" value="Instagram App ID" />
                    <x-text-input id="app_id" name="app_id" type="text" class="mt-1 w-full font-mono"
                                  value="{{ old('app_id', $settings->app_id) }}" />
                    <x-input-error :messages="$errors->get('app_id')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">
                        Public — it travels in the authorise URL in every client's browser.
                    </p>
                </div>

                <div class="mb-6">
                    <x-input-label for="app_secret" value="Instagram App Secret" />
                    <x-text-input id="app_secret" name="app_secret" type="password" class="mt-1 w-full font-mono"
                                  autocomplete="new-password"
                                  placeholder="{{ $settings->app_secret ? 'Saved — leave blank to keep it' : 'Meta dashboard → Instagram → API setup' }}" />
                    <x-input-error :messages="$errors->get('app_secret')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">
                        Stored encrypted and never shown again. Leaving this blank keeps the current one.
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
            <x-section-heading
                title="Connected accounts"
                subtitle="Connect and disconnect from each client's own page, under Social Media." />

            @if ($connected->isEmpty())
                <x-empty-state message="No Instagram accounts connected yet." />
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($connected as $account)
                        <li class="py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">{{ $account->handle() }}</p>
                                <p class="text-xs text-gray-500">{{ $account->client?->name }}</p>
                            </div>
                            <a href="{{ route('clients.show', $account->client_id) }}"
                               class="shrink-0 text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                                Open client
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-app-layout>
