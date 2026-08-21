/*
 * The Firebase surface for opting a browser in to push notifications.
 *
 * Never statically imported -- reached only via `await import('./push.js')`
 * from a click handler or a deferred silent refresh. firebase/app +
 * firebase/messaging is ~30-40 KB gzipped even tree-shaken, and most staff
 * will never opt in; Vite code-splits this file automatically so nobody
 * downloads it unless they actually reach for the button.
 */
import { initializeApp } from 'firebase/app';
import { getMessaging, getToken, deleteToken, isSupported } from 'firebase/messaging';

const STORAGE_KEY = 'chakra-push-token';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

/*
 * Same fetch() + meta-tag convention as shoots/show.blade.php and every
 * other AJAX call in this app -- there is no axios anywhere in
 * resources/views, and bootstrap.js does not attach a CSRF header, so this
 * follows the established pattern rather than reaching for axios.
 */
async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) throw new Error(`Request to ${url} failed: ${response.status}`);

    return response.json().catch(() => ({}));
}

/**
 * Whether this browser can even be asked. `isSupported()` (a Promise) is
 * the only correct feature test -- NOT `'Notification' in window`, which is
 * true on iOS Safari in a plain tab even though push there silently never
 * works: Safari only delivers web push to a PWA installed to the Home
 * Screen (iOS 16.4+), and there is no way to detect or force that from
 * script beyond the standalone-display check the caller does separately.
 */
export async function supported() {
    try {
        return await isSupported();
    } catch {
        return false;
    }
}

/**
 * Ask permission and register this browser's token with the server.
 *
 * The caller (the opt-in button's click handler) must import() this module
 * and call optIn() as close to the click as possible -- an `await` before
 * Notification.requestPermission() breaks Safari's user-gesture context and
 * the prompt silently never appears. This function calls
 * requestPermission() as its very first line, before touching Firebase at
 * all, to keep that gap as small as this module's own boundary allows.
 *
 * @param {{apiKey:string,projectId:string,messagingSenderId:string,appId:string}} webConfig
 * @param {string} vapidKey
 * @returns {Promise<'granted'|'denied'|'unsupported'>}
 */
export async function optIn(webConfig, vapidKey) {
    if (!(await supported())) return 'unsupported';

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return permission;

    const app = initializeApp(webConfig);
    const messaging = getMessaging(app);
    // The registration app.js already made on window load -- passing it
    // explicitly is what stops getToken() from silently registering a
    // SECOND worker at /firebase-messaging-sw.js. Two service workers
    // cannot both own scope /.
    const registration = await navigator.serviceWorker.ready;

    const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: registration });

    await postJson('/profile/push-tokens', { token });
    localStorage.setItem(STORAGE_KEY, token);

    return 'granted';
}

/** Drop this browser's registration, server and client side. Used on logout and the "Stop" button. */
export async function optOut(webConfig) {
    const stored = localStorage.getItem(STORAGE_KEY);

    try {
        if (stored) {
            const app = initializeApp(webConfig);
            await deleteToken(getMessaging(app));
        }
    } catch {
        // Firebase-side revocation failing must not stop the server-side
        // one -- a token Firebase still thinks is valid but we have
        // forgotten simply never resolves to a real device again.
    }

    if (stored) {
        await postJson('/profile/push-tokens/revoke', { token: stored }).catch(() => {});
    }

    localStorage.removeItem(STORAGE_KEY);
}

/**
 * Re-register silently if this browser already opted in. FCM rotates
 * tokens periodically; without this a device goes quiet after a few weeks
 * with no error and no way for anyone to notice. Safe to call on every
 * load -- it only does anything when permission is already granted and a
 * token was already stored, so it never prompts.
 */
export async function refreshIfEnabled(webConfig, vapidKey) {
    if (Notification.permission !== 'granted' || !localStorage.getItem(STORAGE_KEY)) return;
    if (!(await supported())) return;

    try {
        const app = initializeApp(webConfig);
        const messaging = getMessaging(app);
        const registration = await navigator.serviceWorker.ready;
        const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: registration });

        await postJson('/profile/push-tokens', { token });
        localStorage.setItem(STORAGE_KEY, token);
    } catch {
        // A silent background refresh failing is not worth surfacing --
        // the opt-in card will show the true state next time it renders.
    }
}

export function currentToken() {
    return localStorage.getItem(STORAGE_KEY);
}
