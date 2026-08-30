/**
 * The inbox thread's Alpine component.
 *
 * Registered as Alpine.data('whatsappInboxThread', ...) from app.js (see the
 * import there) rather than defined inline in show.blade.php -- the polling
 * loop and its fetch calls are the one piece of this screen substantial
 * enough to be worth testing and reading outside a <script> tag.
 *
 * config carries everything server-rendered: the initial messages (already
 * in occurred_at order), the highest id among them, and the two URLs this
 * never has to build itself.
 */
export function whatsappInboxThread(config) {
    return {
        messages: config.messages ?? [],
        lastId: config.lastId ?? 0,
        polling: null,

        init() {
            // Marked read once, on open -- not on every poll tick. A poll is
            // the same open thread catching up, not a new "you have unread
            // messages" event, so it must never re-fire this.
            this.markRead();
            this.scrollToBottom();
            this.polling = setInterval(() => this.poll(), 4000);

            // Alpine tears down x-data'd elements on their own navigation,
            // but this page can also be replaced by the nav-progress bar's
            // full reload -- pagehide covers both without leaking a timer
            // into whatever loads next.
            window.addEventListener('pagehide', () => this.stop(), { once: true });
        },

        stop() {
            if (this.polling) {
                clearInterval(this.polling);
                this.polling = null;
            }
        },

        async markRead() {
            try {
                await fetch(config.readUrl, { method: 'POST', headers: this.headers() });
            } catch (e) {
                // Best effort -- the badge is also cleared by a real page
                // load of show(), so one dropped call here is not the only
                // chance it gets.
            }
        },

        async poll() {
            try {
                const response = await fetch(`${config.messagesUrl}?after=${this.lastId}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json();

                if (Array.isArray(data.messages) && data.messages.length > 0) {
                    this.messages.push(...data.messages);
                    this.lastId = data.messages[data.messages.length - 1].id;
                    this.scrollToBottom();
                }
            } catch (e) {
                // A dropped poll just waits for the next tick.
            }
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.thread;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        headers() {
            return {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'X-Requested-With': 'XMLHttpRequest',
            };
        },
    };
}
