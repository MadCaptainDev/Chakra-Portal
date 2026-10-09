/*
 * The Studio Map (studio-map/index.blade.php): the studio's clients as a
 * city. The city itself -- districts, streets, buildings, cars, crew -- is
 * drawn by studio-city.js; this file runs everything around it.
 *
 *  - Lenses. Five questions of the same city -- Status, Money (rupees per
 *    hour of work), Hours, Content (days of reels left) and Crew (where
 *    everybody is). A lens decides each client's colour, height, badge and
 *    the legend.
 *  - The live feed. Today's shoot events run along the bottom, and a video's
 *    photo goes up over its client's building for a few hours.
 *  - Replay. The slider is the time of day; crew, photos, the feed and the
 *    daylight are all worked out from the timeline for whatever time it says.
 *  - Acting. Click a building for its command card -- its actions, most
 *    needed first. Drag a person from the team bench (or off the street) onto
 *    a building to put them on a shoot, or onto Chakra to take them off one.
 *    The actions live in studio-map-actions.js; this file decides when to
 *    offer them.
 *
 * The snapshot is re-fetched every 30 seconds, and straight after any action.
 */
import { createCity } from './studio-city.js';
import { createActions, esc, icon, initials, money } from './studio-map-actions.js';

const STATE = {
    overdue: { label: 'Overdue invoice', color: 0xf87171, glow: true },
    rolling: { label: 'Shoot rolling now', color: 0x34d399, glow: true },
    shoot: { label: 'Shoot today', color: 0xfbbf24, glow: false },
    waiting: { label: 'Waiting on client', color: 0x38bdf8, glow: false },
    owes: { label: 'Owes, not overdue', color: 0xa78bfa, glow: false },
    calm: { label: 'All quiet', color: 0x94a3b8, glow: false },
};
const ORDER = Object.keys(STATE);
const C = { red: 0xf87171, amber: 0xfbbf24, green: 0x34d399, sky: 0x38bdf8, slate: 0x64748b, brand: 0x67bcd4 };
const REFRESH_MS = 30000;
const MIN = 60 * 1000;
const DRAG_START_PX = 6;

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const hex = (n) => '#' + n.toString(16).padStart(6, '0');
const clockOf = (ms) => new Date(ms).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' });
const firstName = (name) => String(name).split(' ')[0];
const byStateThenName = (a, b) => ORDER.indexOf(a.state) - ORDER.indexOf(b.state) || a.name.localeCompare(b.name);
const typing = (el) => el?.closest?.('input, textarea, select, [contenteditable="true"]');
const pinned = (b) => b.lat != null && b.lng != null;
const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* private window: fine */ } },
};

/* ——— Lenses ——————————————————————————————————————————————————————— */

// Storeys: two at least, twelve at most, growing with the square root so
// one busy client does not dwarf the rest.
const floorsFor = (value, scale) => 2 + Math.min(10, Math.round(Math.sqrt(Math.max(0, value)) * scale));

const LENSES = {
    status: {
        legend: () => ORDER.map((k) => [STATE[k].color, STATE[k].label]),
        colour: (b) => STATE[b.state].color,
        glow: (b) => STATE[b.state].glow,
        floors: (b) => floorsFor(b.hours, 1.5),
        badge: (b) => {
            if (b.overdue_days > 0) return `₹ ${b.overdue_days}d overdue`;
            if (b.state === 'rolling') return '● REC';
            if (b.state === 'shoot') return 'Shoot today';
            const waiting = b.scripts_waiting + b.proposals_waiting;
            return waiting ? `${waiting} waiting` : null;
        },
        loud: (b) => b.state !== 'calm',
        note: 'Height: hours this month',
    },
    money: {
        legend: (s) => [
            [C.green, `At or above studio average${s.totals.per_hour ? ` (${money(s.totals.per_hour)}/hr)` : ''}`],
            [C.amber, 'Below average'],
            [C.red, 'Under 60% of average'],
            [C.slate, 'No hours logged this month'],
        ],
        colour: (b, s) => {
            const avg = s.totals.per_hour;
            if (b.per_hour == null || !avg) return C.slate;
            if (b.per_hour >= avg) return C.green;
            return b.per_hour >= avg * 0.6 ? C.amber : C.red;
        },
        glow: () => false,
        floors: (b) => floorsFor(b.invoiced_month / 1000, 1.3),
        badge: (b) => (b.per_hour != null ? `${money(b.per_hour)}/hr` : null),
        loud: (b) => b.per_hour != null,
        note: 'Height: invoiced this month',
    },
    hours: {
        legend: () => [[0x2f6e84, 'Under 10 hours'], [0x4fa9c4, '10–30 hours'], [0x8acce0, '30+ hours'], [C.slate, 'None this month']],
        colour: (b) => (b.hours <= 0 ? C.slate : b.hours < 10 ? 0x2f6e84 : b.hours < 30 ? 0x4fa9c4 : 0x8acce0),
        glow: () => false,
        floors: (b) => floorsFor(b.hours, 1.5),
        badge: (b) => (b.hours > 0 ? `${b.hours.toFixed(1)}h` : null),
        loud: (b) => b.hours > 0,
        note: 'Height: hours this month',
    },
    content: {
        legend: () => [[C.red, 'Runs out within a week, no shoot booked'], [C.amber, 'Runs out within 3 weeks'], [C.green, 'Enough content'], [C.slate, 'Not tracked']],
        colour: (b) => ({ critical: C.red, warning: C.amber, ok: C.green })[b.content?.status] ?? C.slate,
        glow: (b) => b.content?.status === 'critical',
        floors: () => 4,
        badge: (b) => {
            if (!b.content) return null;
            if (b.content.days_left != null) return b.content.days_left <= 0 ? 'Out of content' : `${b.content.days_left}d left`;
            return `${b.content.remaining} reels`;
        },
        gauge: (b) => (b.content?.days_left != null ? Math.max(0, Math.min(1, b.content.days_left / 42)) : null),
        loud: (b) => ['critical', 'warning'].includes(b.content?.status),
        note: 'Roof tank: six weeks of reels = full',
    },
    crew: {
        legend: () => [[C.green, 'On site'], [C.amber, 'On the way'], [C.brand, 'At the studio']],
        colour: () => 0x8796a0,
        glow: () => false,
        floors: () => 2,
        badge: () => null,
        loud: () => false,
        note: 'Drag anyone onto a building, or onto Chakra',
    },
};
const LENS_KEYS = Object.keys(LENSES);

/* ——— Where a crew member is at time t ——————————————————————————————————
 * home: at the studio. walking: on the way -- only once someone has tapped
 * "We're on the way" in the Shoot Updater; progress runs from that moment to
 * the arrival, or to the server's travel-time guess until there is one.
 * site: on location (reached, or rolling).
 */
function whereAt(shoot, t) {
    if (shoot.finished_at && t >= shoot.finished_at) return { phase: 'home' };
    const arrivals = [shoot.reached_at, shoot.started_at].filter(Boolean);
    const at = arrivals.length ? Math.min(...arrivals) : null;
    if (at && t >= at) return { phase: 'site' };
    if (!shoot.departed_at || t < shoot.departed_at) return { phase: 'home' };

    const trip = at ? at - shoot.departed_at : shoot.travel_ms ?? 30 * MIN;
    return { phase: 'walking', progress: Math.min(at ? 1 : 0.95, (t - shoot.departed_at) / Math.max(trip, MIN)) };
}

const PHASE = {
    site: { label: 'On site', dot: 'bg-emerald-400', ring: 'ring-emerald-400/70', color: C.green },
    walking: { label: 'On the way', dot: 'bg-amber-400', ring: 'ring-amber-400/70', color: C.amber },
    home: { label: 'At the studio', dot: 'bg-brand-400', ring: 'ring-white/15', color: C.brand },
};

/* ——— The page ——————————————————————————————————————————————————————— */

function boot(root) {
    const box = root.querySelector('[data-map-box]');
    const host = root.querySelector('[data-map-canvas]');
    const card = root.querySelector('[data-command-card]');
    const panel = root.querySelector('[data-map-panel]');
    const slider = root.querySelector('[data-replay-slider]');
    const clock = root.querySelector('[data-replay-clock]');
    const liveButton = root.querySelector('[data-replay-live]');
    const playButton = root.querySelector('[data-replay-play]');

    let snapshot = JSON.parse(root.querySelector('[data-studio-map-snapshot]').textContent);
    let skew = snapshot.day.now - Date.now(); // server clock minus ours
    let lens = 'status';
    let live = true;
    let playing = false;
    let t = Date.now() + skew;
    const seen = new Set(snapshot.day.events.map((e) => e.id));
    let fresh = new Set(); // event ids that arrived on the latest refresh

    let selectedId = null; // the client the command card is for ('hq' for the studio)
    let panelView = null; // { type: 'building'|'person', id }
    let mapApi = null; // set once the map is up

    const actions = createActions(root, {
        snapshot: () => snapshot,
        refresh: () => refresh(),
        showPerson: (id) => showPerson(id),
    });

    const now = () => Date.now() + skew;
    const building = (id) => snapshot.buildings.find((b) => b.id === id);
    const person = (id) => snapshot.team.find((p) => p.id === id);

    function statusOf(personId, time = live ? now() : t) {
        for (const s of snapshot.day.shoots) {
            if (!s.crew_ids.includes(personId)) continue;
            const where = whereAt(s, time);
            if (where.phase !== 'home') return { phase: where.phase, shoot: s };
        }

        return { phase: 'home', shoot: null };
    }

    /* ——— Command card ——— */
    function select(id) {
        if (id === 'hq') {
            selectedId = 'hq';
            return renderHqCard();
        }
        const b = building(id);
        if (!b) return closeCard();
        selectedId = id;
        if (!mapApi) return openDetails(id); // no city to pin a card to
        mapApi.flyTo(id);
        renderCard();
        mapApi.refreshPins();
    }

    function cardShell(inner) {
        card.innerHTML = `
            <div class="relative w-[min(92vw,310px)] rounded-2xl bg-brand-900/95 shadow-2xl ring-1 ring-white/15 backdrop-blur-md" data-card-inner>
                ${inner}
                <span data-card-arrow class="absolute left-1/2 h-3 w-3 -translate-x-1/2 rotate-45 bg-brand-900"></span>
            </div>`;
        card.querySelector('[data-card-close]')?.addEventListener('click', closeCard);
        card.classList.remove('hidden');
        positionCard();
    }

    function renderCard() {
        const b = building(selectedId);
        if (!b) return closeCard();
        const style = STATE[b.state];
        const list = actions.actionsFor(b);
        const badge = LENSES[lens].badge(b);

        cardShell(`
            <div class="flex items-start gap-3 p-3 pb-2">
                <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" style="background:${hex(style.color)}"></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-bold leading-tight text-white">${esc(b.name)}</p>
                    <p class="truncate text-xs text-brand-100/60">${esc(style.label)}${badge && lens !== 'status' ? ` · ${esc(badge)}` : ''}</p>
                </div>
                <button type="button" data-card-close class="-mr-1 -mt-1 rounded-lg p-1.5 text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">${icon('x')}</button>
            </div>
            <div class="space-y-0.5 px-1.5 pb-1.5">
                ${list.map((a, i) => `
                    <button type="button" data-act="${i}"
                            class="group flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition ${a.primary ? 'bg-brand-400 text-brand-900 hover:bg-brand-300' : 'text-white hover:bg-white/10'}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${a.primary ? 'bg-brand-900/15' : 'bg-white/10'} ${a.wa && !a.primary ? 'text-emerald-300' : ''}">${icon(a.icon)}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold leading-tight">${esc(a.label)}</span>
                            ${a.hint ? `<span class="block truncate text-xs ${a.primary ? 'text-brand-900/70' : 'text-brand-100/55'}">${esc(a.hint)}</span>` : ''}
                        </span>
                        ${a.wa ? `<span class="shrink-0 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider ${a.primary ? 'bg-brand-900/15' : 'bg-emerald-500/15 text-emerald-300'}">WhatsApp</span>` : ''}
                    </button>`).join('')}
            </div>
            <div class="flex border-t border-white/10 text-xs font-semibold">
                <button type="button" data-card-details class="flex flex-1 items-center justify-center gap-1.5 py-2.5 text-brand-100/70 hover:text-white">${icon('info')}Details</button>
                <a href="${esc(b.links.client)}" class="flex flex-1 items-center justify-center gap-1.5 border-l border-white/10 py-2.5 text-brand-100/70 hover:text-white">${icon('out')}Open</a>
            </div>`);

        card.querySelectorAll('[data-act]').forEach((button) => button.addEventListener('click', () => list[Number(button.dataset.act)].run()));
        card.querySelector('[data-card-details]').addEventListener('click', () => openDetails(b.id));
    }

    function renderHqCard() {
        const home = snapshot.districts.find((d) => d.home);
        const out = snapshot.team.filter((p) => statusOf(p.id).phase !== 'home').length;
        cardShell(`
            <div class="flex items-start gap-3 p-3">
                <div class="min-w-0 flex-1">
                    <p class="font-bold leading-tight text-white">Chakra studio</p>
                    <p class="mt-0.5 text-xs text-brand-100/60">${esc(home?.name ?? '')}${home ? ' · ' : ''}${out ? `${out} out on shoots` : 'Everyone is in'}</p>
                    <p class="mt-2 text-xs text-brand-100/50">Crew walk out from here. Drop someone on this tower to take them off a shoot.</p>
                </div>
                <button type="button" data-card-close class="-mr-1 -mt-1 rounded-lg p-1.5 text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">${icon('x')}</button>
            </div>`);
    }

    function closeCard() {
        const had = selectedId;
        selectedId = null;
        card.classList.add('hidden');
        card.innerHTML = '';
        if (had != null) mapApi?.refreshPins();
    }

    // Pinned above its pin as the map moves; a sheet on a phone.
    function positionCard() {
        if (selectedId == null || card.classList.contains('hidden') || !mapApi) return;
        const inner = card.querySelector('[data-card-inner]');
        const arrow = card.querySelector('[data-card-arrow]');
        const W = box.clientWidth;
        const H = box.clientHeight;

        if (W < 640) {
            Object.assign(card.style, { left: '8px', right: '8px', top: 'auto', bottom: '8px' });
            inner.style.width = '100%';
            arrow.classList.add('hidden');
            return;
        }

        const point = mapApi.pointOf(selectedId);
        if (!point) return;
        const w = inner.offsetWidth;
        const h = inner.offsetHeight;
        const above = point.y - h - 18 > 8;
        const x = Math.min(Math.max(point.x, w / 2 + 8), W - w / 2 - 8);
        const y = above ? point.y - h - 14 : Math.min(point.y + 14, H - h - 8);

        Object.assign(card.style, { left: `${x - w / 2}px`, top: `${y}px`, right: 'auto', bottom: 'auto' });
        inner.style.width = '';
        arrow.classList.toggle('hidden', Math.abs(x - point.x) > w / 2 - 16);
        arrow.style.left = `${point.x - x + w / 2}px`;
        Object.assign(arrow.style, above ? { bottom: '-6px', top: 'auto' } : { top: '-6px', bottom: 'auto' });
    }

    /* ——— Side panel: a client, or a person ——— */
    function openDetails(id) {
        const b = building(id);
        if (!b) return closePanel();
        panelView = { type: 'building', id };
        closeCard(); // the panel carries the same actions; one surface at a time
        renderBuildingPanel(b);
    }

    function showPerson(id) {
        const p = person(id);
        if (!p) return;
        panelView = { type: 'person', id };
        closeCard();
        renderPersonPanel(p);
        const status = statusOf(id);
        if (mapApi && status.shoot?.client_id) mapApi.flyTo(status.shoot.client_id);
    }

    function closePanel() {
        panelView = null;
        panel.classList.replace('flex', 'hidden');
    }

    function rerenderPanel() {
        if (!panelView) return;
        const subject = panelView.type === 'building' ? building(panelView.id) : person(panelView.id);
        if (!subject) return closePanel();
        panelView.type === 'building' ? renderBuildingPanel(subject) : renderPersonPanel(subject);
    }

    function panelHeader(eyebrow, colour, title, sub) {
        return `
            <div class="flex items-start justify-between gap-3 border-b border-white/10 p-4">
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em]" style="color:${colour}">${eyebrow}</p>
                    <h3 class="mt-0.5 text-lg font-bold leading-tight text-white">${title}</h3>
                    ${sub ? `<p class="text-xs text-brand-100/50">${sub}</p>` : ''}
                </div>
                <button type="button" data-panel-close class="shrink-0 rounded-md p-1.5 text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">${icon('x')}</button>
            </div>`;
    }

    const crewChip = (c, shoot) => `
        <span class="inline-flex items-center gap-1 rounded-full bg-white/10 py-0.5 pl-2 pr-0.5 text-xs text-white">
            ${esc(firstName(c.name))}${c.role ? `<span class="text-brand-100/50">· ${esc(c.role)}</span>` : ''}
            <button type="button" data-remove="${shoot.id}:${c.id}" class="rounded-full p-0.5 text-brand-100/50 hover:bg-red-500/30 hover:text-white" aria-label="Remove ${esc(c.name)} from ${esc(shoot.title)}">${icon('x', 'h-3 w-3')}</button>
        </span>`;

    function wireRemovals(shoots) {
        panel.querySelectorAll('[data-remove]').forEach((button) => button.addEventListener('click', () => {
            const [shootId, personId] = button.dataset.remove.split(':').map(Number);
            const shoot = shoots.find((s) => s.id === shootId);
            if (shoot) actions.openRemove({ personId, shoot });
        }));
    }

    function renderBuildingPanel(b) {
        const style = STATE[b.state];
        const list = actions.actionsFor(b);
        const row = (k, v) => `<div class="flex justify-between gap-3 py-1.5"><dt class="text-brand-100/60">${k}</dt><dd class="text-right font-medium tabular-nums text-white">${v}</dd></div>`;
        const content = b.content
            ? (b.content.days_left != null ? `${b.content.days_left} days (${b.content.remaining} reels)` : `${b.content.remaining} reels`)
            : '—';

        panel.innerHTML = `
            ${panelHeader(esc(style.label), hex(style.color), esc(b.name), `${b.type === 'occasion' ? 'Occasion client' : 'Regular client'}${b.active ? '' : ' · inactive'}${b.address ? ` · ${esc(b.address)}` : ''}`)}
            <div class="grid grid-cols-2 gap-1.5 p-3">
                ${list.map((a, i) => `
                    <button type="button" data-act="${i}" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-semibold ${a.primary ? 'col-span-2 bg-brand-400 text-brand-900 hover:bg-brand-300' : 'bg-white/5 text-white hover:bg-white/10'}">
                        <span class="${a.wa && !a.primary ? 'text-emerald-300' : ''}">${icon(a.icon)}</span><span class="truncate">${esc(a.label)}</span>
                    </button>`).join('')}
            </div>
            <dl class="divide-y divide-white/5 px-4 pb-2 text-sm">
                ${row('Hours this month', b.hours.toFixed(1))}
                ${row('Invoiced this month', b.invoiced_month ? money(b.invoiced_month) : '—')}
                ${row('Per hour of work', b.per_hour != null ? money(b.per_hour) : '—')}
                ${row('Owed', b.owed ? money(b.owed) : '—')}
                ${b.overdue_days ? row('Oldest overdue', `<span class="text-red-300">${b.overdue_days} days</span>`) : ''}
                ${row('Content left', content)}
                ${row('Scripts waiting on them', b.scripts_waiting || '—')}
                ${row('Proposals waiting on them', b.proposals_waiting || '—')}
            </dl>
            ${b.upcoming_shoots.length ? `
                <div class="px-4 pb-4">
                    <p class="mb-1.5 text-xs font-semibold text-white">Shoots, next two weeks</p>
                    <ul class="space-y-2">
                        ${b.upcoming_shoots.map((s) => `
                            <li class="rounded-lg bg-white/5 p-2.5">
                                <p class="text-sm font-semibold text-white">${esc(s.title)}</p>
                                <p class="text-xs text-brand-100/60">${esc(s.when)}</p>
                                <div class="mt-1.5 flex flex-wrap gap-1">
                                    ${s.crew.length ? s.crew.map((c) => crewChip(c, s)).join('') : '<span class="text-xs text-red-300">Nobody crewed</span>'}
                                </div>
                            </li>`).join('')}
                    </ul>
                </div>` : ''}
            <div class="mt-auto border-t border-white/10 p-3">
                <a href="${esc(b.links.client)}" class="flex items-center justify-center gap-1.5 rounded-lg bg-white/10 py-2 text-sm font-semibold text-white hover:bg-white/15">${icon('out')}Open ${esc(b.name)}</a>
            </div>`;

        panel.querySelectorAll('[data-act]').forEach((button) => button.addEventListener('click', () => list[Number(button.dataset.act)].run()));
        panel.querySelector('[data-panel-close]').addEventListener('click', closePanel);
        wireRemovals(b.upcoming_shoots);
        panel.classList.replace('hidden', 'flex');
    }

    function renderPersonPanel(p) {
        const status = statusOf(p.id);
        const ph = PHASE[status.phase];
        const where = status.shoot ? building(status.shoot.client_id)?.name ?? status.shoot.title : null;
        const theirs = snapshot.buildings.flatMap((b) => b.upcoming_shoots
            .filter((s) => s.crew.some((c) => c.id === p.id))
            .map((s) => ({ ...s, client: b.name })));

        panel.innerHTML = `
            ${panelHeader(esc(ph.label) + (where ? ` · ${esc(where)}` : ''), hex(ph.color), esc(p.name), `${p.hours_today ? `${p.hours_today}h logged today` : 'Nothing logged today yet'}`)}
            <div class="grid grid-cols-2 gap-1.5 p-3">
                <button type="button" data-person-act="assign" class="flex items-center gap-2 rounded-lg bg-brand-400 px-2.5 py-2 text-xs font-semibold text-brand-900 hover:bg-brand-300">${icon('users')}Add to a shoot</button>
                <button type="button" data-person-act="todo" class="flex items-center gap-2 rounded-lg bg-white/5 px-2.5 py-2 text-xs font-semibold text-white hover:bg-white/10">${icon('check')}Add a to-do</button>
            </div>
            <div class="px-4 pb-3">
                <p class="mb-1.5 text-xs font-semibold text-white">Shoots, next two weeks</p>
                ${theirs.length ? `<ul class="space-y-1.5">${theirs.map((s) => `
                    <li class="flex items-center gap-2 rounded-lg bg-white/5 px-2.5 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-white">${esc(s.title)}</p>
                            <p class="truncate text-xs text-brand-100/60">${esc(s.client)} · ${esc(s.when)}</p>
                        </div>
                        <button type="button" data-remove="${s.id}:${p.id}" class="shrink-0 rounded-md p-1.5 text-brand-100/50 hover:bg-red-500/25 hover:text-white" aria-label="Take ${esc(p.name)} off ${esc(s.title)}">${icon('minus')}</button>
                    </li>`).join('')}</ul>`
                    : '<p class="rounded-lg border border-dashed border-white/15 px-3 py-3 text-center text-xs text-brand-100/50">Not on any shoot. Drag them onto a pin to add them.</p>'}
            </div>
            <div class="px-4 pb-4">
                <p class="mb-1.5 text-xs font-semibold text-white">Open to-dos <span class="font-normal text-brand-100/50">${p.open_todos}</span></p>
                ${p.todos.length ? `<ul class="space-y-1 text-sm">${p.todos.map((todo) => `
                    <li class="flex items-baseline justify-between gap-3">
                        <span class="truncate text-brand-100/85">${esc(todo.title)}</span>
                        <span class="shrink-0 text-xs ${todo.overdue ? 'font-semibold text-red-300' : 'text-brand-100/50'}">${esc(todo.due ?? '')}</span>
                    </li>`).join('')}</ul>${p.open_todos > p.todos.length ? `<p class="mt-1 text-xs text-brand-100/45">and ${p.open_todos - p.todos.length} more</p>` : ''}`
                    : '<p class="text-xs text-brand-100/50">None open.</p>'}
            </div>`;

        panel.querySelector('[data-person-act="assign"]').addEventListener('click', () => actions.openAssign({ personId: p.id }));
        panel.querySelector('[data-person-act="todo"]').addEventListener('click', () => actions.openTodo({ personId: p.id }));
        panel.querySelector('[data-panel-close]').addEventListener('click', closePanel);
        wireRemovals(theirs);
        panel.classList.replace('hidden', 'flex');
    }

    /* ——— Team bench ——— */
    function renderRoster() {
        root.querySelector('[data-roster]').innerHTML = snapshot.team.map((p) => {
            const ph = PHASE[statusOf(p.id).phase];
            return `
                <li class="shrink-0">
                    <button type="button" data-person="${p.id}" style="touch-action: pan-x"
                            title="${esc(p.name)} — ${esc(ph.label)}${p.hours_today ? `, ${p.hours_today}h today` : ''}. Drag onto a pin."
                            class="flex cursor-grab select-none items-center gap-2 rounded-full bg-white/5 py-1 pl-1 pr-3 ring-1 ${ph.ring} hover:bg-white/10 active:cursor-grabbing">
                        <span class="relative flex h-7 w-7 items-center justify-center rounded-full bg-brand-800 text-[11px] font-bold text-white">
                            ${esc(initials(p.name))}
                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-brand-900 ${ph.dot}"></span>
                        </span>
                        <span class="text-xs font-semibold text-white">${esc(firstName(p.name))}</span>
                        ${p.hours_today ? `<span class="text-[10px] tabular-nums text-brand-100/50">${p.hours_today}h</span>` : ''}
                    </button>
                </li>`;
        }).join('');
    }

    root.querySelector('[data-roster]').addEventListener('pointerdown', (event) => {
        const chip = event.target.closest('[data-person]');
        if (!chip || event.button > 0) return;
        armDrag(Number(chip.dataset.person), null, event);
    });

    /* ——— Clients placed by guess ———
     * Someone with no location still gets a building -- in the district their
     * address names, or the studio's -- but it is a guess, so it is listed
     * here with the way to fix it: look the address up, or add one.
     */
    function renderUnpinned() {
        const missing = snapshot.buildings.filter((b) => !pinned(b)).sort((a, b) => a.name.localeCompare(b.name));
        const slot = root.querySelector('[data-unpinned]');
        if (!missing.length) {
            slot.innerHTML = '';
            return;
        }
        const districtName = (key) => snapshot.districts.find((d) => d.key === key)?.name ?? '';
        const open = slot.querySelector('[data-unpinned-list]') && !slot.querySelector('[data-unpinned-list]').classList.contains('hidden');
        slot.innerHTML = `
            <button type="button" data-unpinned-toggle aria-expanded="${open}" class="flex items-center gap-1.5 rounded-lg bg-amber-500/90 px-2.5 py-1.5 text-xs font-semibold text-brand-900 shadow-lg hover:bg-amber-400">
                ${missing.length} placed by guess <span aria-hidden="true">▾</span>
            </button>
            <div data-unpinned-list class="${open ? '' : 'hidden'} mt-2 w-72 space-y-1 rounded-xl bg-brand-900/95 p-2 shadow-2xl ring-1 ring-white/15 backdrop-blur">
                <p class="px-1 pb-1 text-[11px] text-brand-100/55">No location found for these, so they stand where their address points, or by the studio.</p>
                ${missing.map((b) => `
                    <div class="rounded-lg bg-white/5 p-2">
                        <p class="truncate text-sm font-semibold text-white">${esc(b.name)} <span class="font-normal text-brand-100/50">· in ${esc(districtName(b.district))}</span></p>
                        <p class="truncate text-[11px] text-brand-100/50">${b.address ? esc(b.address) : 'No address on file'}</p>
                        <div class="mt-1.5 flex gap-1.5">
                            ${b.address
                                ? `<button type="button" data-locate="${b.id}" class="rounded-md bg-white/10 px-2 py-1 text-[11px] font-semibold text-white hover:bg-white/15">Look up address</button>`
                                : `<a href="${esc(b.links.client)}" class="rounded-md bg-brand-400 px-2 py-1 text-[11px] font-semibold text-brand-900 hover:bg-brand-300">Add an address</a>`}
                        </div>
                    </div>`).join('')}
            </div>`;
        slot.querySelector('[data-unpinned-toggle]').addEventListener('click', (event) => {
            const list = slot.querySelector('[data-unpinned-list]');
            list.classList.toggle('hidden');
            event.currentTarget.setAttribute('aria-expanded', String(!list.classList.contains('hidden')));
        });
        slot.querySelectorAll('[data-locate]').forEach((b) => b.addEventListener('click', async () => {
            b.disabled = true;
            b.textContent = 'Looking…';
            try {
                const result = await actions.post(actions.urls.locate, { client_id: Number(b.dataset.locate) });
                actions.toast(result.message);
                refresh();
            } catch (e) {
                actions.toast(e.message, 'warn');
                b.disabled = false;
                b.textContent = 'Look up address';
            }
        }));
    }

    /* ——— Dragging people ——— */
    let pending = null; // pressed, not yet moved far enough to be a drag
    let drag = null;

    function armDrag(personId, fromShoot, event) {
        pending = { personId, fromShoot, x: event.clientX, y: event.clientY };
    }

    window.addEventListener('pointermove', (event) => {
        if (pending && mapApi && Math.hypot(event.clientX - pending.x, event.clientY - pending.y) > DRAG_START_PX) {
            startDrag(pending);
            pending = null;
        }
        if (drag) moveDrag(event);
    });
    window.addEventListener('pointerup', () => {
        if (drag) {
            drop();
        } else if (pending) {
            showPerson(pending.personId); // a press that never moved is a click
        }
        pending = null;
        mapApi?.dragEnded();
    });
    window.addEventListener('pointercancel', () => {
        pending = null;
        if (drag) cancelDrag();
        mapApi?.dragEnded();
    });

    function startDrag({ personId, fromShoot }) {
        const p = person(personId);
        if (!p) return;
        closeCard();
        const ghost = document.createElement('div');
        ghost.className = 'pointer-events-none fixed left-0 top-0 z-[70] flex items-center gap-2 rounded-2xl bg-brand-900/95 py-1.5 pl-1.5 pr-3 shadow-2xl ring-2 ring-brand-400/60 backdrop-blur';
        ghost.innerHTML = `
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-400 text-xs font-bold text-brand-900">${esc(initials(p.name))}</span>
            <span><span class="block text-xs font-semibold text-white">${esc(p.name)}</span><span class="block text-[11px] text-brand-100/60" data-ghost-hint>Drop on a client's pin</span></span>`;
        document.body.appendChild(ghost);
        document.body.classList.add('select-none');
        drag = { personId, fromShoot, ghost, target: null };
        mapApi.markDragging(personId, true);
    }

    function moveDrag(event) {
        drag.ghost.style.transform = `translate(${event.clientX + 14}px, ${event.clientY + 14}px)`;
        drag.target = mapApi.dropTargetAt(event.clientX, event.clientY);
        const intent = dropIntent(drag.target);
        drag.ghost.querySelector('[data-ghost-hint]').textContent = intent.hint;
        drag.ghost.classList.toggle('ring-emerald-400', intent.tone === 'green');
        drag.ghost.classList.toggle('ring-amber-400', intent.tone === 'amber');
        drag.ghost.classList.toggle('ring-red-400', intent.tone === 'red');
        mapApi.highlightDrop(drag.target, intent.tone);
    }

    function dropIntent(target) {
        if (!target) return { hint: "Drop on a client's pin", tone: null };
        if (target.kind === 'hq') {
            return drag.fromShoot
                ? { hint: `Take off ${drag.fromShoot.title}`, tone: 'red', run: () => actions.openRemove({ personId: drag.personId, shoot: { id: drag.fromShoot.id, title: drag.fromShoot.title, when: clockOf(drag.fromShoot.starts_at) } }) }
                : { hint: 'Already at the studio', tone: null };
        }
        const b = building(target.id);
        if (!b) return { hint: "Drop on a client's pin", tone: null };
        const open = b.upcoming_shoots.filter((s) => !s.crew.some((c) => c.id === drag.personId));
        if (open.length) {
            return { hint: open.length === 1 ? `Add to ${open[0].title}` : `Add to a shoot at ${b.name}`, tone: 'green', run: () => actions.openAssign({ personId: drag.personId, clientId: b.id }) };
        }
        if (b.upcoming_shoots.length) return { hint: `Already on ${b.name}'s shoots`, tone: null };

        return { hint: `Book a shoot at ${b.name}`, tone: 'amber', run: () => actions.openBookShoot({ clientId: b.id, personId: drag.personId }) };
    }

    function drop() {
        const intent = dropIntent(drag.target);
        cancelDrag();
        intent.run?.();
    }

    function cancelDrag() {
        if (!drag) return;
        drag.ghost.remove();
        document.body.classList.remove('select-none');
        mapApi?.markDragging(drag.personId, false);
        mapApi?.highlightDrop(null);
        drag = null;
    }

    /* ——— Lens switcher ——— */
    function setLens(next) {
        lens = next;
        root.querySelectorAll('[data-lens]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.lens === next)));
        renderLegend();
        mapApi?.redraw();
        if (selectedId != null && selectedId !== 'hq') renderCard();
    }
    function renderLegend() {
        const L_ = LENSES[lens];
        root.querySelector('[data-legend]').innerHTML = L_.legend(snapshot)
            .map(([c, text]) => `<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:${hex(c)}"></span>${esc(text)}</div>`)
            .join('') + `<div class="pt-1 text-brand-100/50">${esc(L_.note)}</div>`;
    }
    root.querySelectorAll('[data-lens]').forEach((button) => button.addEventListener('click', () => setLens(button.dataset.lens)));

    /* ——— Replay ——— */
    function syncSlider() {
        slider.min = snapshot.day.from;
        slider.max = now();
        if (live) slider.value = slider.max;
    }
    function renderTicker() {
        const at = live ? now() : t;
        const events = snapshot.day.events.filter((e) => e.at <= at).slice(-6).reverse();
        renderTickerIn(root, events, live ? fresh : new Set());
    }
    function stopPlaying() {
        playing = false;
        playButton.textContent = '▶ Replay';
    }
    function goLive() {
        live = true;
        stopPlaying();
        liveButton.classList.add('hidden');
        clock.textContent = 'Live';
        t = now();
        syncSlider();
        renderTicker();
        renderRoster();
        mapApi?.tick();
    }
    function scrubTo(ms) {
        live = false;
        t = Math.min(ms, now());
        slider.value = t;
        liveButton.classList.remove('hidden');
        clock.textContent = clockOf(t);
        renderTicker();
        mapApi?.tick();
    }
    slider.addEventListener('input', () => {
        stopPlaying();
        scrubTo(Number(slider.value));
    });
    liveButton.addEventListener('click', goLive);

    // The only frame loop on the page, and only while a replay plays.
    let last = 0;
    function playFrame(stamp) {
        if (!playing) return;
        const dt = last ? (stamp - last) / 1000 : 0;
        last = stamp;
        const span = Math.max(now() - snapshot.day.from, 30 * MIN);
        const next = t + (span / 40) * dt; // the whole day in about forty seconds
        if (next >= now()) return goLive();
        t = next;
        slider.value = t;
        const shown = clockOf(t);
        if (clock.textContent !== shown) {
            clock.textContent = shown;
            renderTicker();
        }
        mapApi?.tick();
        requestAnimationFrame(playFrame);
    }
    function togglePlay() {
        if (playing) return stopPlaying();
        if (live || t >= now() - MIN) scrubTo(snapshot.day.from);
        playing = true;
        last = 0;
        playButton.textContent = '❚❚ Pause';
        requestAnimationFrame(playFrame);
    }
    playButton.addEventListener('click', togglePlay);

    /* ——— Search ——— */
    const searchInput = root.querySelector('[data-search-input]');
    const results = root.querySelector('[data-search-results]');
    let hits = [];
    let active = 0;

    function runSearch() {
        const q = searchInput.value.trim().toLowerCase();
        if (!q) return closeSearch();
        hits = [
            ...snapshot.buildings.filter((b) => b.name.toLowerCase().includes(q)).map((b) => ({ kind: 'client', id: b.id, name: b.name, sub: STATE[b.state].label, colour: STATE[b.state].color })),
            ...snapshot.team.filter((p) => p.name.toLowerCase().includes(q)).map((p) => ({ kind: 'person', id: p.id, name: p.name, sub: PHASE[statusOf(p.id).phase].label, colour: PHASE[statusOf(p.id).phase].color })),
        ].slice(0, 8);
        active = 0;
        renderResults();
    }
    function renderResults() {
        results.innerHTML = hits.length
            ? hits.map((h, i) => `
                <li role="option" id="studio-map-hit-${i}" aria-selected="${i === active}" data-hit="${i}"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 ${i === active ? 'bg-white/10' : 'hover:bg-white/5'}">
                    ${h.kind === 'person'
                        ? `<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-800 text-[10px] font-bold text-white">${esc(initials(h.name))}</span>`
                        : `<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/5"><span class="h-2.5 w-2.5 rounded-full" style="background:${hex(h.colour)}"></span></span>`}
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-white">${esc(h.name)}</span><span class="block truncate text-xs text-brand-100/55">${h.kind === 'person' ? 'Team' : 'Client'} · ${esc(h.sub)}</span></span>
                </li>`).join('')
            : '<li class="px-3 py-2 text-sm text-brand-100/50">Nothing by that name.</li>';
        results.classList.remove('hidden');
        searchInput.setAttribute('aria-expanded', 'true');
        searchInput.setAttribute('aria-activedescendant', hits.length ? `studio-map-hit-${active}` : '');
    }
    function closeSearch() {
        results.classList.add('hidden');
        searchInput.setAttribute('aria-expanded', 'false');
        hits = [];
    }
    function choose(hit) {
        if (!hit) return;
        searchInput.value = '';
        closeSearch();
        searchInput.blur();
        hit.kind === 'client' ? select(hit.id) : showPerson(hit.id);
    }
    searchInput.addEventListener('input', runSearch);
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!hits.length) return;
            active = (active + (event.key === 'ArrowDown' ? 1 : hits.length - 1)) % hits.length;
            renderResults();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(hits[active]);
        } else if (event.key === 'Escape') {
            searchInput.value = '';
            closeSearch();
            searchInput.blur();
        }
    });
    results.addEventListener('pointerdown', (event) => {
        const li = event.target.closest('[data-hit]');
        if (!li) return;
        event.preventDefault(); // keep focus until chosen
        choose(hits[Number(li.dataset.hit)]);
    });
    searchInput.addEventListener('blur', () => setTimeout(closeSearch, 120));

    /* ——— Keys ——— */
    const help = root.querySelector('[data-help]');
    const helpToggle = root.querySelector('[data-help-toggle]');
    const toggleHelp = (open = help.classList.contains('hidden')) => {
        help.classList.toggle('hidden', !open);
        helpToggle.setAttribute('aria-expanded', String(open));
    };
    helpToggle.addEventListener('click', () => toggleHelp());
    document.addEventListener('click', (event) => {
        if (!help.contains(event.target) && !helpToggle.contains(event.target)) toggleHelp(false);
    });

    document.addEventListener('keydown', (event) => {
        if (root.querySelector('[data-action-dialog]').open || event.metaKey || event.ctrlKey || event.altKey) return;
        if (typing(event.target)) return;

        if (event.key === '/') {
            event.preventDefault();
            searchInput.focus();
        } else if (event.key === '?') {
            toggleHelp();
        } else if (/^[1-5]$/.test(event.key)) {
            setLens(LENS_KEYS[Number(event.key) - 1]);
        } else if (event.key === ' ' && !event.target.closest?.('button, a')) {
            event.preventDefault();
            togglePlay();
        } else if (event.key === 'Escape') {
            if (drag) return cancelDrag();

            if (!help.classList.contains('hidden')) return toggleHelp(false);
            if (selectedId != null) return closeCard();
            closePanel();
        }
    });

    /* ——— Refresh ——— */
    async function refresh() {
        try {
            const response = await fetch(root.dataset.endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            snapshot = await response.json();
        } catch {
            return; // Offline for a moment -- keep showing the last one.
        }
        skew = snapshot.day.now - Date.now();

        // Anything not seen before is news: it glows in the feed and its pin
        // pulses until the next refresh.
        fresh = new Set(snapshot.day.events.filter((e) => !seen.has(e.id)).map((e) => e.id));
        fresh.forEach((id) => seen.add(id));

        mapApi?.redraw();
        renderListsIn(root, snapshot);
        renderLegend();
        renderRoster();
        renderUnpinned();
        syncSlider();
        renderTicker();
        if (selectedId === 'hq') renderHqCard();
        else if (selectedId != null) renderCard();
        rerenderPanel();
    }
    setInterval(() => {
        if (!document.hidden) refresh();
    }, REFRESH_MS);

    // List and ticker clicks select a client, map or no map.
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-select]');
        if (!button || !button.dataset.select) return;
        select(Number(button.dataset.select));
        if (mapApi) box.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'center' });
    });

    renderListsIn(root, snapshot);
    renderLegend();
    renderRoster();
    syncSlider();
    renderTicker();

    try {
        mapApi = startCity();
    } catch (e) {
        console.error(e);
        root.querySelector('[data-map-fallback]').classList.replace('hidden', 'flex');
    }
    renderUnpinned();
    /* ——— The city ——————————————————————————————————————————————————— */

    function startCity() {
        const city = createCity({
            host,
            getSnapshot: () => snapshot,
            getLens: () => lens,
            lenses: LENSES,
            getTime: () => (live ? now() : t),
            getSelected: () => selectedId,
            getFresh: () => (live ? fresh : new Set()),
            whereAt,
            phaseColor: (phase) => hex(PHASE[phase].color),
            initials,
            firstName,
            logoUrl: root.dataset.logo,
            onPick: (id) => select(id),
            onBackground: () => closeCard(),
            onPersonDown: (personId, shoot, event) => armDrag(personId, shoot, event),
            onView: () => positionCard(),
        });

        // Day and night: on the clock by default, or pinned either way.
        let sky = store.get('studio-map.sky') ?? 'auto';
        const setSky = (mode) => {
            sky = mode;
            store.set('studio-map.sky', mode);
            city.setSky(mode);
            root.querySelectorAll('[data-sky]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.sky === mode)));
        };
        root.querySelectorAll('[data-sky]').forEach((b) => b.addEventListener('click', () => setSky(b.dataset.sky)));
        setSky(sky);

        root.querySelectorAll('[data-map-zoom]').forEach((b) => b.addEventListener('click', () => city.zoomBy(b.dataset.mapZoom === 'in' ? 1.35 : 1 / 1.35)));
        root.querySelector('[data-map-fit]').addEventListener('click', () => city.fit());
        const fullButton = root.querySelector('[data-map-fullscreen]');
        if (!box.requestFullscreen) fullButton.classList.add('hidden');
        fullButton.addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : box.requestFullscreen()));

        return city;
    }
}

/* ——— Plain DOM: ticker and lists — work without the map ——————————————— */

const VERB = {
    departed: (e) => `${esc(firstName(e.who ?? 'Crew'))} is on the way`,
    reached: (e) => `${esc(firstName(e.who ?? 'Crew'))} reached`,
    rolling: (e) => `${esc(firstName(e.who ?? 'Crew'))} started rolling`,
    video: (e) => `${esc(firstName(e.who ?? 'Crew'))} logged “${esc(e.title)}”`,
    wrapped: () => 'Wrapped',
};
const DOT = { departed: C.sky, reached: C.amber, rolling: C.red, video: C.green, wrapped: C.sky };

function renderTickerIn(root, events, fresh) {
    root.querySelector('[data-ticker]').innerHTML = events.length
        ? events.map((e) => `
            <li class="shrink-0">
                <button type="button" data-select="${e.client_id ?? ''}"
                        class="pointer-events-auto flex max-w-[240px] items-center gap-2 rounded-lg bg-brand-900/85 px-2 py-1.5 text-left text-xs text-white shadow-lg ring-1 backdrop-blur hover:bg-brand-900 ${fresh.has(e.id) ? 'animate-pulse ring-emerald-300' : 'ring-white/10'}">
                    ${e.photo ? `<img src="${esc(e.photo)}" alt="" class="h-8 w-8 shrink-0 rounded object-cover">` : `<span class="h-2 w-2 shrink-0 rounded-full" style="background:${hex(DOT[e.type])}"></span>`}
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">${VERB[e.type](e)}</span>
                        <span class="block truncate text-white/60">${esc(e.client ?? e.shoot)} · ${esc(e.time)}</span>
                    </span>
                </button>
            </li>`).join('')
        : '<li class="rounded-lg bg-brand-900/70 px-3 py-1.5 text-xs text-white/60">Nothing from shoots yet today.</li>';
}

function renderListsIn(root, snapshot) {
    const t = snapshot.totals;
    // On the map on a wide screen, under it otherwise -- both kept current.
    const set = (key, value) => root.querySelectorAll(`[data-stat="${key}"]`).forEach((el) => { el.textContent = value; });
    set('owed', money(t.owed));
    set('overdue', money(t.overdue));
    set('shoots_today', t.shoots_today);
    set('rolling', t.rolling);
    set('waiting_on_clients', t.waiting_on_clients);
    set('hours_month', Number(t.hours_month).toFixed(1));
    root.querySelectorAll('[data-generated-at]').forEach((el) => { el.textContent = snapshot.generated_at; });

    root.querySelector('[data-attention-list]').innerHTML = snapshot.attention.length
        ? snapshot.attention.map((a) => `
            <li class="flex gap-2.5">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full ${a.severity === 'urgent' ? 'bg-red-400' : 'bg-amber-400'}"></span>
                <div class="min-w-0">
                    ${a.url ? `<a href="${esc(a.url)}" class="font-medium text-white hover:underline">${esc(a.title)}</a>` : `<span class="font-medium text-white">${esc(a.title)}</span>`}
                    <p class="text-xs text-brand-100/60">${esc(a.detail)}</p>
                </div>
            </li>`).join('')
        : '<li class="text-brand-100/60">Nothing needs you right now.</li>';

    root.querySelector('[data-building-list]').innerHTML = [...snapshot.buildings].sort(byStateThenName).map((b) => `
        <li>
            <button type="button" data-select="${b.id}" class="flex w-full items-center gap-3 rounded-md px-1.5 py-2 text-left hover:bg-white/5">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:${hex(STATE[b.state].color)}"></span>
                <span class="min-w-0 flex-1 truncate text-white">${esc(b.name)}</span>
                <span class="shrink-0 text-xs text-brand-100/60">${esc(STATE[b.state].label)}${b.owed ? ' · ' + money(b.owed) : ''}</span>
            </button>
        </li>`).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-studio-map]');
    if (root) boot(root);
});
