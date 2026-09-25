/*
 * The comment box on a public proposal (resources/views/proposals/public).
 *
 * Only behaviour lives here -- the document itself is server-rendered Blade,
 * so it reads, prints and loads without any of this. What this adds:
 * remembering the reader's name and email between comments (a client leaving
 * five comments should type their name once), and keeping a half-written
 * comment's box open.
 *
 * localStorage is wrapped in try/catch everywhere: it throws in some private
 * windows and when site data is blocked, and a comment box must still work
 * then -- it just forgets.
 */

const STORAGE_KEY = 'cp-proposal-commenter';

function readIdentity() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : null;

        return {
            name: typeof parsed?.name === 'string' ? parsed.name : '',
            email: typeof parsed?.email === 'string' ? parsed.email : '',
        };
    } catch {
        return { name: '', email: '' };
    }
}

function writeIdentity(name, email) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ name, email }));
    } catch {
        // Storage unavailable -- the comment still posts.
    }
}

/**
 * Alpine component for one comment form. `startOpen` keeps the general
 * feedback box open by default; section boxes start closed behind their
 * "Comment" button.
 */
export function proposalComment(startOpen = false) {
    const identity = readIdentity();

    return {
        open: startOpen,
        name: identity.name,
        email: identity.email,
        sending: false,

        toggle() {
            this.open = !this.open;

            if (this.open) {
                this.$nextTick(() => {
                    const field = this.name ? this.$refs.body : this.$refs.name;
                    field?.focus();
                });
            }
        },

        submit() {
            writeIdentity(this.name.trim(), this.email.trim());
            this.sending = true;
        },
    };
}
