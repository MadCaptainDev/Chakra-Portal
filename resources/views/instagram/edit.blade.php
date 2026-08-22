@php
    /*
     * The studio's Instagram app, as an admin configures it once.
     *
     * Mirrors Setup → WhatsApp deliberately: same shape, same reasoning about
     * what is shown and what is never shown back.
     */
    $configured = $settings->isConfigured();
@endphp

<x-settings-layout title="Instagram">
    <x-slot name="header">
        <x-page-header
            title="Instagram Integration"
            subtitle="One app for the whole studio. Individual accounts are connected on each client's page." />
    </x-slot>

    <div class="space-y-6">

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

            {{-- Three values, two of which look alike and go in different
                 boxes. They are labelled with where they belong rather than
                 with what they are, because swapping them is the single most
                 common way this setup fails. --}}
            <div class="space-y-4" x-data="{ copied: null,
                     copy(key, el) {
                         el.select();
                         navigator.clipboard?.writeText(el.value);
                         this.copied = key;
                         setTimeout(() => { if (this.copied === key) this.copied = null }, 2000);
                     } }">

                <div>
                    <x-input-label value="1 · Business login → Valid OAuth Redirect URI" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ $settings->callbackUrl() }}" x-ref="oauth"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-xs font-mono text-gray-800">
                        <x-secondary-button type="button" @click="copy('oauth', $refs.oauth)">
                            <span x-text="copied === 'oauth' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Where a client lands after approving. Meta matches this character for character.
                    </p>
                </div>

                <div>
                    <x-input-label value="2 · Webhooks → Callback URL" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ $settings->webhookUrl() }}" x-ref="hook"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-xs font-mono text-gray-800">
                        <x-secondary-button type="button" @click="copy('hook', $refs.hook)">
                            <span x-text="copied === 'hook' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-amber-700 mt-1">
                        A <span class="font-semibold">different URL</span> from the one above. Meta verifies this
                        one as an anonymous stranger, and the OAuth URL is behind a login — paste that one here
                        and the check fails with an error that explains nothing.
                    </p>
                </div>

                <div>
                    <x-input-label value="3 · Webhooks → Verify token" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ $settings->verify_token }}" x-ref="token"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-xs font-mono text-gray-800">
                        <x-secondary-button type="button" @click="copy('token', $refs.token)">
                            <span x-text="copied === 'token' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Generated for you, so nobody has to invent a secret on the spot. Meta sends it back once,
                        when you press <span class="font-medium">Verify and save</span>.
                    </p>
                </div>

                <div>
                    <x-input-label value="4 · Business login → Deauthorize callback URL" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ url('/webhooks/instagram/deauthorize') }}" x-ref="deauth"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-xs font-mono text-gray-800">
                        <x-secondary-button type="button" @click="copy('deauth', $refs.deauth)">
                            <span x-text="copied === 'deauth' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Meta tells us here when somebody removes the app from their Instagram. We stop using the
                        connection immediately and keep the record.
                    </p>
                </div>

                <div>
                    <x-input-label value="5 · Business login → Data deletion request URL" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ url('/webhooks/instagram/data-deletion') }}" x-ref="del"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-xs font-mono text-gray-800">
                        <x-secondary-button type="button" @click="copy('del', $refs.del)">
                            <span x-text="copied === 'del' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Deletes what we hold about the Instagram account — token, handle, profile — and answers with
                        a confirmation code. The client's own records here are the studio's and are not touched.
                    </p>
                </div>

                <div class="pt-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-gray-500">
                        @if ($settings->webhook_verified_at)
                            Webhook verified {{ $settings->webhook_verified_at->diffForHumans() }}.
                        @else
                            Webhook not verified yet.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('instagram-settings.rotate') }}"
                          onsubmit="return confirm('Generate a new verify token? The current one stops working immediately and you will have to verify the webhook again in Meta.')">
                        @csrf
                        <x-secondary-button type="submit">Generate new token</x-secondary-button>
                    </form>
                </div>
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

                <div class="mb-6 pt-5 border-t border-gray-100">
                    <x-input-label for="sync_throttle_minutes" value="Sync throttle" />
                    <div class="mt-1 flex items-center gap-2">
                        <x-text-input id="sync_throttle_minutes" name="sync_throttle_minutes" type="number"
                                      min="{{ \App\Models\InstagramSetting::MIN_SYNC_THROTTLE_MINUTES }}"
                                      max="{{ \App\Models\InstagramSetting::MAX_SYNC_THROTTLE_MINUTES }}"
                                      class="w-28"
                                      value="{{ old('sync_throttle_minutes', $settings->sync_throttle_minutes) }}" />
                        <span class="text-sm text-gray-600">minutes between syncs, per account</span>
                    </div>
                    <x-input-error :messages="$errors->get('sync_throttle_minutes')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">
                        Applies to both the client page's <span class="font-medium">Sync now</span> button and
                        <span class="font-mono">php artisan instagram:sync</span>. An account synced more recently
                        than this is skipped rather than calling Instagram again — the numbers cannot have changed
                        in the meantime, and every skipped call is one less against Meta's rate limit.
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
</x-settings-layout>
