/*
 * Hook ideas for a script (Gemini): the script page's panel and the on-set
 * reader's sheet share this. writeUrl/useUrl may hold __ID__ for the script.
 */
const csrf = () => document.querySelector('meta[name=csrf-token]').content;

export function hookIdeas(writeUrl, useUrl, initial = null, canUse = false) {
    return {
        hooks: initial || [],
        note: '',
        language: 'script',
        busy: false,
        error: '',
        info: '',
        canUse,
        copied: null,
        added: {},
        scriptId: null,

        url(u) { return this.scriptId ? u.replace('__ID__', this.scriptId) : u; },
        async post(url, body) {
            const r = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify(body),
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.message || 'Something went wrong. Try again.');
            return data;
        },
        async generate() {
            if (this.busy) return;
            this.busy = true;
            this.error = '';
            try {
                const d = await this.post(this.url(writeUrl), { note: this.note || null, language: this.language, fresh: this.hooks.length > 0 });
                this.hooks = d.hooks;
                this.added = {};
                this.info = d.reused ? 'Same ask as last time: shown again at no cost.' : `Cost about $${Number(d.cost_usd).toFixed(4)} · Gemini $${d.budget.spent.toFixed(2)} of $${Number(d.budget.cap).toFixed(2)} this month.`;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.busy = false;
            }
        },
        async copy(h, n) {
            try {
                await navigator.clipboard.writeText(h.spoken + (h.on_screen ? `\nOn screen: ${h.on_screen}` : ''));
                this.copied = n;
                setTimeout(() => { this.copied = null; }, 1500);
            } catch { this.error = 'Copy did not work here. Select the text instead.'; }
        },
        async use(h, n) {
            if (!confirm('Add this hook as the first part of the script?')) return;
            try {
                const d = await this.post(this.url(useUrl), h);
                this.added[n] = true;
                this.info = d.message;
                this.$dispatch('hook-added', { scriptId: this.scriptId });
            } catch (e) { this.error = e.message; }
        },
    };
}
