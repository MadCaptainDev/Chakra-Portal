/*
 * The Notion ↔ Clients screen (resources/views/content-accounts/edit.blade.php).
 *
 * Every change saves the moment it is made -- no form, no "save all" at the
 * bottom -- and the server answers each one with the whole fresh state
 * (App\Support\NotionConnections::state()), which simply replaces ours: the
 * page can never drift from the database. Each change also offers Undo,
 * which is the same call the other way round.
 *
 * One picker (a sheet on a phone, a dialog on a desk) serves every "choose
 * where this goes" question: a venture to an account, a shoot name to a
 * client, a venture onto an account from the client's side. It can also
 * make the client and account on the spot, so a venture whose client is not
 * set up yet is one tap, not three screens.
 */
export function notionConnections(config) {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

    return {
        s: config.state,
        routes: config.routes,
        tab: config.state.stats.ventures_waiting > 0 ? 'connect' : 'clients',
        q: '',
        clientFilter: 'all',
        busy: false,
        toasts: [],
        picker: null,
        saved: {},

        // ---- lookups --------------------------------------------------------
        client(id) { return this.s.clients.find((c) => c.id === id) || null; },
        account(id) { return this.s.accounts.find((a) => a.id === id) || null; },
        accountLabel(id) {
            const a = this.account(id);
            if (!a) return '';
            const c = this.client(a.client_id);
            return c && c.name !== a.name ? `${c.name} · ${a.name}` : a.name;
        },
        initials(name) {
            return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
        },
        matches(...texts) {
            const q = this.q.trim().toLowerCase();
            return !q || texts.some((t) => (t || '').toLowerCase().includes(q));
        },

        // ---- what each tab lists ---------------------------------------------
        get pct() {
            const { connected_items: c, total_items: t } = this.s.stats;
            return t ? Math.floor((c / t) * 100) : 100;
        },
        get toConnect() {
            return this.s.ventures.filter((v) => !v.account_id && !v.ignored && this.matches(v.name, ...v.samples));
        },
        get ignored() {
            return [
                ...this.s.ventures.filter((v) => v.ignored).map((v) => ({ kind: 'venture', name: v.name, detail: `${v.items} items` })),
                ...this.s.shootNames.filter((sh) => sh.ignored).map((sh) => ({ kind: 'shoot_client', name: sh.name, detail: `${sh.shoots} shoots` })),
            ].filter((i) => this.matches(i.name));
        },
        get shoots() {
            return this.s.shootNames
                .filter((sh) => !sh.ignored && this.matches(sh.name, this.client(sh.client_id)?.name))
                .sort((a, b) => (b.unmapped > 0 || b.split) - (a.unmapped > 0 || a.split) || b.shoots - a.shoots);
        },
        get clientCards() {
            return this.s.clients
                .map((c) => {
                    const accounts = this.s.accounts.filter((a) => a.client_id === c.id).map((a) => ({
                        ...a,
                        ventures: this.s.ventures.filter((v) => v.account_id === a.id),
                    }));
                    const shootNames = this.s.shootNames.filter((sh) => sh.client_id === c.id && !sh.ignored);
                    const items = accounts.reduce((n, a) => n + a.ventures.reduce((m, v) => m + v.items, 0), 0);
                    const connected = accounts.some((a) => a.ventures.length) || shootNames.length > 0;
                    return { ...c, accounts, shootNames, items, connected };
                })
                .filter((c) => this.clientFilter === 'all'
                    || (this.clientFilter === 'missing' && !c.connected)
                    || (this.clientFilter === 'active' && c.active))
                .filter((c) => this.matches(c.name, ...c.accounts.map((a) => a.name), ...c.accounts.flatMap((a) => a.ventures.map((v) => v.name)), ...c.shootNames.map((sh) => sh.name)))
                .sort((a, b) => (b.active - a.active) || a.name.localeCompare(b.name));
        },
        get counts() {
            return {
                connect: this.s.ventures.filter((v) => !v.account_id && !v.ignored).length,
                clients: this.s.clients.filter((c) => !this.s.accounts.some((a) => a.client_id === c.id && this.s.ventures.some((v) => v.account_id === a.id))).length,
                shoots: this.s.shootNames.filter((sh) => !sh.ignored && (sh.unmapped > 0 || sh.split)).length,
                ignored: this.s.ventures.filter((v) => v.ignored).length + this.s.shootNames.filter((sh) => sh.ignored).length,
            };
        },

        // ---- talking to the server --------------------------------------------
        async call(method, url, body, undo = null) {
            this.busy = true;
            try {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: body ? JSON.stringify(body) : undefined,
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                    throw new Error(first || data.message || 'That did not save. Try again.');
                }
                this.s = data.state;
                if (data.message) this.toast(data.message, undo);
                return data;
            } catch (error) {
                this.toast(error.message, null, 'error');
                return null;
            } finally {
                this.busy = false;
            }
        },

        toast(text, undo = null, tone = 'ok') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, text, undo, tone });
            setTimeout(() => this.dismiss(id), undo ? 7000 : 4000);
        },
        dismiss(id) { this.toasts = this.toasts.filter((t) => t.id !== id); },
        async undo(toast) {
            this.dismiss(toast.id);
            await toast.undo();
        },

        // ---- the actions ------------------------------------------------------
        connect(venture, accountId) {
            const before = this.s.ventures.find((v) => v.name === venture)?.account_id ?? null;
            this.closePicker();
            return this.call('POST', this.routes.connectVenture, { venture, account_id: accountId },
                () => this.call('POST', this.routes.connectVenture, { venture, account_id: before }));
        },
        disconnect(venture) { return this.connect(venture, null); },

        ignore(kind, name, ignored = true) {
            return this.call('POST', this.routes.ignore, { kind, name, ignored },
                () => this.call('POST', this.routes.ignore, { kind, name, ignored: !ignored }));
        },

        connectShoot(name, clientId) {
            const before = this.s.shootNames.find((sh) => sh.name === name)?.client_id ?? null;
            this.closePicker();
            return this.call('POST', this.routes.connectShoot, { name, client_id: clientId },
                () => this.call('POST', this.routes.connectShoot, { name, client_id: before }));
        },

        async createAndConnect() {
            const p = this.picker;
            const body = {
                venture: p.venture,
                account_name: (p.accountName || '').trim(),
                ...(p.step === 'new-client' ? { client_name: (p.clientName || '').trim() } : { client_id: p.clientId }),
            };
            const data = await this.call('POST', this.routes.createAndConnect, body,
                () => this.call('POST', this.routes.connectVenture, { venture: p.venture, account_id: null }));
            if (data) this.closePicker();
        },

        async saveAccount(account, field, value) {
            const key = `${account.id}.${field}`;
            const payload = { [field]: field === 'name' ? value : (value === '' || value === null ? null : Number(value)) };
            if (field === 'name' && !String(value).trim()) return;
            const data = await this.call('PATCH', this.routes.quick.replace('__ID__', account.id), payload);
            if (data) {
                this.saved[key] = true;
                setTimeout(() => { this.saved[key] = false; }, 1500);
            }
        },

        addAccount(clientId, name) {
            if (!name || !name.trim()) return;
            return this.call('POST', this.routes.store, { client_id: clientId, name: name.trim() });
        },

        deleteAccount(account) {
            const ventures = this.s.ventures.filter((v) => v.account_id === account.id).length;
            const note = ventures ? ` Its ${ventures} venture(s) go back to To connect — no content is deleted.` : '';
            if (!window.confirm(`Delete the account “${account.name}”?${note}`)) return;
            return this.call('DELETE', this.routes.destroy.replace('__ID__', account.id));
        },

        autoMatch() { return this.call('POST', this.routes.autoMap); },

        // ---- the picker -------------------------------------------------------
        openPicker(mode, target) {
            this.picker = {
                mode,                 // 'venture' | 'shoot' | 'account'
                venture: mode === 'venture' ? target : null,
                shoot: mode === 'shoot' ? target : null,
                accountId: mode === 'account' ? target : null,
                step: 'pick',         // 'pick' | 'new-client' | 'new-account'
                q: '',
                clientId: null,
                clientName: mode === 'venture' ? target : '',
                accountName: mode === 'venture' ? target : '',
            };
            this.$nextTick(() => this.$refs.pickerSearch?.focus());
        },
        closePicker() { this.picker = null; },

        pickerMatches(...texts) {
            const q = (this.picker?.q || '').trim().toLowerCase();
            return !q || texts.some((t) => (t || '').toLowerCase().includes(q));
        },
        get pickerTitle() {
            const p = this.picker;
            if (!p) return '';
            if (p.mode === 'venture') return `Where does “${p.venture}” belong?`;
            if (p.mode === 'shoot') return `Whose shoots are “${p.shoot}”?`;
            return `Add a venture to ${this.accountLabel(p.accountId)}`;
        },
        get pickerSuggestions() {
            const p = this.picker;
            if (!p || p.mode === 'account') return [];
            const list = p.mode === 'venture'
                ? this.s.ventures.find((v) => v.name === p.venture)?.suggestions
                : this.s.shootNames.find((sh) => sh.name === p.shoot)?.suggestions;
            return list || [];
        },
        // Accounts grouped under their clients, for a venture.
        get pickerGroups() {
            return this.s.clients
                .map((c) => ({ client: c, accounts: this.s.accounts.filter((a) => a.client_id === c.id && this.pickerMatches(a.name, c.name)) }))
                .filter((g) => g.accounts.length || this.pickerMatches(g.client.name))
                .sort((a, b) => (b.client.active - a.client.active) || a.client.name.localeCompare(b.client.name));
        },
        get pickerClients() {
            return this.s.clients.filter((c) => this.pickerMatches(c.name))
                .sort((a, b) => (b.active - a.active) || a.name.localeCompare(b.name));
        },
        // Ventures that could go onto an account: waiting ones first, then
        // ones connected elsewhere (moving them is allowed, and says so).
        get pickerVentures() {
            const p = this.picker;
            return this.s.ventures
                .filter((v) => !v.ignored && v.account_id !== p.accountId && this.pickerMatches(v.name, ...v.samples))
                .sort((a, b) => (!!a.account_id - !!b.account_id) || b.items - a.items);
        },
        startNew(step, clientId = null) {
            this.picker.step = step;
            this.picker.clientId = clientId;
            if (step === 'new-client' && this.picker.q.trim()) this.picker.clientName = this.picker.q.trim();
        },
    };
}
