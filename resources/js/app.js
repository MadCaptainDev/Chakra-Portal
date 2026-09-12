import './bootstrap';

import Alpine from 'alpinejs';
import { whatsappInboxThread } from './whatsapp-inbox.js';

window.Alpine = Alpine;

// Registered before start() so resources/views/whatsapp-crm/inbox/show.blade.php's
// x-data="whatsappInboxThread(...)" can resolve it -- unlike push.js below,
// this is not lazy: the thread's polling has to be running the moment the
// page it belongs to loads, not after a first user gesture opts in.
Alpine.data('whatsappInboxThread', whatsappInboxThread);

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

/*
 * Pull to refresh.
 *
 * app.css switches the browser's own off (overscroll-behavior) because it
 * fired an unstyled full reload with no warning, mid-scroll. This is that
 * gesture put back deliberately: it only arms at the very top of the page,
 * it shows what it is about to do while the finger is still down, and it
 * only commits past a real threshold -- so the accidental version that got
 * it switched off in the first place does not come back with it.
 *
 * Touch only. There is no mouse equivalent and nothing to fix on desktop,
 * where the keyboard already has a refresh.
 */
(function initPullToRefresh() {
    if (!window.matchMedia?.('(pointer: coarse)').matches) return;

    const THRESHOLD = 72; // px of finger travel before a release reloads
    const MAX_PULL = 150; // past here the indicator stops following

    let startY = null;
    let armed = false;
    let indicator = null;

    function el() {
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'ptr';
            indicator.innerHTML = '<span class="ptr-spinner"></span>';
            document.body.appendChild(indicator);
        }

        return indicator;
    }

    /*
     * A touch that begins inside something with its own scrollbar -- a modal
     * body, the equipment picker's list, a table that scrolls sideways --
     * belongs to that element, not to the page. Bailing on any scrollable
     * ancestor (rather than only one already scrolled) is the conservative
     * call: a list sitting at its own top still scrolls down under the
     * finger, and stealing that gesture to reload the page would be worse
     * than not offering the gesture there at all.
     */
    function hasScrollableAncestor(node) {
        while (node && node !== document.body && node.nodeType === 1) {
            if (node.scrollHeight > node.clientHeight + 1) {
                const overflowY = getComputedStyle(node).overflowY;
                if (overflowY === 'auto' || overflowY === 'scroll') return true;
            }
            node = node.parentElement;
        }

        return false;
    }

    function settle() {
        const bar = el();
        bar.classList.add('is-settling');
        bar.classList.remove('is-armed');
        bar.style.setProperty('--ptr-pull', '0');
        setTimeout(() => bar.classList.remove('is-settling'), 260);
    }

    function onMove(event) {
        if (startY === null) return;

        const delta = event.touches[0].clientY - startY;

        // Pulling up, or the page scrolled away under the finger: this
        // gesture is a scroll after all. Hand it back untouched.
        if (delta <= 0 || window.scrollY > 0) {
            if (armed || delta <= 0) settle();
            startY = null;
            armed = false;
            stopTracking();

            return;
        }

        // Only now is this definitely a pull rather than a scroll, so this is
        // the first point at which suppressing the rubber-band is honest.
        if (event.cancelable) event.preventDefault();

        const pull = Math.min(delta, MAX_PULL) / MAX_PULL;
        const bar = el();
        bar.classList.remove('is-settling');
        bar.style.setProperty('--ptr-pull', String(pull));

        armed = delta >= THRESHOLD;
        bar.classList.toggle('is-armed', armed);
    }

    function stopTracking() {
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onEnd);
        document.removeEventListener('touchcancel', onCancel);
    }

    function onEnd() {
        const shouldRefresh = armed;
        startY = null;
        armed = false;
        stopTracking();

        if (!shouldRefresh) {
            settle();

            return;
        }

        const bar = el();
        bar.classList.add('is-refreshing');
        bar.style.setProperty('--ptr-pull', '1');
        location.reload();
    }

    function onCancel() {
        startY = null;
        armed = false;
        stopTracking();
        settle();
    }

    document.addEventListener('touchstart', (event) => {
        if (event.touches.length !== 1) return;
        // A modal is open (x-modal locks the body this way) -- the page
        // behind it is not what the finger is on.
        if (document.body.classList.contains('overflow-y-hidden')) return;
        // iOS rubber-band can report a negative scrollY; only a page truly
        // resting at the top arms.
        if (window.scrollY > 0) return;
        if (hasScrollableAncestor(event.target)) return;

        startY = event.touches[0].clientY;
        armed = false;

        /*
         * touchmove has to be non-passive to suppress the rubber-band, and a
         * permanently non-passive document listener takes every scroll on the
         * page off the browser's fast path. Registering it only once a touch
         * has started at the very top -- and tearing it down on release --
         * keeps ordinary scrolling untouched.
         */
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onEnd, { passive: true });
        document.addEventListener('touchcancel', onCancel, { passive: true });
    }, { passive: true });
})();
