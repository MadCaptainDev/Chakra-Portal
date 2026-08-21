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
 * v2 adds push + notificationclick (see below). The same stance applies to
 * both: a push payload is read once, used to show a notification, and never
 * written to the Cache API or anywhere else this worker can reach.
 *
 * Bump CACHE_VERSION to retire every previous cache on the next activation.
 */

const CACHE_VERSION = 'v2';
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

/*
 * A push notification arriving. FCM messages are sent DATA-ONLY (no
 * `notification` key) on purpose -- see PushMessage.php's own docblock for
 * why: the Firebase SW SDK is not loaded here, so a `notification` key
 * would arrive and display nothing, and Chrome would show its own generic
 * "This site has been updated in the background" instead of anything
 * useful. This handler is what actually shows something.
 *
 * Three rules, all non-negotiable:
 *   1. The whole body is inside try/catch -- FCM sends empty pushes for
 *      its own reasons, and a bare event.data.json() on one throws.
 *   2. waitUntil() always resolves to a SHOWN notification, including the
 *      catch path -- a bland fallback still opens the app on tap, which
 *      beats the browser's own generic notification every time.
 *   3. Never return early without calling showNotification().
 */
self.addEventListener('push', (event) => {
    event.waitUntil(
        (async () => {
            let title = 'Chakra Portal';
            let body = 'You have a new update.';
            let url = '/';
            let tag;

            try {
                const payload = event.data ? event.data.json() : {};
                const data = payload.data ?? payload;

                if (data.title) title = data.title;
                if (data.body) body = data.body;
                if (data.url) url = data.url;
                if (data.tag) tag = data.tag;
            } catch (error) {
                // Fall through to the bland fallback above -- rule 2.
            }

            return self.registration.showNotification(title, {
                body,
                icon: '/icon-192.png',
                badge: '/favicon-32x32.png',
                tag,
                renotify: !!tag,
                data: { url },
            });
        })(),
    );
});

/* Tapping a shown notification: focus an already-open tab on this origin if
 * there is one, otherwise open a new one at the notification's deep link. */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);

            if (existing) {
                // navigate() rejects on a client this worker does not
                // control -- fall back to opening a new window rather than
                // leaving the tap silently doing nothing.
                return existing.navigate(url).then((client) => client.focus()).catch(() => clients.openWindow(url));
            }

            return clients.openWindow(url);
        }),
    );
});
