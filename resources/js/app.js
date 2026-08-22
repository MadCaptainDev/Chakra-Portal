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

/*
 * Top-of-page navigation bar. Every screen here is server-rendered Blade,
 * not a SPA -- there is nothing else telling the person that the tap they
 * just made is doing anything, right through the gap between the request
 * going out and the next page painting. On a slow connection that gap is
 * exactly when a pull-to-refresh swipe starts to feel like the only way to
 * get unstuck; a bar climbing at the top of the screen is a cheap way to
 * say "already on it" instead.
 *
 * A classic nprogress-style trick, not a real percentage: there is no way
 * to know how far a full page load actually is, so the bar creeps toward
 * 90% and is simply abandoned there -- the navigation it was for finishes
 * by tearing this whole page (and the bar with it) down and painting the
 * next one. pageshow covers the one case that survives navigation: a
 * back/forward restore from bfcache, which never re-runs this script.
 */
(function initNavProgress() {
    function start() {
        const bar = document.createElement('div');
        bar.className = 'nav-progress';
        document.body.appendChild(bar);

        requestAnimationFrame(() => {
            bar.classList.add('is-active');
            bar.style.width = '35%';
        });

        let width = 35;
        const timer = setInterval(() => {
            // Slows down the closer it gets, so a fast load never looks like
            // it raced to 90% for no reason -- the same easing nprogress
            // itself uses, just inlined rather than adding a dependency for
            // one bar.
            width += (90 - width) * 0.1;
            bar.style.width = Math.min(width, 90) + '%';
        }, 200);

        // If the browser serves this page from bfcache instead of a real
        // navigation (Safari does this often for a plain back tap), no new
        // page ever loads to tear this bar down -- clear it here instead.
        window.addEventListener('pageshow', () => clearInterval(timer), { once: true });
    }

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;

        let url;
        try {
            url = new URL(link.href, location.href);
        } catch {
            return;
        }

        if (url.origin !== location.origin) return;
        // A same-page anchor (jumping to #contact on the page already open)
        // never navigates -- nothing here to show progress for.
        if (url.pathname === location.pathname && url.search === location.search && url.hash) return;

        start();
    });

    document.addEventListener('submit', (event) => {
        // Whatever handled the submit first (the logout revoke hook above,
        // an Alpine form managing its own AJAX request) already called
        // preventDefault() by the time this reaches document -- submit
        // listeners on one target fire in registration order, and this is
        // deliberately the last one added. A prevented submit never
        // navigates, so it gets no bar.
        if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;

        start();
    });
})();
