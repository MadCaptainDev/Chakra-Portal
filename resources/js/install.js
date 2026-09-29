/*
 * The "install this app" bar at the top of the signed-in app
 * (resources/views/layouts/_install-banner.blade.php).
 *
 * Two different mechanisms behind one bar:
 *
 *   - Chrome / Edge / Samsung Internet on Android fire `beforeinstallprompt`.
 *     The event is held on to here and replayed from the bar's Install
 *     button, which opens the browser's own install dialog.
 *   - iOS has no install API at all. Every iOS browser can only do it by
 *     hand, through the Share sheet's "Add to Home Screen", so the bar there
 *     says exactly that instead of offering a button that cannot work.
 *
 * `beforeinstallprompt` can fire before Alpine has built the bar, so it is
 * captured at module load and kept on window rather than inside the
 * component.
 */

const DISMISS_KEY = 'chakra-install-dismissed-at';
const DISMISS_DAYS = 14;

window.addEventListener('beforeinstallprompt', (event) => {
    // Stop Chrome's own mini-infobar; our bar is the one prompt shown.
    event.preventDefault();
    window.chakraInstallPrompt = event;
    window.dispatchEvent(new CustomEvent('chakra-install-available'));
});

window.addEventListener('appinstalled', () => {
    window.chakraInstallPrompt = null;
    window.dispatchEvent(new CustomEvent('chakra-install-done'));
});

function isStandalone() {
    return window.navigator.standalone === true
        || window.matchMedia?.('(display-mode: standalone)').matches;
}

function isIos() {
    // iPadOS reports itself as a Mac; a touch screen gives it away.
    return /iP(hone|od|ad)/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function recentlyDismissed() {
    try {
        const at = Number(localStorage.getItem(DISMISS_KEY));

        return at > 0 && Date.now() - at < DISMISS_DAYS * 86400000;
    } catch {
        return false;
    }
}

export function installBanner() {
    return {
        // 'hidden' | 'prompt' (Android button) | 'ios' (manual steps)
        mode: 'hidden',
        showSteps: false,

        init() {
            if (isStandalone() || recentlyDismissed()) return;

            if (window.chakraInstallPrompt) {
                this.mode = 'prompt';
            } else if (isIos()) {
                this.mode = 'ios';
            }

            window.addEventListener('chakra-install-available', () => {
                if (!recentlyDismissed()) this.mode = 'prompt';
            });
            window.addEventListener('chakra-install-done', () => { this.mode = 'hidden'; });
        },

        async install() {
            const prompt = window.chakraInstallPrompt;
            if (!prompt) return;

            // A prompt event can only be used once, whatever the answer.
            window.chakraInstallPrompt = null;
            prompt.prompt();

            const { outcome } = await prompt.userChoice;
            this.mode = 'hidden';
            if (outcome !== 'accepted') this.remember();
        },

        dismiss() {
            this.mode = 'hidden';
            this.remember();
        },

        remember() {
            try {
                localStorage.setItem(DISMISS_KEY, String(Date.now()));
            } catch {
                // Private mode: the bar simply comes back next visit.
            }
        },
    };
}
