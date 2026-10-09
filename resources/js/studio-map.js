/*
 * The Studio Map (studio-map/index.blade.php): the studio's clients on a
 * real map -- OpenStreetMap streets through Leaflet -- with the Chakra pin
 * where the studio is.
 *
 *  - Lenses. Five questions of the same pins -- Status, Money (rupees per
 *    hour of work), Hours, Content (days of reels left) and Crew (where
 *    everybody is). A lens decides each pin's colour, size, badge, legend.
 *  - The live feed. Today's shoot events run along the bottom, and a video's
 *    photo goes up over its client's pin for a few hours.
 *  - Replay. The slider is the time of day; crew, routes, photos and the
 *    feed are all worked out from the timeline for whatever time it says.
 *  - Acting. Click a pin for its command card -- its actions, most needed
 *    first. Drag a person from the team bench (or off the map) onto a pin to
 *    put them on a shoot, or onto the Chakra pin to take them off one. The
 *    actions live in studio-map-actions.js; this file decides when to offer
 *    them.
 *  - Pins. Addresses are looked up nightly (clients:geocode); anything not
 *    found, or found in the wrong place, is placed or moved by hand here.
 *
 * Light by design: markers are plain HTML, motion is CSS, and nothing runs a
 * frame loop except a replay that is playing. The snapshot is re-fetched
 * every 30 seconds, and straight after any action.
 */
// The ES-module build: the package's main entry is UMD, whose named exports
// a bundler cannot see.
import * as L from 'leaflet/dist/leaflet-src.esm.js';
import 'leaflet/dist/leaflet.css';
import '../css/studio-map.css';
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
const LIVE_TICK_MS = 15000;
const BILLBOARD_FOR_MS = 3 * 60 * 60 * 1000;
const MIN = 60 * 1000;
const DRAG_START_PX = 6;
const FAR_ZOOM = 11; // below this, only pins that need you keep their names
const TRICHY = [10.7905, 78.7047]; // where the map opens before anything is pinned

// OpenStreetMap's own tiles: no key, just attribution and light use -- an
// admin-only page is exactly that (tile.openstreetmap.org usage policy).
// "Night" is the same tiles darkened in CSS (.sm-map.is-night), so it costs
// no second download. CARTO's basemaps, tried first, now need an API key.
const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

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

const sizeFor = (value, scale) => Math.round(24 + Math.min(24, Math.sqrt(Math.max(0, value)) * scale));

const LENSES = {
    status: {
        legend: () => ORDER.map((k) => [STATE[k].color, STATE[k].label]),
        colour: (b) => STATE[b.state].color,
        glow: (b) => STATE[b.state].glow,
        size: (b) => sizeFor(b.hours, 3),
        badge: (b) => {
            if (b.overdue_days > 0) return `₹ ${b.overdue_days}d overdue`;
            if (b.state === 'rolling') return '● REC';
            if (b.state === 'shoot') return 'Shoot today';
            const waiting = b.scripts_waiting + b.proposals_waiting;
            return waiting ? `${waiting} waiting` : null;
        },
        loud: (b) => b.state !== 'calm',
        note: 'Size: hours this month',
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
        size: (b) => sizeFor(b.invoiced_month / 1000, 2.6),
        badge: (b) => (b.per_hour != null ? `${money(b.per_hour)}/hr` : null),
        loud: (b) => b.per_hour != null,
        note: 'Size: invoiced this month',
    },
    hours: {
        legend: () => [[0x2f6e84, 'Under 10 hours'], [0x4fa9c4, '10–30 hours'], [0x8acce0, '30+ hours'], [C.slate, 'None this month']],
        colour: (b) => (b.hours <= 0 ? C.slate : b.hours < 10 ? 0x2f6e84 : b.hours < 30 ? 0x4fa9c4 : 0x8acce0),
        glow: () => false,
        size: (b) => sizeFor(b.hours, 3),
        badge: (b) => (b.hours > 0 ? `${b.hours.toFixed(1)}h` : null),
        loud: (b) => b.hours > 0,
        note: 'Size: hours this month',
    },
    content: {
        legend: () => [[C.red, 'Runs out within a week, no shoot booked'], [C.amber, 'Runs out within 3 weeks'], [C.green, 'Enough content'], [C.slate, 'Not tracked']],
        colour: (b) => ({ critical: C.red, warning: C.amber, ok: C.green })[b.content?.status] ?? C.slate,
        glow: (b) => b.content?.status === 'critical',
        size: () => 34,
        badge: (b) => {
            if (!b.content) return null;
            if (b.content.days_left != null) return b.content.days_left <= 0 ? 'Out of content' : `${b.content.days_left}d left`;
            return `${b.content.remaining} reels`;
        },
        gauge: (b) => (b.content?.days_left != null ? Math.max(0, Math.min(1, b.content.days_left / 42)) : null),
        loud: (b) => ['critical', 'warning'].includes(b.content?.status),
        note: 'Ring: six weeks of reels = full',
    },
    crew: {
        legend: () => [[C.green, 'On site'], [C.amber, 'On the way'], [C.brand, 'At the studio']],
        colour: () => 0x8796a0,
        glow: () => false,
        size: () => 22,
        badge: () => null,
        loud: () => false,
        note: 'Drag anyone onto a pin, or onto Chakra',
    },
};
const LENS_KEYS = Object.keys(LENSES);

/* ——— Where a crew member is at time t ——————————————————————————————————
 * home: at the studio. walking: on the way (progress 0..1, or null when the
 * arrival time is not known yet). site: on location.
 */
function whereAt(shoot, t) {
    if (shoot.finished_at && t >= shoot.finished_at) return { phase: 'home' };
    const arrivals = [shoot.reached_at, shoot.started_at].filter(Boolean);
    const at = arrivals.length ? Math.min(...arrivals) : null;
    if (at && t >= at) return { phase: 'site' };

    const depart = at ? at - 40 * MIN : (shoot.starts_at ?? Infinity) - 60 * MIN;
    if (t < depart) return { phase: 'home' };

    return { phase: 'walking', progress: at ? Math.min(1, (t - depart) / (at - depart)) : null };
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
    const banner = root.querySelector('[data-place-banner]');

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
        if (!mapApi || !pinned(b)) return openDetails(id); // nowhere to pin a card to
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
                <button type="button" data-card-move class="flex flex-1 items-center justify-center gap-1.5 border-l border-white/10 py-2.5 text-brand-100/70 hover:text-white">Move pin</button>
                <a href="${esc(b.links.client)}" class="flex flex-1 items-center justify-center gap-1.5 border-l border-white/10 py-2.5 text-brand-100/70 hover:text-white">${icon('out')}Open</a>
            </div>`);

        card.querySelectorAll('[data-act]').forEach((button) => button.addEventListener('click', () => list[Number(button.dataset.act)].run()));
        card.querySelector('[data-card-details]').addEventListener('click', () => openDetails(b.id));
        card.querySelector('[data-card-move]').addEventListener('click', () => mapApi.startMove({ target: 'client', id: b.id }));
    }

    function renderHqCard() {
        const set = snapshot.studio.set;
        cardShell(`
            <div class="flex items-start gap-3 p-3">
                <div class="min-w-0 flex-1">
                    <p class="font-bold leading-tight text-white">Chakra studio</p>
                    <p class="mt-0.5 text-xs text-brand-100/60">${set ? 'Crew set out from here. Drop someone here to take them off a shoot.' : 'Not placed yet — move this pin to where the studio is.'}</p>
                </div>
                <button type="button" data-card-close class="-mr-1 -mt-1 rounded-lg p-1.5 text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">${icon('x')}</button>
            </div>
            <div class="px-1.5 pb-1.5">
                <button type="button" data-card-move class="flex w-full items-center justify-center gap-2 rounded-xl ${set ? 'bg-white/10 text-white hover:bg-white/15' : 'bg-brand-400 text-brand-900 hover:bg-brand-300'} px-3 py-2 text-sm font-semibold">${set ? 'Move the studio pin' : 'Place the studio'}</button>
            </div>`);
        card.querySelector('[data-card-move]').addEventListener('click', () => mapApi.startMove({ target: 'studio' }));
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

    /* ——— Pins not on the map yet ——— */
    function renderUnpinned() {
        const missing = snapshot.buildings.filter((b) => !pinned(b)).sort((a, b) => a.name.localeCompare(b.name));
        const slot = root.querySelector('[data-unpinned]');
        if (!missing.length) {
            slot.innerHTML = '';
            return;
        }
        const open = slot.querySelector('[data-unpinned-list]') && !slot.querySelector('[data-unpinned-list]').classList.contains('hidden');
        slot.innerHTML = `
            <button type="button" data-unpinned-toggle aria-expanded="${open}" class="flex items-center gap-1.5 rounded-lg bg-amber-500/90 px-2.5 py-1.5 text-xs font-semibold text-brand-900 shadow-lg hover:bg-amber-400">
                ${missing.length} not on the map <span aria-hidden="true">▾</span>
            </button>
            <div data-unpinned-list class="${open ? '' : 'hidden'} mt-2 w-72 space-y-1 rounded-xl bg-brand-900/95 p-2 shadow-2xl ring-1 ring-white/15 backdrop-blur">
                ${missing.map((b) => `
                    <div class="rounded-lg bg-white/5 p-2">
                        <p class="truncate text-sm font-semibold text-white">${esc(b.name)}</p>
                        <p class="truncate text-[11px] text-brand-100/50">${b.address ? esc(b.address) : 'No address on file'}</p>
                        <div class="mt-1.5 flex gap-1.5">
                            ${b.address ? `<button type="button" data-locate="${b.id}" class="rounded-md bg-white/10 px-2 py-1 text-[11px] font-semibold text-white hover:bg-white/15">Look up address</button>` : ''}
                            <button type="button" data-place="${b.id}" class="rounded-md bg-brand-400 px-2 py-1 text-[11px] font-semibold text-brand-900 hover:bg-brand-300">Place on map</button>
                        </div>
                    </div>`).join('')}
            </div>`;
        slot.querySelector('[data-unpinned-toggle]').addEventListener('click', (event) => {
            const list = slot.querySelector('[data-unpinned-list]');
            list.classList.toggle('hidden');
            event.currentTarget.setAttribute('aria-expanded', String(!list.classList.contains('hidden')));
        });
        slot.querySelectorAll('[data-place]').forEach((b) => b.addEventListener('click', () => mapApi?.startPlace(Number(b.dataset.place))));
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
            ...snapshot.buildings.filter((b) => b.name.toLowerCase().includes(q)).map((b) => ({ kind: 'client', id: b.id, name: b.name, sub: pinned(b) ? STATE[b.state].label : 'Not on the map yet', colour: STATE[b.state].color })),
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
            if (mapApi?.cancelPlacing()) return;
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
        mapApi = startMap();
    } catch (e) {
        console.error(e);
        root.querySelector('[data-map-fallback]').classList.replace('hidden', 'flex');
    }
    renderUnpinned();

    /* ——— The map ——————————————————————————————————————————————————— */

    function startMap() {
        const map = L.map(host, {
            zoomControl: false,
            attributionControl: true,
            minZoom: 5,
            maxZoom: 18,
            worldCopyJump: false,
            zoomSnap: 0.5,
        });
        map.attributionControl.setPrefix(false).setPosition('bottomright');

        L.tileLayer(TILES, { attribution: ATTRIBUTION, maxZoom: 19 }).addTo(map);
        let style = store.get('studio-map.tiles') === 'night' ? 'night' : 'streets';
        const setStyle = (next) => {
            style = next;
            host.classList.toggle('is-night', next === 'night');
            store.set('studio-map.tiles', next);
            root.querySelectorAll('[data-tiles]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.tiles === next)));
        };
        setStyle(style);
        root.querySelectorAll('[data-tiles]').forEach((b) => {
            b.setAttribute('aria-pressed', String(b.dataset.tiles === style));
            b.addEventListener('click', () => setStyle(b.dataset.tiles));
        });

        const routes = L.layerGroup().addTo(map);
        const pins = L.layerGroup().addTo(map);
        const boards = L.layerGroup().addTo(map);
        const crew = L.layerGroup().addTo(map);

        const pinMarkers = new Map(); // client id -> marker
        const offsets = new Map(); // client id -> [dx, dy] when several share a spot
        let walkers = []; // { marker, personId, shoots, homeIndex, current }
        const billboards = new Map(); // client id -> { marker, eventId }
        let hqMarker = null;
        let moving = null; // { target, id?, marker, from }
        let placing = null; // client id
        let dropEl = null;

        const hqLatLng = () => {
            if (snapshot.studio.set) return L.latLng(snapshot.studio.lat, snapshot.studio.lng);
            const spots = snapshot.buildings.filter(pinned);
            if (!spots.length) return L.latLng(TRICHY);
            return L.latLng(spots.reduce((s, b) => s + b.lat, 0) / spots.length, spots.reduce((s, b) => s + b.lng, 0) / spots.length);
        };

        /* ——— Pins ——— */
        function pinHtml(b) {
            const L_ = LENSES[lens];
            const colour = hex(L_.colour(b, snapshot));
            const size = L_.size(b);
            const badge = L_.badge(b);
            const fill = L_.gauge?.(b);
            const freshHere = live && snapshot.day.events.some((e) => fresh.has(e.id) && e.client_id === b.id);
            const [dx, dy] = offsets.get(b.id) ?? [0, 0];
            const classes = [
                'sm-pin',
                L_.glow(b) || freshHere ? 'is-glowing' : '',
                L_.loud(b) ? 'is-loud' : '',
                b.active ? '' : 'is-faded',
                fill != null ? 'has-gauge' : '',
                selectedId === b.id ? 'is-selected' : '',
            ].filter(Boolean).join(' ');

            return `<div class="${classes}" data-client="${b.id}" style="--c:${colour};--size:${size}px;${fill != null ? `--fill:${fill};` : ''}transform:translate(${dx}px,${dy}px)">
                ${badge ? `<span class="sm-pin__badge">${esc(badge)}</span>` : ''}
                <span class="sm-pin__dot" role="button" aria-label="${esc(b.name)}">${esc(initials(b.name))}</span>
                <span class="sm-pin__label">${esc(b.name)}</span>
            </div>`;
        }

        /*
         * Pins that would overlap on screen fan out round their shared centre.
         * Worked out in screen pixels and redone on every zoom: three clients
         * on Madurai Road are one blob zoomed out and three pins zoomed in. The
         * studio joins in as the fixed middle of whatever group it lands in, so
         * clients in its own town ring it rather than bury it.
         */
        function declutter() {
            offsets.clear();
            if (!map._loaded) return;
            const items = snapshot.buildings.filter(pinned).map((b) => ({ id: b.id, b, p: map.latLngToLayerPoint([b.lat, b.lng]), size: LENSES[lens].size(b) }));
            const hq = { id: 'hq', p: map.latLngToLayerPoint(hqLatLng()), size: 30 };
            const all = [hq, ...items];
            const parent = all.map((_, i) => i);
            const find = (i) => (parent[i] === i ? i : (parent[i] = find(parent[i])));
            for (let i = 0; i < all.length; i++) {
                for (let j = i + 1; j < all.length; j++) {
                    if (all[i].p.distanceTo(all[j].p) < (all[i].size + all[j].size) / 2 + 8) parent[find(i)] = find(j);
                }
            }
            const groups = new Map();
            all.forEach((item, i) => groups.set(find(i), [...(groups.get(find(i)) ?? []), item]));

            groups.forEach((group) => {
                if (group.length < 2) return;
                const withHq = group.includes(hq);
                const members = group.filter((x) => x !== hq).sort((x, y) => byStateThenName(x.b, y.b));
                const center = withHq ? hq.p : members.reduce((c, m) => c.add(m.p), L.point(0, 0)).divideBy(members.length);
                const widest = Math.max(...members.map((m) => m.size));
                const r = Math.max(widest + 6, (members.length * (widest + 8)) / (2 * Math.PI)) + (withHq ? 16 : 0);
                members.forEach((m, k) => {
                    const angle = (k / members.length) * Math.PI * 2 - Math.PI / 2;
                    offsets.set(m.id, [Math.round(center.x + Math.cos(angle) * r - m.p.x), Math.round(center.y + Math.sin(angle) * r - m.p.y)]);
                });
            });

            pinMarkers.forEach((marker, id) => {
                const [dx, dy] = offsets.get(id) ?? [0, 0];
                const el = marker.getElement()?.querySelector('.sm-pin');
                if (el) el.style.transform = `translate(${dx}px, ${dy}px)`;
            });
            billboards.forEach((board, id) => {
                const [dx, dy] = offsets.get(id) ?? [0, 0];
                const el = board.marker.getElement()?.querySelector('.sm-board');
                if (el) el.style.transform = `translate(${dx}px, ${dy - board.lift}px)`;
            });
        }

        function drawPins() {
            pins.clearLayers();
            pinMarkers.clear();
            snapshot.buildings.filter(pinned).sort(byStateThenName).reverse().forEach((b) => {
                const marker = L.marker([b.lat, b.lng], {
                    icon: L.divIcon({ html: pinHtml(b), className: '', iconSize: [0, 0] }),
                    keyboard: true,
                    title: b.name,
                    riseOnHover: true,
                    zIndexOffset: LENSES[lens].loud(b) ? 500 : 0,
                });
                marker.on('click', (e) => (placing != null ? place({ target: 'client', client_id: placing }, e.latlng) : !moving && select(b.id)));
                marker.on('contextmenu', () => !moving && placing == null && select(b.id));
                marker.addTo(pins);
                pinMarkers.set(b.id, marker);
            });

            const at = hqLatLng();
            if (hqMarker) {
                hqMarker.setLatLng(at);
                hqMarker.setIcon(hqIcon());
            } else {
                // Under the client pins: they are what gets clicked, the studio is the backdrop.
                hqMarker = L.marker(at, { icon: hqIcon(), zIndexOffset: -300, title: 'Chakra studio', keyboard: true }).addTo(map);
                hqMarker.on('click', () => !moving && select('hq'));
            }
            declutter();
        }
        const hqIcon = () => L.divIcon({
            className: '',
            iconSize: [0, 0],
            html: `<div class="sm-hq ${snapshot.studio.set ? '' : 'is-unset'}" data-hq><span class="sm-hq__pill">CHAKRA</span></div>`,
        });

        function refreshPins() {
            pinMarkers.forEach((marker, id) => {
                const b = building(id);
                if (b) marker.setIcon(L.divIcon({ html: pinHtml(b), className: '', iconSize: [0, 0] }));
            });
        }

        /* ——— Routes and crew ——— */
        function drawRoutes() {
            routes.clearLayers();
            const from = hqLatLng();
            const time = live ? now() : t;
            snapshot.day.shoots.forEach((s) => {
                const b = building(s.client_id);
                if (!b || !pinned(b)) return;
                const done = s.finished_at && time >= s.finished_at;
                const onSite = !done && whereAt(s, time).phase === 'site';
                L.polyline([from, [b.lat, b.lng]], {
                    color: done ? '#94a3b8' : onSite ? '#34d399' : '#fbbf24',
                    weight: 3,
                    opacity: 0.85,
                    className: `sm-route ${done ? 'is-done' : ''}`,
                    interactive: false,
                }).addTo(routes);
            });
        }

        function drawCrew() {
            crew.clearLayers();
            const shootsFor = (id) => snapshot.day.shoots.filter((s) => s.crew_ids.includes(id) && pinned(building(s.client_id) ?? {}));
            const cast = lens === 'crew' ? snapshot.team : snapshot.team.filter((p) => shootsFor(p.id).length);
            walkers = cast.map((p, i) => {
                const marker = L.marker(hqLatLng(), {
                    icon: L.divIcon({
                        className: '',
                        iconSize: [0, 0],
                        html: `<div class="sm-crew" data-crew="${p.id}" style="--c:${hex(C.brand)}"><span class="sm-crew__face" title="${esc(p.name)} — drag onto a pin">${esc(initials(p.name))}</span><span class="sm-crew__name">${esc(firstName(p.name))}${lens === 'crew' && p.hours_today ? ` · ${p.hours_today}h` : ''}</span></div>`,
                    }),
                    zIndexOffset: 2000,
                    keyboard: false,
                });
                marker.addTo(crew);
                return { marker, personId: p.id, shoots: shootsFor(p.id), homeIndex: i, current: null };
            });
            placeCrew();
        }

        function placeCrew() {
            const time = live ? now() : t;
            const home = hqLatLng();
            const atSite = new Map(); // client id -> how many already placed there
            const atHome = walkers.filter((w) => !w.shoots.some((s) => whereAt(s, time).phase !== 'home')).length;
            let homeSlot = 0;

            walkers.forEach((w) => {
                let where = { phase: 'home' };
                w.current = null;
                for (const s of w.shoots) {
                    const here = whereAt(s, time);
                    if (here.phase !== 'home') {
                        where = here;
                        w.current = s;
                        break;
                    }
                }

                const el = w.marker.getElement()?.querySelector('.sm-crew');
                const show = where.phase !== 'home' || lens === 'crew';
                if (el) {
                    el.style.display = show ? '' : 'none';
                    el.style.setProperty('--c', hex(PHASE[where.phase].color));
                    el.classList.toggle('is-walking', where.phase === 'walking');
                }
                if (!show) return;

                let latlng = home;
                let ring = null;
                if (where.phase === 'home') {
                    ring = [homeSlot++, atHome, 34];
                } else {
                    const b = building(w.current.client_id);
                    const dest = L.latLng(b.lat, b.lng);
                    if (where.phase === 'site') {
                        latlng = dest;
                        const n = atSite.get(b.id) ?? 0;
                        atSite.set(b.id, n + 1);
                        ring = [n, 6, (LENSES[lens].size(b) / 2) + 20, offsets.get(b.id)];
                    } else {
                        const k = where.progress ?? 0.55;
                        latlng = L.latLng(home.lat + (dest.lat - home.lat) * k, home.lng + (dest.lng - home.lng) * k);
                    }
                }
                w.marker.setLatLng(latlng);
                if (el) {
                    if (ring) {
                        const [i, n, r, base = [0, 0]] = ring;
                        const angle = (i / Math.max(n, 1)) * Math.PI * 2 + Math.PI / 4;
                        el.style.transform = `translate(${Math.round(base[0] + Math.cos(angle) * r)}px, ${Math.round(base[1] + Math.sin(angle) * r)}px)`;
                    } else {
                        el.style.transform = '';
                    }
                }
            });
        }

        /* ——— Billboards ——— */
        function syncBillboards() {
            const time = live ? now() : t;
            const latest = new Map();
            for (const e of snapshot.day.events) {
                const b = e.client_id && building(e.client_id);
                if (e.type === 'video' && e.photo && b && pinned(b) && e.at <= time && time - e.at <= BILLBOARD_FOR_MS) {
                    latest.set(e.client_id, e); // in time order, so the last one wins
                }
            }
            billboards.forEach((board, clientId) => {
                if (latest.get(clientId)?.id !== board.eventId) {
                    boards.removeLayer(board.marker);
                    billboards.delete(clientId);
                }
            });
            latest.forEach((e, clientId) => {
                if (billboards.has(clientId)) return;
                const b = building(clientId);
                const [dx, dy] = offsets.get(clientId) ?? [0, 0];
                const lift = LENSES[lens].size(b) / 2 + 26;
                const marker = L.marker([b.lat, b.lng], {
                    icon: L.divIcon({
                        className: '',
                        iconSize: [0, 0],
                        html: `<div class="sm-board" style="transform:translate(${dx}px, ${dy - lift}px)"><div class="sm-board__card"><img src="${esc(e.photo)}" alt="" loading="lazy"><p>${esc(e.title)} · ${esc(e.time)}</p></div></div>`,
                    }),
                    zIndexOffset: 1500,
                    keyboard: false,
                }).addTo(boards);
                marker.on('click', () => select(clientId));
                billboards.set(clientId, { marker, eventId: e.id, lift });
            });
        }

        /* ——— Fitting, zoom, fullscreen ——— */
        // The map opens on the studio's own patch -- clients within reach of
        // it -- so one client in Chennai does not shrink every pin around
        // Trichy to a dot. "Show every client" fits the lot.
        const NEAR_KM = 150;
        function fit({ animate = true, everyone = true } = {}) {
            const home = hqLatLng();
            let points = snapshot.buildings.filter(pinned).map((b) => L.latLng(b.lat, b.lng));
            if (!everyone) {
                const near = points.filter((p) => p.distanceTo(home) <= NEAR_KM * 1000);
                if (near.length >= 2) points = near;
            }
            if (snapshot.studio.set) points.push(home);
            if (!points.length) return map.setView(TRICHY, 9, { animate });
            if (points.length === 1) return map.setView(points[0], 13, { animate });
            map.fitBounds(L.latLngBounds(points), { padding: [90, 90], maxZoom: 14, animate });
        }
        root.querySelector('[data-map-fit]').addEventListener('click', () => fit());
        root.querySelectorAll('[data-map-zoom]').forEach((b) => b.addEventListener('click', () => (b.dataset.mapZoom === 'in' ? map.zoomIn() : map.zoomOut())));

        const fullButton = root.querySelector('[data-map-fullscreen]');
        if (!box.requestFullscreen) fullButton.classList.add('hidden');
        fullButton.addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : box.requestFullscreen()));
        document.addEventListener('fullscreenchange', () => setTimeout(() => map.invalidateSize(), 50));
        new ResizeObserver(() => map.invalidateSize()).observe(box);

        const setFar = () => host.classList.toggle('is-far', map.getZoom() < FAR_ZOOM);
        map.on('zoomend', () => {
            setFar();
            declutter();
            placeCrew();
        });
        map.on('move zoom', positionCard);
        map.on('click', (e) => {
            if (placing != null) return place({ target: 'client', client_id: placing }, e.latlng);
            if (!moving) closeCard();
        });

        /* ——— Placing and moving pins ——— */
        function showBanner(text, canSave) {
            banner.querySelector('[data-place-text]').textContent = text;
            banner.querySelector('[data-place-save]').classList.toggle('hidden', !canSave);
            banner.classList.replace('hidden', 'flex');
        }
        const hideBanner = () => banner.classList.replace('flex', 'hidden');

        function startPlace(clientId) {
            cancelPlacing();
            closeCard();
            placing = clientId;
            host.classList.add('is-placing');
            showBanner(`Click the map where ${building(clientId)?.name ?? 'they'} are`, false);
        }

        function startMove({ target, id }) {
            cancelPlacing();
            const marker = target === 'studio' ? hqMarker : pinMarkers.get(id);
            if (!marker) return;
            closeCard();
            moving = { target, id, marker, from: marker.getLatLng() };
            marker.dragging.enable();
            showBanner(`Drag ${target === 'studio' ? 'the Chakra pin' : `${building(id).name}'s pin`} to the right spot, then save`, true);
        }

        function cancelPlacing() {
            const was = placing != null || moving != null;
            if (placing != null) {
                placing = null;
                host.classList.remove('is-placing');
            }
            if (moving) {
                moving.marker.dragging.disable();
                moving.marker.setLatLng(moving.from);
                moving = null;
            }
            hideBanner();
            return was;
        }

        async function place(body, latlng) {
            try {
                const result = await actions.post(actions.urls.place, { ...body, lat: latlng.lat, lng: latlng.lng });
                actions.toast(result.message);
                if (moving) moving.marker.dragging.disable();
                moving = null;
                placing = null;
                host.classList.remove('is-placing');
                hideBanner();
                refresh();
            } catch (e) {
                actions.toast(e.message, 'error');
            }
        }

        banner.querySelector('[data-place-cancel]').addEventListener('click', cancelPlacing);
        banner.querySelector('[data-place-save]').addEventListener('click', () => {
            if (!moving) return;
            place(moving.target === 'studio' ? { target: 'studio' } : { target: 'client', client_id: moving.id }, moving.marker.getLatLng());
        });

        /* ——— Picking people up off the map ——— */
        host.addEventListener('pointerdown', (event) => {
            const face = event.target.closest('.sm-crew');
            if (!face || event.button > 0) return;
            event.stopPropagation(); // not a map pan
            event.preventDefault();
            map.dragging.disable();
            const w = walkers.find((x) => x.personId === Number(face.dataset.crew));
            armDrag(Number(face.dataset.crew), w?.current ?? null, event);
        }, { capture: true });

        /* ——— First draw ——— */
        // View first: decluttering works in screen pixels, which need one.
        fit({ animate: false, everyone: false });
        drawPins();
        drawRoutes();
        drawCrew();
        syncBillboards();
        declutter();
        setFar();

        // Live: people and photos move on the clock, but slowly -- a nudge
        // every fifteen seconds is plenty, and costs nothing in between.
        setInterval(() => {
            if (live && !document.hidden) {
                placeCrew();
                syncBillboards();
            }
        }, LIVE_TICK_MS);

        return {
            flyTo(id) {
                const b = building(id);
                if (b && pinned(b)) map.panTo([b.lat, b.lng], { animate: !reduceMotion() });
            },
            pointOf(id) {
                if (id === 'hq') {
                    const p = map.latLngToContainerPoint(hqMarker.getLatLng());
                    return { x: p.x, y: p.y - 18 };
                }
                const b = building(id);
                if (!b || !pinned(b)) return null;
                const p = map.latLngToContainerPoint([b.lat, b.lng]);
                const [dx, dy] = offsets.get(id) ?? [0, 0];
                return { x: p.x + dx, y: p.y + dy - LENSES[lens].size(b) / 2 - 18 };
            },
            dropTargetAt(x, y) {
                const el = document.elementFromPoint(x, y);
                if (el?.closest('[data-hq]')) return { kind: 'hq', el: el.closest('[data-hq]') };
                const pin = el?.closest('[data-client]');
                return pin ? { kind: 'client', id: Number(pin.dataset.client), el: pin } : null;
            },
            highlightDrop(target, tone) {
                dropEl?.classList.remove('drop-green', 'drop-amber', 'drop-red');
                dropEl = target && tone ? target.el : null;
                dropEl?.classList.add(`drop-${tone}`);
            },
            markDragging(personId, on) {
                host.querySelector(`.sm-crew[data-crew="${personId}"]`)?.classList.toggle('is-dragging', on);
            },
            dragEnded() {
                map.dragging.enable();
            },
            redraw() {
                // Mid-move, a refresh would replace the very pin being dragged.
                if (moving) return;
                drawPins();
                drawRoutes();
                drawCrew();
                billboards.forEach((board) => boards.removeLayer(board.marker));
                billboards.clear();
                syncBillboards();
            },
            refreshPins,
            tick() {
                drawRoutes();
                placeCrew();
                syncBillboards();
            },
            startPlace,
            startMove,
            cancelPlacing,
        };
    }
}

/* ——— Plain DOM: ticker and lists — work without the map ——————————————— */

const VERB = {
    reached: (e) => `${esc(firstName(e.who ?? 'Crew'))} reached`,
    rolling: (e) => `${esc(firstName(e.who ?? 'Crew'))} started rolling`,
    video: (e) => `${esc(firstName(e.who ?? 'Crew'))} logged “${esc(e.title)}”`,
    wrapped: () => 'Wrapped',
};
const DOT = { reached: C.amber, rolling: C.red, video: C.green, wrapped: C.sky };

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
                <span class="shrink-0 text-xs text-brand-100/60">${pinned(b) ? '' : 'not on map · '}${esc(STATE[b.state].label)}${b.owed ? ' · ' + money(b.owed) : ''}</span>
            </button>
        </li>`).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-studio-map]');
    if (root) boot(root);
});
