@php
    /*
     * Three paid API keys, all secrets -- unlike push_settings there is no
     * non-secret half of this screen to show back in plain text, so all
     * three fields behave the same way: blank means "leave it alone".
     */
    $configured = $settings->isFullyConfigured();
@endphp

<x-settings-layout title="Competitor Analysis">
    <x-slot name="header">
        <x-page-header
            title="Competitor Analysis"
            subtitle="Scrapes a competitor's Instagram reels, ranks them against that account's own average, and has AI break down and adapt the best ones." />
    </x-slot>

    <div class="space-y-6">

        <x-card padding="md">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Apify (scraping)</p>
                    <x-badge :status="$settings->hasApify() ? 'active' : 'overdue'">{{ $settings->hasApify() ? 'Set' : 'Not set' }}</x-badge>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Gemini (analysis)</p>
                    <x-badge :status="$settings->hasGemini() ? 'active' : 'overdue'">{{ $settings->hasGemini() ? 'Set' : 'Not set' }}</x-badge>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Anthropic (concepts)</p>
                    <x-badge :status="$settings->hasAnthropic() ? 'active' : 'overdue'">{{ $settings->hasAnthropic() ? 'Set' : 'Not set' }}</x-badge>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Tracked competitors</p>
                    <p class="text-sm font-medium text-gray-900">{{ $trackedCount }}</p>
                </div>
            </div>

            @if ($settings->hasAnthropic())
                <form method="POST" action="{{ route('competitor-settings.test') }}" class="mt-4 pt-4 border-t border-gray-100">
                    @csrf
                    <x-secondary-button type="submit">Test the Anthropic connection</x-secondary-button>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Sends one real, cheap request to Claude and shows its reply — proves the key works
                        without spending on Apify or Gemini.
                    </p>
                </form>
            @endif
        </x-card>

        <x-card padding="md">
            <form method="POST" action="{{ route('competitor-settings.update') }}">
                @csrf
                @method('PUT')

                <x-section-heading
                    title="1 · Apify"
                    subtitle="apify.com → Settings → Integrations. Scrapes the public Instagram reels — the free tier covers a handful of accounts a month." />

                <div class="mb-6">
                    <x-input-label for="apify_token" value="Apify API token" />
                    <x-text-input id="apify_token" name="apify_token" type="text" class="mt-1 w-full font-mono text-xs"
                                  autocomplete="off" spellcheck="false"
                                  placeholder="{{ $settings->hasApify() ? 'Saved — leave blank to keep it' : 'apify_api_...' }}" />
                    <x-input-error :messages="$errors->get('apify_token')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">Stored encrypted and never shown again.</p>
                </div>

                <x-section-heading
                    title="2 · Google Gemini"
                    subtitle="aistudio.google.com/apikey. Watches each reel and writes the shot-by-shot breakdown — has a free tier." />

                <div class="mb-6">
                    <x-input-label for="gemini_api_key" value="Gemini API key" />
                    <x-text-input id="gemini_api_key" name="gemini_api_key" type="text" class="mt-1 w-full font-mono text-xs"
                                  autocomplete="off" spellcheck="false"
                                  placeholder="{{ $settings->hasGemini() ? 'Saved — leave blank to keep it' : 'AIza...' }}" />
                    <x-input-error :messages="$errors->get('gemini_api_key')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">Stored encrypted and never shown again.</p>
                </div>

                <div class="mb-6">
                    <x-input-label for="gemini_model" value="Gemini model" />
                    <x-text-input id="gemini_model" name="gemini_model" type="text" class="mt-1 w-full font-mono text-xs"
                                  value="{{ old('gemini_model', $settings->gemini_model) }}" />
                    <x-input-error :messages="$errors->get('gemini_model')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">
                        Not a secret — just which model to call. Google retires models occasionally; change
                        this if analysis starts failing with a 404.
                    </p>
                </div>

                <x-section-heading
                    title="3 · Anthropic"
                    subtitle="platform.claude.com. A separate, prepaid API key — different from any Claude subscription. Writes the new Reel concepts." />

                <div class="mb-6">
                    <x-input-label for="anthropic_api_key" value="Anthropic API key" />
                    <x-text-input id="anthropic_api_key" name="anthropic_api_key" type="text" class="mt-1 w-full font-mono text-xs"
                                  autocomplete="off" spellcheck="false"
                                  placeholder="{{ $settings->hasAnthropic() ? 'Saved — leave blank to keep it' : 'sk-ant-...' }}" />
                    <x-input-error :messages="$errors->get('anthropic_api_key')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">Stored encrypted and never shown again.</p>
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

        @unless ($configured)
            <p class="text-xs text-gray-500 text-center">
                All three keys are needed for the full pipeline — scraping works with just Apify, but
                analysis needs Gemini too and generating concepts needs Anthropic.
            </p>
        @endunless
    </div>
</x-settings-layout>
