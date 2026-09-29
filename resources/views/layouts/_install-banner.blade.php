{{--
    "Install the app" bar, shown at the very top of the signed-in app on
    phones and tablets until the portal is installed or the bar is dismissed
    (it then stays away for 14 days). Behaviour lives in resources/js/install.js:
    Android gets a real Install button, iOS gets the Share → Add to Home Screen
    steps because Safari has no install API to call.
--}}
<div x-data="installBanner" x-show="mode !== 'hidden'" style="display: none;"
     class="lg:hidden bg-brand-400 text-brand-900" role="region" aria-label="Install the app">
    <div class="flex items-center gap-3 px-4 py-2.5">
        <img src="{{ asset('icon-192.png') }}" alt="" class="w-9 h-9 rounded-lg shrink-0 ring-1 ring-brand-900/10">

        <div class="min-w-0 flex-1 leading-tight">
            <p class="text-sm font-semibold">Install Chakra on your phone</p>
            <p class="text-xs text-brand-900/75">Opens full-screen from your home screen, with notifications.</p>
        </div>

        <button type="button" x-show="mode === 'prompt'" @click="install()"
                class="shrink-0 h-9 px-4 rounded-lg bg-brand-900 text-white text-sm font-semibold active:scale-95 transition">
            Install
        </button>
        <button type="button" x-show="mode === 'ios'" @click="showSteps = !showSteps" :aria-expanded="showSteps"
                class="shrink-0 h-9 px-4 rounded-lg bg-brand-900 text-white text-sm font-semibold active:scale-95 transition">
            How?
        </button>

        <button type="button" @click="dismiss()" aria-label="Not now"
                class="shrink-0 -mr-2 inline-flex items-center justify-center w-9 h-9 rounded-lg text-brand-900/70 hover:bg-brand-900/10">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <ol x-show="mode === 'ios' && showSteps" x-transition
        class="px-4 pb-3 space-y-1.5 text-sm list-decimal list-inside">
        <li>
            Tap the <strong>Share</strong> button
            <svg class="inline w-4 h-4 -mt-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-label="Share icon">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0-12L8 7m4-4l4 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" />
            </svg>
            (in Safari it may be under the <strong>•••</strong> menu)
        </li>
        <li>Scroll down and tap <strong>Add to Home Screen</strong></li>
        <li>Tap <strong>Add</strong>, then open Chakra from your home screen</li>
    </ol>
</div>
