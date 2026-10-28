/**
 * The inbox's Alpine components: the open thread, and the conversation list
 * beside it.
 *
 * Registered as Alpine.data(...) from app.js rather than defined inline in
 * the Blade views -- the polling loops, the sends and the template picker
 * are substantial enough to be worth reading outside a <script> tag.
 *
 * config carries everything server-rendered: the initial messages (already
 * in occurred_at order, each one we sent with its delivery status), the
 * highest id among them, the 24-hour window, the approved templates and
 * saved replies, and every URL this never has to build itself.
 */
export function whatsappInboxThread(config) {
    return {
        messages: config.messages ?? [],
        lastId: config.lastId ?? 0,
        windowOpen: !!config.windowOpen,
        windowClosesAt: config.windowClosesAt ? new Date(config.windowClosesAt) : null,
        templates: config.templates ?? [],
        quickReplies: config.quickReplies ?? [],
        canReply: !!config.canReply,
        details: !!config.detailsOpen,

        body: '',
        sending: false,
        error: '',
        showQuick: false,
        atBottom: true,

        picking: false,
        chosen: null,
        params: [],
        buttonParam: '',

        polling: null,
        now: Date.now(),

        init() {
            // Marked read once, on open -- not on every poll tick. A poll is
            // the same open thread catching up, not a new "you have unread
            // messages" event, so it must never re-fire this.
            this.markRead();
            this.scrollToBottom(true);
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
            this.now = Date.now();

            try {
                const response = await fetch(`${config.messagesUrl}?after=${this.lastId}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });
                const data = await response.json();

                // Delivered / read / failed for what is already on screen.
                if (data.statuses) {
                    this.messages.forEach((message) => {
                        const update = data.statuses[message.id];
                        if (update) {
                            message.status = update.status;
                            message.error = update.error;
                        }
                    });
                }

                if (typeof data.window_open === 'boolean') {
                    this.windowOpen = data.window_open;
                    this.windowClosesAt = data.window_closes_at ? new Date(data.window_closes_at) : null;
                }

                if (Array.isArray(data.messages) && data.messages.length > 0) {
                    const known = new Set(this.messages.map((message) => message.id));
                    const fresh = data.messages.filter((message) => !known.has(message.id));
                    const stick = this.atBottom;

                    this.messages.push(...fresh);
                    this.lastId = data.messages[data.messages.length - 1].id;
                    if (stick) this.scrollToBottom();
                }
            } catch (e) {
                // A dropped poll just waits for the next tick.
            }
        },

        // -- sending --------------------------------------------------------

        onEnter(event) {
            // Enter sends on a keyboard; Shift+Enter (and every phone, whose
            // Enter key is the only way to type a new line) adds a line.
            if (event.shiftKey || window.matchMedia('(pointer: coarse)').matches) return;
            event.preventDefault();
            this.sendText();
        },

        async sendText() {
            const body = this.body.trim();
            if (body === '' || this.sending) return;

            const ok = await this.post(config.replyUrl, { body });
            if (ok) {
                this.body = '';
                this.$nextTick(() => this.grow());
            }
        },

        async sendTemplate() {
            if (!this.chosen || this.sending) return;

            const ok = await this.post(config.templateUrl, {
                template: this.chosen.name,
                params: this.params,
                button_param: this.chosen.has_url_button ? this.buttonParam : null,
            });
            if (ok) this.closeTemplates();
        },

        async post(url, payload) {
            this.sending = true;
            this.error = '';

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { ...this.headers(), 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const errors = data.errors ? Object.values(data.errors).flat() : [];
                    this.error = errors[0] || data.message || 'Could not send. Try again.';

                    // The server is the one that knows the window just closed.
                    if (response.status === 422 && /24 hours/.test(this.error)) {
                        this.windowOpen = false;
                    }
                    return false;
                }

                if (data.message && !this.messages.some((message) => message.id === data.message.id)) {
                    this.messages.push(data.message);
                    this.lastId = Math.max(this.lastId, data.message.id);
                }
                this.scrollToBottom(true);
                return true;
            } catch (e) {
                this.error = 'No connection. Your message was not sent.';
                return false;
            } finally {
                this.sending = false;
            }
        },

        useQuick(reply) {
            this.body = this.body ? `${this.body}\n${reply.content}` : reply.content;
            this.showQuick = false;
            this.$nextTick(() => {
                this.grow();
                this.$refs.body?.focus();
            });
        },

        // -- templates ------------------------------------------------------

        openTemplates() {
            this.picking = true;
            this.showQuick = false;
            this.error = '';
            if (this.templates.length === 1) this.choose(this.templates[0]);
        },

        closeTemplates() {
            this.picking = false;
            this.chosen = null;
            this.error = '';
        },

        choose(template) {
            this.chosen = template;
            this.params = [...(template.prefill ?? Array(template.placeholder_count).fill(''))];
            this.buttonParam = '';
            this.error = '';
        },

        preview() {
            if (!this.chosen) return '';
            return this.chosen.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (match, n) => {
                const value = this.params[Number(n) - 1];
                return value ? value : `[blank ${n}]`;
            });
        },

        // -- display --------------------------------------------------------

        startsDay(index) {
            return index === 0 || this.messages[index].day !== this.messages[index - 1].day;
        },

        statusLabel(message) {
            return {
                pending: 'Sending',
                sent: 'Sent -- WhatsApp accepted it, not yet delivered',
                delivered: 'Delivered',
                read: 'Read',
                failed: 'Not delivered',
            }[message.status] ?? '';
        },

        windowLeft() {
            if (!this.windowClosesAt) return '';
            const minutes = Math.max(0, Math.round((this.windowClosesAt.getTime() - this.now) / 60000));
            if (minutes >= 60) return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
            return `${minutes}m`;
        },

        grow() {
            const el = this.$refs.body;
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
        },

        onScroll() {
            const el = this.$refs.thread;
            this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        },

        scrollToBottom(force = false) {
            this.$nextTick(() => {
                const el = this.$refs.thread;
                if (el && (force || this.atBottom)) {
                    el.scrollTop = el.scrollHeight;
                    this.atBottom = true;
                }
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

/**
 * The conversation list: refetched every 15 seconds (index?partial=1) so a
 * new message moves its thread to the top and lights its unread count
 * without a reload. Skipped while the tab is hidden or the person is
 * scrolled down the list, where swapping rows would move them under the
 * cursor.
 */
export function whatsappInboxList(config) {
    return {
        timer: null,

        init() {
            this.timer = setInterval(() => this.refresh(), 15000);
            window.addEventListener('pagehide', () => clearInterval(this.timer), { once: true });
        },

        async refresh() {
            const list = this.$refs.list;
            if (document.hidden || !list || list.scrollTop > 40) return;

            try {
                const response = await fetch(config.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (response.ok) list.innerHTML = await response.text();
            } catch (e) {
                // Next tick.
            }
        },
    };
}
