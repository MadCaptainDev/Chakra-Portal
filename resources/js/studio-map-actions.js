/*
 * What the Studio Map can do (studio-map.js draws; this acts).
 *
 * Every action is a dialog over the map, posting to StudioMapActionController,
 * which runs the same App\Tools classes the MCP connector uses. Anything that
 * messages a client is two steps: the dialog fetches a preview -- recipient,
 * number, the exact words, template or plain message -- and nothing is sent
 * until "Send on WhatsApp" is pressed on that preview.
 *
 * The catalogue (actionsFor) is ordered by what the building needs: an
 * overdue client offers the invoice first, one running out of content offers
 * a shoot, and so on. The command card and the side panel both render it.
 */

/* ——— Small shared bits ——————————————————————————————————————————————— */

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const money = (n) => '₹' + Math.round(n).toLocaleString('en-IN');
export const initials = (name) => String(name).split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');

const PATHS = {
    users: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
    camera: 'm15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z',
    check: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    chat: 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
    out: 'M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25',
    info: 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
    x: 'M6 18 18 6M6 6l12 12',
    minus: 'M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
};
export const icon = (name, cls = 'h-4 w-4') =>
    `<svg class="${cls} shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${PATHS[name]}"/></svg>`;

const INPUT = 'w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/35 focus:ring-2 focus:ring-brand-400';
const field = (label, control, hint = '') => `
    <label class="block">
        <span class="text-xs font-semibold text-brand-100/70">${label}</span>
        <span class="mt-1 block">${control}</span>
        ${hint ? `<span class="mt-1 block text-[11px] text-brand-100/45">${hint}</span>` : ''}
    </label>`;

const ROLES = ['Director', 'DOP', 'Camera', 'Sound', 'Lights', 'Assistant', 'Editor'];

const pad = (n) => String(n).padStart(2, '0');
const localDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const localDateTime = (d) => `${localDate(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

/* ——— The factory ———————————————————————————————————————————————————— */

export function createActions(root, { snapshot, refresh, showPerson }) {
    const dialog = root.querySelector('[data-action-dialog]');
    const toasts = document.querySelector('[data-toasts]');
    const urls = JSON.parse(root.dataset.actions);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    async function post(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 419) throw new Error('Your session has expired. Reload the page and try again.');
            if (response.status === 429) throw new Error('That is a lot at once — wait a minute and try again.');
            const first = data.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(first || data.message || `Something went wrong (${response.status}).`);
        }

        return data;
    }

    /* ——— Toasts ——— */
    function toast(message, tone = 'success') {
        const tones = {
            success: ['bg-emerald-500/15 ring-emerald-400/40 text-emerald-100', 'check'],
            warn: ['bg-amber-500/15 ring-amber-400/40 text-amber-100', 'info'],
            error: ['bg-red-500/15 ring-red-400/40 text-red-100', 'info'],
        };
        const [cls, ic] = tones[tone];
        const el = document.createElement('div');
        el.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        el.className = `pointer-events-auto flex items-start gap-2.5 rounded-xl px-4 py-3 text-sm shadow-2xl ring-1 backdrop-blur-md bg-brand-900/90 animate-rise-in ${cls}`;
        el.innerHTML = `${icon(ic, 'h-5 w-5 mt-px')}<span class="flex-1">${esc(message)}</span>
            <button type="button" class="-mr-1 rounded p-0.5 opacity-60 hover:opacity-100" aria-label="Dismiss">${icon('x')}</button>`;
        el.querySelector('button').addEventListener('click', () => el.remove());
        toasts.appendChild(el);
        setTimeout(() => el.remove(), tone === 'success' ? 5000 : 9000);
    }

    /* ——— One dialog, many forms ——— */
    function openDialog({ eyebrow, title, body, submit, tone = 'brand', onOpen, onSubmit }) {
        const submitTone = {
            brand: 'bg-brand-400 text-brand-900 hover:bg-brand-300',
            whatsapp: 'bg-emerald-500 text-white hover:bg-emerald-400',
            danger: 'bg-red-500 text-white hover:bg-red-400',
        }[tone];

        dialog.innerHTML = `
            <form class="flex max-h-[88vh] flex-col" novalidate>
                <header class="flex items-start justify-between gap-3 px-5 pt-5 pb-3">
                    <div class="min-w-0">
                        ${eyebrow ? `<p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-300">${eyebrow}</p>` : ''}
                        <h2 class="mt-0.5 text-lg font-bold leading-snug text-white">${title}</h2>
                    </div>
                    <button type="button" data-close class="shrink-0 rounded-lg p-1.5 text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">${icon('x', 'h-5 w-5')}</button>
                </header>
                <div class="space-y-4 overflow-y-auto px-5 pb-4" data-body>${body}</div>
                <p class="mx-5 mb-3 hidden rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200" data-error role="alert"></p>
                <footer class="flex items-center justify-end gap-2 border-t border-white/10 px-5 py-4">
                    <button type="button" data-close class="rounded-lg px-4 py-2 text-sm font-semibold text-brand-100/80 hover:bg-white/10">Cancel</button>
                    <button type="submit" data-submit class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:opacity-60 ${submitTone}">${submit}</button>
                </footer>
            </form>`;

        const form = dialog.querySelector('form');
        const error = dialog.querySelector('[data-error]');
        const button = dialog.querySelector('[data-submit]');
        const label = button.innerHTML;

        dialog.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => dialog.close()));
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            error.classList.add('hidden');
            button.disabled = true;
            button.innerHTML = `<span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>${label}`;
            try {
                const result = await onSubmit(new FormData(form), dialog);
                dialog.close();
                toast(result.message);
                (result.warnings ?? []).forEach((w) => toast(w, 'warn'));
                refresh();
            } catch (e) {
                error.textContent = e.message;
                error.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.innerHTML = label;
            }
        });

        if (!dialog.open) dialog.showModal();
        onOpen?.(dialog, { button, error });
        dialog.querySelector('[data-autofocus]')?.focus();
    }

    // A click on the backdrop closes it, like Esc does.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    /* ——— Lookups ——— */
    const s = () => snapshot();
    const person = (id) => s().team.find((p) => p.id === id);
    const building = (id) => s().buildings.find((b) => b.id === id);
    const teamOptions = (selected) => s().team
        .map((p) => `<option value="${p.id}" ${p.id === selected ? 'selected' : ''}>${esc(p.name)}</option>`).join('');

    /* ——— Assign crew ——— */
    function openAssign({ personId = null, clientId = null } = {}) {
        const clients = clientId ? [building(clientId)] : s().buildings.filter((b) => b.upcoming_shoots.length);
        const options = clients.map((b) => {
            const shoots = b.upcoming_shoots.map((sh) => {
                const already = personId && sh.crew.some((c) => c.id === personId);
                return `<option value="${sh.id}" ${already ? 'disabled' : ''}>${esc(sh.title)} — ${esc(sh.when)}${already ? ' (already on it)' : ''}</option>`;
            }).join('');
            return clientId ? shoots : `<optgroup label="${esc(b.name)}">${shoots}</optgroup>`;
        }).join('');

        if (!options) {
            toast('No shoots in the next two weeks to add anyone to. Book one first.', 'warn');
            return;
        }

        const p = personId ? person(personId) : null;
        openDialog({
            eyebrow: clientId ? esc(building(clientId).name) : 'Crew',
            title: p ? `Add ${esc(p.name)} to a shoot` : 'Add crew to a shoot',
            submit: `${icon('users')}Add to crew`,
            body: `
                ${p ? `<input type="hidden" name="user_id" value="${p.id}">` : field('Who', `<select name="user_id" required class="${INPUT}" data-autofocus><option value="">Choose…</option>${teamOptions()}</select>`)}
                ${field('Shoot', `<select name="shoot_id" required class="${INPUT}" ${p ? 'data-autofocus' : ''}>${options}</select>`)}
                <div class="grid grid-cols-2 gap-3">
                    ${field('Role', `<input name="role" list="studio-map-roles" maxlength="80" placeholder="e.g. Camera" class="${INPUT}">
                        <datalist id="studio-map-roles">${ROLES.map((r) => `<option value="${r}">`).join('')}</datalist>`)}
                    ${field('Call time', `<input type="time" name="call_time" class="${INPUT}">`, 'Optional')}
                </div>
                <p class="text-xs text-brand-100/50">They get a push notification if the shoot is still ahead.</p>`,
            onSubmit: (data) => post(urls.assignCrew, {
                shoot_id: Number(data.get('shoot_id')),
                user_id: Number(data.get('user_id')),
                role: data.get('role') || null,
                call_time: data.get('call_time') || null,
            }),
        });
    }

    /* ——— Remove crew ——— */
    function openRemove({ personId, shoot }) {
        const p = person(personId);
        openDialog({
            eyebrow: 'Crew',
            title: `Take ${esc(p?.name ?? 'them')} off this shoot?`,
            tone: 'danger',
            submit: `${icon('minus')}Remove from crew`,
            body: `<div class="rounded-xl bg-white/5 p-3 text-sm">
                    <p class="font-semibold text-white">${esc(shoot.title)}</p>
                    <p class="text-brand-100/60">${esc(shoot.when ?? '')}</p>
                </div>
                <p class="text-sm text-brand-100/70">They will no longer see it in My Shoots.</p>`,
            onSubmit: () => post(urls.removeCrew, { shoot_id: shoot.id, user_id: personId }),
        });
    }

    /* ——— Book a shoot ——— */
    function openBookShoot({ clientId, personId = null }) {
        const b = building(clientId);
        const p = personId ? person(personId) : null;
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(10, 0, 0, 0);
        const runway = b.content?.days_left != null ? `${b.content.days_left} days of reels left` : null;

        openDialog({
            eyebrow: esc(b.name),
            title: p ? `Book a shoot with ${esc(p.name)}` : 'Book a shoot',
            submit: `${icon('camera')}Book shoot`,
            body: `
                ${runway ? `<p class="rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-200">${esc(runway)} — content ${b.content.status === 'critical' ? 'runs out within a week' : 'is running down'}.</p>` : ''}
                ${field('Title', `<input name="title" required maxlength="160" value="${esc(b.name)} shoot" class="${INPUT}" data-autofocus>`)}
                <div class="grid grid-cols-2 gap-3">
                    ${field('Starts', `<input type="datetime-local" name="starts_at" required value="${localDateTime(tomorrow)}" min="${localDateTime(new Date())}" class="${INPUT}">`)}
                    ${field('Location', `<input name="location" maxlength="255" placeholder="Optional" class="${INPUT}">`)}
                </div>
                <fieldset>
                    <legend class="text-xs font-semibold text-brand-100/70">Status</legend>
                    <div class="mt-1 grid grid-cols-2 gap-1 rounded-lg bg-white/5 p-1">
                        ${['planned', 'confirmed'].map((v, i) => `
                            <label class="cursor-pointer">
                                <input type="radio" name="status" value="${v}" class="peer sr-only" ${i === 0 ? 'checked' : ''}>
                                <span class="block rounded-md py-1.5 text-center text-sm font-semibold text-brand-100/70 peer-checked:bg-brand-400 peer-checked:text-brand-900 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-300">${v === 'planned' ? 'Planned' : 'Confirmed'}</span>
                            </label>`).join('')}
                    </div>
                </fieldset>
                ${p ? `<div class="flex items-end gap-3 rounded-xl bg-white/5 p-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-400 text-xs font-bold text-brand-900">${esc(initials(p.name))}</span>
                        <span class="flex-1">${field(`${esc(p.name)} joins as`, `<input name="crew_role" list="studio-map-roles" maxlength="80" placeholder="Role (optional)" class="${INPUT}">
                            <datalist id="studio-map-roles">${ROLES.map((r) => `<option value="${r}">`).join('')}</datalist>`)}</span>
                    </div>` : ''}
                ${field('Notes for the crew', `<textarea name="notes" rows="2" maxlength="5000" placeholder="Optional" class="${INPUT}"></textarea>`)}`,
            onSubmit: (data) => post(urls.createShoot, {
                client_id: clientId,
                title: data.get('title'),
                starts_at: String(data.get('starts_at')).replace('T', ' '),
                location: data.get('location') || null,
                status: data.get('status'),
                notes: data.get('notes') || null,
                crew_user_id: p?.id ?? null,
                crew_role: data.get('crew_role') || null,
            }),
        });
    }

    /* ——— To-do ——— */
    function openTodo({ personId = null, clientId = null } = {}) {
        const b = clientId ? building(clientId) : null;
        const clientSelect = `<select name="client_id" class="${INPUT}"><option value="">Several clients / none</option>${s().buildings
            .slice().sort((x, y) => x.name.localeCompare(y.name))
            .map((x) => `<option value="${x.id}" ${x.id === clientId ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>`;

        openDialog({
            eyebrow: b ? esc(b.name) : 'To-do',
            title: 'Add a to-do',
            submit: `${icon('check')}Add to-do`,
            body: `
                ${field('For', `<select name="user_id" required class="${INPUT}" ${personId ? '' : 'data-autofocus'}><option value="">Choose…</option>${teamOptions(personId)}</select>`)}
                ${field('What needs doing', `<input name="title" required maxlength="255" placeholder="e.g. Send SVA the edited reels" class="${INPUT}" ${personId ? 'data-autofocus' : ''}>`)}
                <div class="grid grid-cols-2 gap-3">
                    ${field('Client', clientSelect)}
                    ${field('Due', `<input type="date" name="due_on" value="${localDate(new Date())}" min="${localDate(new Date())}" class="${INPUT}">`)}
                </div>
                ${field('Notes', `<textarea name="notes" rows="2" maxlength="5000" placeholder="Optional" class="${INPUT}"></textarea>`)}`,
            onSubmit: (data) => post(urls.createTodo, {
                user_id: Number(data.get('user_id')),
                title: data.get('title'),
                client_id: data.get('client_id') ? Number(data.get('client_id')) : null,
                due_on: data.get('due_on') || null,
                notes: data.get('notes') || null,
            }),
        });
    }

    /* ——— WhatsApp sends: choose, preview, confirm ——— */
    function openSend(kind, { clientId }) {
        const b = building(clientId);
        const choices = kind === 'invoice'
            ? b.unpaid_invoices.filter((i) => i.sendable).map((i) => ({ id: i.id, label: i.number, detail: `${money(i.balance)}${i.overdue_days ? ` · ${i.overdue_days} days overdue` : ' · not yet due'}`, late: i.overdue_days > 0 }))
            : kind === 'proposal'
                ? b.open_proposals.map((p) => ({ id: p.id, label: p.title, detail: p.status === 'viewed' ? 'Opened by them' : 'Not opened yet', late: false }))
                : [{ id: b.id, label: b.name, detail: '' }];
        const what = { invoice: 'invoice', proposal: 'proposal', brief: 'brief reminder' }[kind];

        openDialog({
            eyebrow: `${esc(b.name)} · WhatsApp`,
            title: kind === 'brief' ? 'Send a brief reminder' : `Re-send ${what}`,
            tone: 'whatsapp',
            submit: `${icon('chat')}Send on WhatsApp`,
            body: `
                ${choices.length > 1 ? `<fieldset class="space-y-1.5">
                    <legend class="mb-1 text-xs font-semibold text-brand-100/70">Which ${what}</legend>
                    ${choices.map((c, i) => `
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg bg-white/5 px-3 py-2 ring-1 ring-transparent has-[:checked]:ring-emerald-400/60">
                            <input type="radio" name="choice" value="${c.id}" ${i === 0 ? 'checked' : ''} class="text-emerald-500 focus:ring-emerald-400">
                            <span class="flex-1 text-sm font-semibold text-white">${esc(c.label)}</span>
                            <span class="text-xs ${c.late ? 'text-red-300' : 'text-brand-100/60'}">${esc(c.detail)}</span>
                        </label>`).join('')}
                </fieldset>` : `<input type="hidden" name="choice" value="${choices[0].id}">`}
                <div data-preview class="min-h-[160px]"></div>`,
            onOpen: (dlg, { button }) => {
                const load = async () => {
                    const box = dlg.querySelector('[data-preview]');
                    const chosen = dlg.querySelector('[name="choice"]:checked') ?? dlg.querySelector('[name="choice"]');
                    const id = Number(chosen.value);
                    button.disabled = true;
                    box.innerHTML = `<div class="flex h-40 items-center justify-center text-sm text-brand-100/50"><span class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>Getting the message ready…</div>`;
                    try {
                        const p = await post(urls.preview, { kind, id });
                        box.innerHTML = previewHtml(p);
                        button.disabled = false;
                    } catch (e) {
                        box.innerHTML = `<div class="rounded-xl bg-red-500/10 p-4 text-sm text-red-200">${esc(e.message)}
                            <a href="${esc(b.links.client)}" class="mt-2 block font-semibold underline">Open ${esc(b.name)}</a></div>`;
                    }
                };
                dlg.querySelectorAll('[name="choice"]').forEach((r) => r.addEventListener('change', load));
                load();
            },
            onSubmit: (data) => post(urls.send, {
                kind,
                id: Number(data.get('choice')),
                phone: data.get('phone') || null,
            }),
        });
    }

    function previewHtml(p) {
        const viaTemplate = p.channel === 'template';
        return `
            <div class="space-y-3">
                <div class="flex items-center gap-3 rounded-xl bg-white/5 p-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/20 text-sm font-bold text-emerald-200">${esc(initials(p.to ?? '?'))}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">${esc(p.to)}</p>
                        ${p.phone_editable
                            ? `<input name="phone" required value="${esc(p.phone ?? '')}" placeholder="Their WhatsApp number" aria-label="WhatsApp number" class="mt-1 w-full rounded-md border-0 bg-white/10 px-2 py-1 text-sm text-white focus:ring-2 focus:ring-emerald-400">`
                            : `<p class="text-sm text-brand-100/70">${esc(p.phone)}</p><input type="hidden" name="phone" value="">`}
                    </div>
                </div>
                <div class="rounded-2xl bg-[#0b141a] p-3">
                    <div class="ml-auto max-w-[92%] rounded-xl rounded-tr-sm bg-[#005c4b] px-3 py-2 text-[13px] leading-relaxed text-white shadow">
                        <p class="whitespace-pre-line">${esc(p.message)}</p>
                        ${p.button ? `<div class="mt-2 border-t border-white/15 pt-2 text-center text-[13px] font-semibold text-sky-300">${esc(p.button)}</div>` : ''}
                    </div>
                </div>
                <p class="flex items-start gap-2 text-xs text-brand-100/60">${icon('info', 'h-4 w-4 mt-px')}
                    ${viaTemplate
                        ? 'Goes as an approved WhatsApp template, so it arrives even if they have not messaged us lately.'
                        : 'They messaged us in the last 24 hours, so this goes as a normal message.'}
                </p>
            </div>`;
    }

    /* ——— What a building offers, most-needed first ——— */
    function actionsFor(b) {
        const list = [];
        const sendable = b.unpaid_invoices.filter((i) => i.sendable);
        const crewCount = b.upcoming_shoots.reduce((n, sh) => n + sh.crew.length, 0);

        if (b.upcoming_shoots.length) {
            list.push({ key: 'assign', icon: 'users', label: 'Add crew to a shoot', hint: `${b.upcoming_shoots.length} upcoming · ${crewCount} crew`, run: () => openAssign({ clientId: b.id }) });
        }
        list.push({ key: 'book', icon: 'camera', label: 'Book a shoot', hint: b.content?.days_left != null ? `${b.content.days_left}d of reels left` : (b.next_shoot ? `Next: ${b.next_shoot.when}` : 'None booked'), run: () => openBookShoot({ clientId: b.id }) });
        if (sendable.length) {
            const worst = sendable[0];
            list.push({ key: 'invoice', icon: 'chat', wa: true, label: worst.overdue_days ? 'Chase payment: re-send invoice' : 'Re-send invoice', hint: `${money(worst.balance)}${worst.overdue_days ? ` · ${worst.overdue_days}d overdue` : ''}`, run: () => openSend('invoice', { clientId: b.id }) });
        }
        if (b.open_proposals.length) {
            list.push({ key: 'proposal', icon: 'chat', wa: true, label: 'Re-send proposal', hint: b.open_proposals[0].title, run: () => openSend('proposal', { clientId: b.id }) });
        }
        if (b.brief_pending) {
            list.push({ key: 'brief', icon: 'chat', wa: true, label: 'Send brief reminder', hint: 'Brief not submitted', run: () => openSend('brief', { clientId: b.id }) });
        }
        list.push({ key: 'todo', icon: 'check', label: 'Add a to-do', hint: b.venture ?? '', run: () => openTodo({ clientId: b.id }) });

        // What this building most needs goes first.
        const content = b.content?.status;
        const first = b.overdue_days > 0 ? 'invoice'
            : content === 'critical' || content === 'warning' ? 'book'
                : b.state === 'waiting' ? (b.open_proposals.length ? 'proposal' : 'brief')
                    : b.state === 'shoot' || b.state === 'rolling' ? 'assign'
                        : b.upcoming_shoots.length ? 'assign' : 'book';
        const i = list.findIndex((a) => a.key === first);
        if (i > 0) list.unshift(...list.splice(i, 1));
        if (list[0]) list[0].primary = true;

        return list;
    }

    return { actionsFor, openAssign, openRemove, openBookShoot, openTodo, openSend, toast, showPerson, post, urls };
}
