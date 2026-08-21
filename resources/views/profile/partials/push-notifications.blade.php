@php
    use App\Support\Device;

    /*
     * Turning push on/off for THIS browser.
     *
     * Everything about whether we CAN ask -- support, iOS install state,
     * the current Notification.permission, whether a token is already
     * stored -- is read client-side in Alpine's init(), not here: those
     * facts belong to the browser, not the request. Blade only supplies
     * what the server actually knows: whether Firebase is configured at
     * all, the web config needed to call it, and this account's list of
     * already-registered devices.
     *
     * Not shown to clients -- the parent view (profile/edit.blade.php)
     * already wraps this @include in @unless ($user->isClient()).
     */
    $icons = [
        Device::PHONE => 'phone',
        Device::TABLET => 'tablet',
        Device::DESKTOP => 'desktop',
    ];
@endphp

<section
    x-data="pushNotifications({
        configured: @js($pushConfigured),
        webConfig: @js($pushWebConfig),
        vapidKey: @js($pushVapidKey),
    })"
    x-init="init()"
>
    <header>
        <h2 class="text-lg font-medium text-gray-900">Notifications</h2>
        <p class="mt-1 text-sm text-gray-600">
            Get a push alert on this device for the things you'd want to know about right
            away -- announcements, to-dos, shoot reminders, sent-back timesheets.
        </p>
    </header>

    <div class="mt-6 space-y-4">
        @unless ($pushConfigured)
            <div class="flex items-start gap-3 p-4 rounded-xl bg-gray-50 ring-1 ring-gray-900/5">
                <x-icon name="bell" class="w-5 h-5 shrink-0 mt-0.5 text-gray-400" />
                <p class="text-sm text-gray-600">
                    Push notifications haven't been set up for the studio yet. Ask an admin
                    to configure Firebase under Setup &rarr; Notifications.
                </p>
            </div>
        @else
            {{-- Checking support / iOS-not-installed / not-asked / granted / denied --}}
            <template x-if="state === 'checking'">
                <p class="text-sm text-gray-500">Checking this browser&hellip;</p>
            </template>

            <template x-if="state === 'unsupported'">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-gray-50 ring-1 ring-gray-900/5">
                    <x-icon name="bell" class="w-5 h-5 shrink-0 mt-0.5 text-gray-400" />
                    <p class="text-sm text-gray-600">
                        This browser doesn't support push notifications. Try Chrome, Edge, or
                        Safari on iOS 16.4+ (installed to the Home Screen).
                    </p>
                </div>
            </template>

            <template x-if="state === 'ios-not-installed'">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-brand-50 ring-1 ring-brand-900/10">
                    <x-icon name="bell" class="w-5 h-5 shrink-0 mt-0.5 text-brand-600" />
                    <p class="text-sm text-gray-700">
                        On iPhone, notifications only work once the portal is added to your
                        Home Screen. Tap <strong>Share</strong>
                        <span aria-hidden="true">&rarr;</span>
                        <strong>Add to Home Screen</strong>, then open it from there and come
                        back to this page.
                    </p>
                </div>
            </template>

            <template x-if="state === 'denied'">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-amber-50 ring-1 ring-amber-900/10">
                    <x-icon name="alert" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />
                    <p class="text-sm text-amber-800">
                        Notifications are blocked for this site. A page can't re-ask once
                        you've said no -- open your browser's site settings for this page and
                        allow notifications, then reload.
                    </p>
                </div>
            </template>

            <template x-if="state === 'not-asked'">
                <div class="flex items-start justify-between gap-4 p-4 rounded-xl bg-gray-50 ring-1 ring-gray-900/5">
                    <div class="flex items-start gap-3 min-w-0">
                        <x-icon name="bell" class="w-5 h-5 shrink-0 mt-0.5 text-gray-400" />
                        <p class="text-sm text-gray-600">Notifications are off for this device.</p>
                    </div>
                    <x-primary-button type="button" @click="enable()" :disabled="false" x-bind:disabled="busy" class="shrink-0">
                        <span x-show="!busy">Turn on</span>
                        <span x-show="busy" x-cloak>Turning on&hellip;</span>
                    </x-primary-button>
                </div>
            </template>

            <template x-if="state === 'granted'">
                <div class="flex items-start justify-between gap-4 p-4 rounded-xl bg-green-50 ring-1 ring-green-900/10">
                    <div class="flex items-start gap-3 min-w-0">
                        <x-icon name="check-circle" class="w-5 h-5 shrink-0 mt-0.5 text-green-600" />
                        <p class="text-sm text-green-800">Notifications are on for this device.</p>
                    </div>
                    <x-secondary-button type="button" @click="disable()" x-bind:disabled="busy" class="shrink-0">
                        <span x-show="!busy">Turn off</span>
                        <span x-show="busy" x-cloak>Turning off&hellip;</span>
                    </x-secondary-button>
                </div>
            </template>

            <p x-show="error" x-cloak x-text="error" class="text-sm text-red-600"></p>
        @endunless

        @if ($pushTokens->isNotEmpty())
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-2">
                    Registered devices
                </p>
                <div class="rounded-xl ring-1 ring-gray-900/5 overflow-hidden">
                    @foreach ($pushTokens as $token)
                        <div class="flex items-start gap-3.5 p-4 {{ $loop->first ? '' : 'border-t border-gray-100' }}">
                            <span class="shrink-0 inline-flex items-center justify-center w-10 h-10 rounded-lg bg-gray-100 text-gray-500">
                                <x-icon :name="$icons[$token->device_kind] ?? 'globe'" class="w-5 h-5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900">{{ $token->device_label }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $token->last_used_at ? 'Last notified '.$token->last_used_at->diffForHumans() : 'Not notified yet' }}
                                </p>
                                @if ($token->failure_reason)
                                    <p class="mt-1 text-xs font-medium text-amber-700">{{ $token->failure_reason }}</p>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('push-tokens.destroy', $token) }}" class="shrink-0"
                                  onsubmit="return confirm('Stop notifications on {{ $token->device_label }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex items-center min-h-[36px] px-3 rounded-md border border-gray-300
                                               text-[11px] font-semibold uppercase tracking-wider text-gray-700
                                               hover:bg-red-50 hover:border-red-300 hover:text-red-700 transition-colors">
                                    Stop
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pushNotifications', (config) => ({
                state: 'checking',
                busy: false,
                error: null,

                async init() {
                    if (!config.configured) return;

                    if (typeof window.chakraPush === 'undefined') {
                        this.state = 'unsupported';
                        return;
                    }

                    const isIos = /iP(hone|od|ad)/.test(navigator.userAgent);
                    const isStandalone = window.navigator.standalone === true
                        || window.matchMedia('(display-mode: standalone)').matches;

                    if (isIos && !isStandalone) {
                        this.state = 'ios-not-installed';
                        return;
                    }

                    const supported = await window.chakraPush.supported();
                    if (!supported) {
                        this.state = 'unsupported';
                        return;
                    }

                    if (Notification.permission === 'denied') {
                        this.state = 'denied';
                        return;
                    }

                    if (Notification.permission === 'granted' && localStorage.getItem('chakra-push-token')) {
                        this.state = 'granted';

                        /*
                         * FCM rotates tokens periodically; without this a
                         * device goes quiet after a few weeks with no error
                         * and no one noticing. Deferred so it never competes
                         * with the page's own work, and silent -- permission
                         * is already granted, so it can never prompt.
                         */
                        const refresh = () => window.chakraPush.refreshIfEnabled(config.webConfig, config.vapidKey);
                        if ('requestIdleCallback' in window) {
                            requestIdleCallback(refresh);
                        } else {
                            setTimeout(refresh, 2000);
                        }

                        return;
                    }

                    this.state = 'not-asked';
                },

                async enable() {
                    this.busy = true;
                    this.error = null;

                    /*
                     * This call -- not the one inside push.js's optIn() --
                     * is what actually needs to run synchronously-first in
                     * this click's user-gesture window; an awaited
                     * dynamic import() before it can lose that window on
                     * Safari. Once the decision is made here, optIn()'s own
                     * internal requestPermission() call resolves instantly
                     * with no second prompt.
                     */
                    const permission = await Notification.requestPermission();

                    if (permission !== 'granted') {
                        this.state = permission === 'denied' ? 'denied' : 'not-asked';
                        this.busy = false;
                        return;
                    }

                    try {
                        const result = await window.chakraPush.optIn(config.webConfig, config.vapidKey);
                        if (result === 'granted') {
                            window.location.reload();
                            return;
                        }
                        this.state = result === 'denied' ? 'denied' : 'not-asked';
                    } catch (e) {
                        this.error = "Something went wrong turning this on. Try again in a moment.";
                    } finally {
                        this.busy = false;
                    }
                },

                async disable() {
                    this.busy = true;
                    this.error = null;

                    try {
                        await window.chakraPush.optOut(config.webConfig);
                        window.location.reload();
                    } catch (e) {
                        this.error = "Something went wrong turning this off. Try again in a moment.";
                        this.busy = false;
                    }
                },
            }));
        });
    </script>
@endpush
