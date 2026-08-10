/*
 * Service worker for the Chakra Productions portal.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: cache page HTML.
 *
 * Most of this app is behind a login and shows invoices, salaries, client
 * records and staff details. Anything written to the Cache API lands on disk
 * unencrypted, outlives the session, and is readable by whoever picks up the
 * device next -- including a different member of staff who signs in on the
 * same laptop. So navigations are fetched from the network every time and the
 * response is never stored; when the network is unreachable the request falls
 * back to a static offline page instead.
 *
 * What IS cached is the stuff that is public, immutable and already served to
 * anyone who asks: the content-hashed CSS/JS bundles, the site icons and the
 * brand images. That is enough for the app to open instantly on a repeat visit
 * and to satisfy installability, without persisting a single private byte.
 *
 * Bump CACHE_VERSION to retire every previous cache on the next activation.
 */

const CACHE_VERSION = 'v1';
const STATIC_CACHE = `chakra-static-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';

/* Fetched during install so the offline page is guaranteed to be there the
 * first time it is needed. Everything else arrives through runtime caching. */
const PRECACHE = [
    OFFLINE_URL,
    '/favicon.ico',
    '/favicon-32x32.png',
    '/apple-touch-icon.png',
    '/icon-192.png',
    '/icon-512.png',
];

/* Same-origin prefixes that are safe to cache: public, non-personal, and
 * either content-hashed or effectively static. */
const CACHEABLE_PREFIXES = ['/build/', '/images/', '/fonts/'];

const isCacheableAsset = (url) =>
    CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix)) ||
    PRECACHE.includes(url.pathname);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            // addAll is atomic: one 404 would throw away the whole install, and
            // a missing icon should not stop the offline page from working.
            .then((cache) => Promise.allSettled(PRECACHE.map((url) => cache.add(url))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only ever touch same-origin GETs. Anything else -- POSTs, form submits,
    // third-party requests -- goes straight to the network untouched.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    /* Navigations: network first, and the response is NOT stored. On failure
     * fall back to the offline page rather than a browser error. */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL, { ignoreSearch: true })),
        );

        return;
    }

    if (!isCacheableAsset(url)) return;

    /* Static assets: serve from cache, refresh in the background. The bundles
     * are content-hashed, so a stale hit is only ever the file that URL has
     * always meant. */
    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request)
                .then((response) => {
                    if (response && response.ok && response.type === 'basic') {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => cached);

            return cached || network;
        }),
    );
});

/* Lets the page drop every cached asset -- wired to sign-out, so a shared
 * machine keeps nothing from the previous session even though none of it is
 * private to begin with. */
self.addEventListener('message', (event) => {
    if (event.data === 'clear-caches') {
        event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key)))));
    }
});
