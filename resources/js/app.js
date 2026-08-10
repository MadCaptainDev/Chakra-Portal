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
     * Signing out drops the cached assets. None of them are private -- the
     * worker refuses to cache page HTML precisely so that they cannot be -- but
     * on a shared machine it is better that the next person starts clean.
     */
    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement && event.target.action.includes('/logout')) {
            navigator.serviceWorker.controller?.postMessage('clear-caches');
        }
    });
}
