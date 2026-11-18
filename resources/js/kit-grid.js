/**
 * The kit picker as a photo grid (shoots._kit-grid): tap a picture to add it,
 * tap again for one more; what always travels with it (batteries, cage,
 * charger -- EquipmentItem::bundle) comes along, marked "with". Category chips
 * and a search narrow it down. Used on the shoot page and the crew's run page.
 */
export function kitGrid(items) {
    return {
        items,
        q: '',
        cat: '',
        selected: [], // { id, quantity, max, auto }

        get categories() {
            const seen = new Map();
            this.items.forEach((i) => { if (!seen.has(i.category)) seen.set(i.category, i.order ?? 99); });
            return [...seen.entries()].sort((a, b) => a[1] - b[1] || a[0].localeCompare(b[0])).map(([name]) => name);
        },
        get shown() {
            const q = this.q.trim().toLowerCase();
            return this.items
                .filter((i) => (!this.cat || i.category === this.cat) && (!q || `${i.name} ${i.identifier || ''} ${i.category}`.toLowerCase().includes(q)))
                .sort((a, b) => (a.order ?? 99) - (b.order ?? 99) || a.name.localeCompare(b.name));
        },
        get count() { return this.selected.reduce((n, s) => n + s.quantity, 0); },
        row(id) { return this.selected.find((s) => s.id === id); },
        qty(id) { return this.row(id)?.quantity ?? 0; },

        /** Tap: add it (with what goes with it), or one more of it. */
        tap(item) {
            const row = this.row(item.id);
            if (row) { if (row.quantity < row.max) row.quantity++; row.auto = false; return; }
            this.add(item);
        },
        add(item, auto = false) {
            if (this.row(item.id)) return;
            this.selected.push({ id: item.id, quantity: 1, max: Math.max(1, item.total || 1), auto });
            (item.accessoryIds || []).forEach((id) => {
                const acc = this.items.find((i) => i.id === id);
                if (acc) this.add(acc, true);
            });
        },
        less(id) {
            const row = this.row(id);
            if (!row) return;
            if (row.quantity <= 1) { this.selected = this.selected.filter((s) => s.id !== id); return; }
            row.quantity--;
        },
        clear() { this.selected = []; },
        name(id) { return this.items.find((i) => i.id === id)?.name ?? ''; },
    };
}
