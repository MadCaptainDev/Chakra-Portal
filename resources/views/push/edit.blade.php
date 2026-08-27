@php
    /*
     * Firebase push, configured once for the whole studio.
     *
     * Mirrors Setup -> Instagram/WhatsApp/Notion deliberately: same shape,
     * same reasoning about what is shown and never shown back -- with one
     * addition, since this screen holds two DIFFERENT kinds of field. The
     * service account is a secret and blank means "leave it alone". The
     * web config and VAPID key are not secrets -- they are shipped to
     * every browser that opts in, which is what they are FOR -- so they
     * render in plain text and blank genuinely means "clear this".
     */
    $configured = $settings->isConfigured();
@endphp

<x-settings-layout title="Push Notifications">
    <x-slot name="header">
        <x-page-header
            title="Push Notifications"
            subtitle="One Firebase project for the whole studio. Staff turn devices on individually from their own profile." />
    </x-slot>

    <div class="space-y-6">

        <x-card padding="md">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Service account</p>
                    @if ($configured)
                        <x-badge status="active">Set</x-badge>
                    @else
                        <x-badge status="overdue">Not set</x-badge>
                    @endif
                </div>
                @if ($configured)
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Firebase project</p>
                        <p class="text-sm font-medium text-white font-mono">{{ $projectId ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Web config</p>
                        @if ($projectsMatch === null)
                            <span class="text-sm text-brand-100/50">No web config pasted yet</span>
                        @elseif ($projectsMatch)
                            <x-badge status="active">Matches</x-badge>
                        @else
                            <x-badge status="overdue">Does not match</x-badge>
                        @endif
                    </div>
                @endif
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Staff opted in</p>
                    <p class="text-sm font-medium text-white">{{ $staffWithDevices }} of {{ $totalStaff }} ({{ $deviceCount }} device(s))</p>
                </div>
            </div>

            @if ($configured)
                <form method="POST" action="{{ route('push.test') }}" class="mt-4 pt-4 border-t border-white/10">
                    @csrf
                    <x-secondary-button type="submit">Send test push to me</x-secondary-button>
                    <p class="mt-1.5 text-xs text-brand-100/60">
                        You'll need to have turned notifications on for this browser first, from your
                        <a href="{{ route('profile.edit') }}" class="text-brand-300 hover:text-brand-200">profile</a>.
                    </p>
                </form>
            @endif
        </x-card>

        <x-card padding="md">
            <form method="POST" action="{{ route('push.update') }}">
                @csrf
                @method('PUT')

                <x-section-heading
                    title="1 · Service account"
                    subtitle="Firebase console → Project settings → Service accounts → Generate new private key. Paste the whole downloaded JSON file below." />

                <div class="mb-6">
                    <x-input-label for="service_account_json" value="Service account JSON" />
                    <textarea id="service_account_json" name="service_account_json" rows="4"
                              autocomplete="off" spellcheck="false"
                              class="mt-1 w-full rounded-md border-white/15 font-mono text-xs"
                              placeholder="{{ $configured ? 'Saved — leave blank to keep it' : '{ "type": "service_account", "project_id": "...", ... }' }}"></textarea>
                    <x-input-error :messages="$errors->get('service_account_json')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">
                        Stored encrypted and never shown again. Leaving this blank keeps the current one --
                        unlike the two fields below, which are not secrets and are cleared if you leave them blank.
                    </p>
                </div>

                <x-section-heading
                    title="2 · Web app config"
                    subtitle="Firebase console → Project settings → General → Your apps → Web app → SDK setup and configuration → Config." />

                <div class="mb-6">
                    <x-input-label for="web_config" value="Web config (JSON)" />
                    <textarea id="web_config" name="web_config" rows="3"
                              autocomplete="off" spellcheck="false"
                              class="mt-1 w-full rounded-md border-white/15 font-mono text-xs"
                              placeholder='{ "apiKey": "...", "projectId": "...", "messagingSenderId": "...", "appId": "..." }'>{{ old('web_config', $settings->web_config) }}</textarea>
                    <x-input-error :messages="$errors->get('web_config')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">
                        Not a secret -- every browser that opts in downloads this. Shown as saved; blank clears it.
                    </p>
                </div>

                <x-section-heading
                    title="3 · VAPID public key"
                    subtitle="Firebase console → Project settings → Cloud Messaging → Web configuration → Web Push certificates." />

                <div class="mb-6">
                    <x-input-label for="vapid_public_key" value="VAPID public key" />
                    <x-text-input id="vapid_public_key" name="vapid_public_key" type="text" class="mt-1 w-full font-mono text-xs"
                                  autocomplete="off"
                                  value="{{ old('vapid_public_key', $settings->vapid_public_key) }}" />
                    <x-input-error :messages="$errors->get('vapid_public_key')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">Also not a secret -- the public half of the key pair.</p>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>Save</x-primary-button>
                    @if ($settings->updatedBy)
                        <span class="text-xs text-brand-100/60">
                            Last changed by {{ $settings->updatedBy->name }}, {{ $settings->updated_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
