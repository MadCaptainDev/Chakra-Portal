import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

/*
 * Service worker. Registered after load so it never competes with the page's
 * own requests for bandwidth on a first visit.
 *
 * Only over HTTPS (or localhost) -- registration throws on plain HTTP, and the
 * LAN dev setup is served over it.
 */
if ('serviceWorker' in navigator && (window.isSecureContext ?? location.protocol === 'https:')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            // A failed registration must never break the page: everything the
            // worker does is an enhancement.
            console.warn('Service worker registration failed:', error);
        });
    });

    /*
     * Signing out drops the cached assets and this browser's push
     * registration. Neither may block the actual sign-out.
     *
     * The cache-clear is fire-and-forget (postMessage has no response to
     * wait for). Revoking the push token is a real network request, so it
     * gets a hard budget: race it against a 1.5s timeout and submit the
     * logout form regardless of which wins. This deliberately does NOT
     * import push.js -- pulling in the whole Firebase bundle at logout
     * time for one DELETE-shaped request is not worth it, and the
     * server-side revoke needs nothing but the raw token already sitting
     * in localStorage. STORAGE_KEY here must match push.js's.
     */
    const PUSH_TOKEN_KEY = 'chakra-push-token';

    document.addEventListener('submit', (event) => {
        if (!(event.target instanceof HTMLFormElement) || !event.target.action.includes('/logout')) {
            return;
        }

        navigator.serviceWorker.controller?.postMessage('clear-caches');

        const token = localStorage.getItem(PUSH_TOKEN_KEY);
        if (!token) return;

        const form = event.target;
        event.preventDefault();

        const revoke = fetch('/profile/push-tokens/revoke', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ token }),
        }).catch(() => {});

        const timeout = new Promise((resolve) => setTimeout(resolve, 1500));

        Promise.race([revoke, timeout]).finally(() => {
            localStorage.removeItem(PUSH_TOKEN_KEY);
            form.submit();
        });
    });
}

/*
 * Push notifications (resources/js/push.js) -- never statically imported.
 * firebase/app + firebase/messaging is ~30-40 KB gzipped and most staff
 * will never opt in, so it must stay out of every page's main bundle.
 *
 * The import() call has to live here, in a file Vite actually processes,
 * for its code-splitting to rewrite it into a fetch of the correct hashed
 * chunk -- a blade view's own inline <script> cannot import() a build
 * path itself. resources/views/profile/partials/push-notifications.blade.php
 * calls these wrappers rather than reaching for push.js directly.
 */
window.chakraPush = {
    optIn: (webConfig, vapidKey) => import('./push.js').then((m) => m.optIn(webConfig, vapidKey)),
    optOut: (webConfig) => import('./push.js').then((m) => m.optOut(webConfig)),
    refreshIfEnabled: (webConfig, vapidKey) => import('./push.js').then((m) => m.refreshIfEnabled(webConfig, vapidKey)),
    supported: () => import('./push.js').then((m) => m.supported()),
};
