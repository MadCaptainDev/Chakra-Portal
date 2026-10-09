/*
 * The Studio Map (studio-map/index.blade.php): the studio as a small 3D
 * town. Chakra is the tower in the middle; every client is a building
 * around it, the ones that need you nearest.
 *
 *  - Lenses. One town, five questions -- Status, Money (rupees per hour of
 *    work), Hours, Content (days of reels left) and Crew (where everybody
 *    is). A lens decides each building's colour, height, badge and legend.
 *  - The live feed. Today's shoot events run along the bottom, and a video's
 *    photo goes up as a billboard over its client's building for a few hours.
 *  - Replay. The slider is the time of day; crew, billboards and the feed are
 *    all worked out from the timeline for whatever time it says.
 *  - Acting. Click a building for its command card -- its actions, most
 *    needed first. Drag a person from the team bench (or, in the Crew lens,
 *    off the map) onto a building to put them on a shoot, or onto the tower
 *    to take them off one. The actions themselves live in
 *    studio-map-actions.js; this file decides when to offer them.
 *
 * The snapshot is re-fetched every 30 seconds, and straight after any action.
 *
 * Its own Vite entry, loaded by that one page, so three.js never lands in
 * app.js for everybody else.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
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
const SPACING = 7;
const REFRESH_MS = 30000;
const BILLBOARD_FOR_MS = 3 * 60 * 60 * 1000;
const MIN = 60 * 1000;
const DRAG_START_PX = 6;

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const hex = (n) => '#' + n.toString(16).padStart(6, '0');
const clockOf = (ms) => new Date(ms).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' });
const firstName = (name) => String(name).split(' ')[0];
const byStateThenName = (a, b) => ORDER.indexOf(a.state) - ORDER.indexOf(b.state) || a.name.localeCompare(b.name);
const typing = (el) => el?.closest?.('input, textarea, select, [contenteditable="true"]');

/* ——— Lenses ——————————————————————————————————————————————————————— */

const heightFor = (value, scale) => 1.2 + Math.min(11, Math.sqrt(Math.max(0, value)) * scale);

const LENSES = {
    status: {
        legend: () => ORDER.map((k) => [STATE[k].color, STATE[k].label]),
        colour: (b) => STATE[b.state].color,
        glow: (b) => STATE[b.state].glow,
        height: (b) => heightFor(b.hours, 0.9),
        badge: (b) => {
            if (b.overdue_days > 0) return `₹ ${b.overdue_days}d overdue`;
            if (b.state === 'rolling') return '● REC';
            if (b.state === 'shoot') return 'SHOOT TODAY';
            const waiting = b.scripts_waiting + b.proposals_waiting;
            return waiting ? `${waiting} WAITING` : null;
        },
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
        height: (b) => heightFor(b.invoiced_month / 1000, 0.9),
        badge: (b) => (b.per_hour != null ? `${money(b.per_hour)}/hr` : null),
        note: 'Height: invoiced this month',
    },
    hours: {
        legend: () => [[0x2f6e84, 'Under 10 hours'], [0x4fa9c4, '10–30 hours'], [0x8acce0, '30+ hours'], [C.slate, 'None this month']],
        colour: (b) => (b.hours <= 0 ? C.slate : b.hours < 10 ? 0x2f6e84 : b.hours < 30 ? 0x4fa9c4 : 0x8acce0),
        glow: () => false,
        height: (b) => heightFor(b.hours, 0.9),
        badge: (b) => (b.hours > 0 ? `${b.hours.toFixed(1)}h` : null),
        note: 'Height: hours this month',
    },
    content: {
        legend: () => [[C.red, 'Runs out within a week, no shoot booked'], [C.amber, 'Runs out within 3 weeks'], [C.green, 'Enough content'], [C.slate, 'Not tracked']],
        colour: (b) => ({ critical: C.red, warning: C.amber, ok: C.green })[b.content?.status] ?? C.slate,
        glow: (b) => b.content?.status === 'critical',
        height: () => 3,
        badge: (b) => {
            if (!b.content) return null;
            if (b.content.days_left != null) return b.content.days_left <= 0 ? 'OUT OF CONTENT' : `${b.content.days_left}d left`;
            return `${b.content.remaining} reels`;
        },
        gauge: (b) => (b.content?.days_left != null ? Math.max(0, Math.min(1, b.content.days_left / 42)) : null),
        note: 'Roof tank: six weeks of reels = full',
    },
    crew: {
        legend: () => [[C.green, 'On site'], [C.amber, 'On the way'], [C.brand, 'At the studio']],
        colour: () => 0x3b4b55,
        glow: () => false,
        height: () => 1.6,
        badge: () => null,
        note: 'Drag anyone onto a building or the tower',
    },
};
const LENS_KEYS = Object.keys(LENSES);

/* ——— Small three.js helpers ———————————————————————————————————————— */

function slot(i) {
    let ring = 1;
    let start = 0;
    while (i >= start + ring * 6) {
        start += ring * 6;
        ring += 1;
    }
    const count = ring * 6;
    const angle = ((i - start) / count) * Math.PI * 2 + (ring % 2 ? 0 : Math.PI / count);

    return new THREE.Vector3(Math.cos(angle) * ring * SPACING, 0, Math.sin(angle) * ring * SPACING);
}

function label(text, { fg = '#ffffff', bg = 'rgba(8,20,28,0.78)', size = 28, weight = 600 } = {}) {
    const ratio = 2;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const font = `${weight} ${size * ratio}px ui-sans-serif, system-ui, sans-serif`;
    ctx.font = font;
    const pad = 12 * ratio;
    canvas.width = Math.ceil(ctx.measureText(text).width + pad * 2);
    canvas.height = Math.ceil(size * ratio * 1.6);
    ctx.font = font;
    ctx.fillStyle = bg;
    ctx.beginPath();
    ctx.roundRect(0, 0, canvas.width, canvas.height, canvas.height / 2);
    ctx.fill();
    ctx.fillStyle = fg;
    ctx.textBaseline = 'middle';
    ctx.fillText(text, pad, canvas.height / 2 + ratio);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, depthWrite: false, transparent: true }));
    const height = size / 20;
    sprite.scale.set((canvas.width / canvas.height) * height, height, 1);

    return sprite;
}

let windowImage = null;
function windowTexture(repeatY) {
    if (!windowImage) {
        windowImage = document.createElement('canvas');
        windowImage.width = 64;
        windowImage.height = 64;
        const ctx = windowImage.getContext('2d');
        ctx.fillStyle = '#9aa4ad';
        ctx.fillRect(0, 0, 64, 64);
        for (let x = 0; x < 2; x++) {
            for (let y = 0; y < 2; y++) {
                ctx.fillStyle = Math.random() > 0.35 ? '#ffffff' : '#5d6870';
                ctx.fillRect(8 + x * 30, 8 + y * 30, 18, 20);
            }
        }
    }
    const texture = new THREE.CanvasTexture(windowImage);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.wrapS = texture.wrapT = THREE.RepeatWrapping;
    texture.repeat.set(1.5, repeatY);

    return texture;
}

function dispose(object) {
    object.traverse((child) => {
        child.geometry?.dispose();
        const materials = Array.isArray(child.material) ? child.material : [child.material];
        materials.filter(Boolean).forEach((m) => {
            m.map?.dispose();
            m.dispose();
        });
    });
}

/* ——— Where a crew member is at time t ——————————————————————————————————
 * home: at the studio. walking: on the way (progress 0..1, or null when the
 * arrival time is not known yet -- they pace the street). site: on location.
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
    const tooltip = root.querySelector('[data-map-tooltip]');
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

    let selectedId = null; // the building the command card is for
    let panelView = null; // { type: 'building'|'person', id }
    let gl = null; // set once WebGL is up: { flyTo(id), projectTop(id), canDrag }

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
        const b = building(id);
        if (!b) return closeCard();
        selectedId = id;
        if (!gl) return openDetails(id); // no map to pin a card to
        gl.flyTo(id);
        renderCard();
    }

    function renderCard() {
        const b = building(selectedId);
        if (!b) return closeCard();
        const style = STATE[b.state];
        const list = actions.actionsFor(b);
        const badge = LENSES[lens].badge(b);

        card.innerHTML = `
            <div class="relative w-[min(92vw,310px)] rounded-2xl bg-brand-900/95 shadow-2xl ring-1 ring-white/15 backdrop-blur-md" data-card-inner>
                <div class="flex items-start gap-3 p-3 pb-2">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-sm" style="background:${hex(style.color)}"></span>
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
                    <a href="${esc(b.links.client)}" class="flex flex-1 items-center justify-center gap-1.5 border-l border-white/10 py-2.5 text-brand-100/70 hover:text-white">${icon('out')}Open client</a>
                </div>
                <span data-card-arrow class="absolute left-1/2 h-3 w-3 -translate-x-1/2 rotate-45 bg-brand-900"></span>
            </div>`;

        card.querySelectorAll('[data-act]').forEach((button) => button.addEventListener('click', () => list[Number(button.dataset.act)].run()));
        card.querySelector('[data-card-close]').addEventListener('click', closeCard);
        card.querySelector('[data-card-details]').addEventListener('click', () => openDetails(b.id));
        card.classList.remove('hidden');
        positionCard();
    }

    function closeCard() {
        selectedId = null;
        card.classList.add('hidden');
        card.innerHTML = '';
    }

    // Pinned above its building as the camera moves; a sheet on a phone.
    function positionCard() {
        if (!selectedId || card.classList.contains('hidden') || !gl) return;
        const inner = card.querySelector('[data-card-inner]');
        const arrow = card.querySelector('[data-card-arrow]');
        const W = box.clientWidth;
        const H = box.clientHeight;

        if (W < 640) {
            Object.assign(card.style, { left: '8px', right: '8px', top: 'auto', bottom: '8px', transform: 'none' });
            inner.style.width = '100%';
            arrow.classList.add('hidden');
            return;
        }

        const point = gl.projectTop(selectedId);
        if (!point) return;
        const w = inner.offsetWidth;
        const h = inner.offsetHeight;
        const above = point.y - h - 18 > 8;
        const x = Math.min(Math.max(point.x, w / 2 + 8), W - w / 2 - 8);
        const y = above ? point.y - h - 14 : Math.min(point.y + 14, H - h - 8);

        Object.assign(card.style, { left: `${x - w / 2}px`, top: `${y}px`, right: 'auto', bottom: 'auto', transform: 'none' });
        inner.style.width = '';
        arrow.classList.toggle('hidden', Math.abs(x - point.x) > w / 2 - 16);
        arrow.style.left = `${point.x - x + w / 2}px`;
        Object.assign(arrow.style, above ? { bottom: '-6px', top: 'auto' } : { top: '-6px', bottom: 'auto' });
    }

    /* ——— Side panel: a building, or a person ——— */
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
        if (gl && status.shoot?.client_id) gl.flyTo(status.shoot.client_id);
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
            ${panelHeader(esc(style.label), hex(style.color), esc(b.name), `${b.type === 'occasion' ? 'Occasion client' : 'Regular client'}${b.active ? '' : ' · inactive'}`)}
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
                    : '<p class="rounded-lg border border-dashed border-white/15 px-3 py-3 text-center text-xs text-brand-100/50">Not on any shoot. Drag them onto a building to add them.</p>'}
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
                            title="${esc(p.name)} — ${esc(ph.label)}${p.hours_today ? `, ${p.hours_today}h today` : ''}. Drag onto a building."
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

    /* ——— Dragging people (wired to the map once WebGL is up) ——— */
    let pending = null; // pressed, not yet moved far enough to be a drag
    let drag = null;

    function armDrag(personId, fromShoot, event) {
        pending = { personId, fromShoot, x: event.clientX, y: event.clientY };
    }

    window.addEventListener('pointermove', (event) => {
        if (pending && gl?.canDrag && Math.hypot(event.clientX - pending.x, event.clientY - pending.y) > DRAG_START_PX) {
            startDrag(pending);
            pending = null;
        }
        if (drag) moveDrag(event);
    });
    window.addEventListener('pointerup', (event) => {
        if (drag) {
            drop(event);
        } else if (pending) {
            showPerson(pending.personId); // a press that never moved is a click
        }
        pending = null;
        gl?.dragEnded();
    });
    window.addEventListener('pointercancel', () => {
        pending = null;
        if (drag) cancelDrag();
    });

    function startDrag({ personId, fromShoot }) {
        const p = person(personId);
        if (!p) return;
        closeCard();
        const ghost = document.createElement('div');
        ghost.className = 'pointer-events-none fixed left-0 top-0 z-[70] flex items-center gap-2 rounded-2xl bg-brand-900/95 py-1.5 pl-1.5 pr-3 shadow-2xl ring-1 ring-brand-400/60 backdrop-blur';
        ghost.innerHTML = `
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-400 text-xs font-bold text-brand-900">${esc(initials(p.name))}</span>
            <span><span class="block text-xs font-semibold text-white">${esc(p.name)}</span><span class="block text-[11px] text-brand-100/60" data-ghost-hint>Drop on a building</span></span>`;
        document.body.appendChild(ghost);
        document.body.classList.add('select-none');
        drag = { personId, fromShoot, ghost, target: null };
    }

    function moveDrag(event) {
        drag.ghost.style.transform = `translate(${event.clientX + 14}px, ${event.clientY + 14}px)`;
        drag.target = gl.dropTargetAt(event.clientX, event.clientY);
        const intent = dropIntent(drag.target);
        drag.ghost.querySelector('[data-ghost-hint]').textContent = intent.hint;
        drag.ghost.classList.toggle('ring-emerald-400/70', intent.tone === 'green');
        drag.ghost.classList.toggle('ring-amber-400/70', intent.tone === 'amber');
        drag.ghost.classList.toggle('ring-red-400/70', intent.tone === 'red');
        gl.highlightDrop(drag.target, intent.tone);
    }

    function dropIntent(target) {
        if (!target) return { hint: 'Drop on a building', tone: null };
        if (target.kind === 'tower') {
            return drag.fromShoot
                ? { hint: `Take off ${drag.fromShoot.title}`, tone: 'red', run: () => actions.openRemove({ personId: drag.personId, shoot: { id: drag.fromShoot.id, title: drag.fromShoot.title, when: clockOf(drag.fromShoot.starts_at) } }) }
                : { hint: 'Already at the studio', tone: null };
        }
        const b = building(target.id);
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
        drag?.ghost.remove();
        document.body.classList.remove('select-none');
        drag = null;
        gl?.highlightDrop(null);
    }

    /* ——— Lens switcher ——— */
    let onRebuild = () => {};
    function setLens(next) {
        lens = next;
        root.querySelectorAll('[data-lens]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.lens === next)));
        renderLegend();
        onRebuild();
        if (selectedId) renderCard();
    }
    function renderLegend() {
        const L = LENSES[lens];
        root.querySelector('[data-legend]').innerHTML = L.legend(snapshot)
            .map(([c, text]) => `<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background:${hex(c)}"></span>${esc(text)}</div>`)
            .join('') + `<div class="pt-1 text-brand-100/50">${esc(L.note)}</div>`;
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
        syncSlider();
        renderTicker();
        renderRoster();
    }
    function scrubTo(ms) {
        live = false;
        t = Math.min(ms, now());
        slider.value = t;
        liveButton.classList.remove('hidden');
        clock.textContent = clockOf(t);
        renderTicker();
    }
    slider.addEventListener('input', () => {
        stopPlaying();
        scrubTo(Number(slider.value));
    });
    liveButton.addEventListener('click', goLive);
    function togglePlay() {
        if (playing) return stopPlaying();
        if (live || t >= now() - MIN) scrubTo(snapshot.day.from);
        playing = true;
        playButton.textContent = '❚❚ Pause';
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
                        : `<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/5"><span class="h-2.5 w-2.5 rounded-sm" style="background:${hex(h.colour)}"></span></span>`}
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
            if (selectedId) return closeCard();
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
            return; // Offline for a moment -- keep drawing the last one.
        }
        skew = snapshot.day.now - Date.now();

        // Anything not seen before is news: it glows in the feed and its
        // building pulses until the next refresh.
        fresh = new Set(snapshot.day.events.filter((e) => !seen.has(e.id)).map((e) => e.id));
        fresh.forEach((id) => seen.add(id));

        onRebuild();
        renderListsIn(root, snapshot);
        renderLegend();
        renderRoster();
        syncSlider();
        renderTicker();
        if (selectedId) renderCard();
        rerenderPanel();
    }
    setInterval(() => {
        if (!document.hidden) refresh();
    }, REFRESH_MS);

    // List and ticker clicks select a building, map or no map.
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-select]');
        if (!button || !button.dataset.select) return;
        select(Number(button.dataset.select));
        if (gl) box.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'center' });
    });

    renderListsIn(root, snapshot);
    renderLegend();
    renderRoster();
    syncSlider();
    renderTicker();

    /* ——— The town ——————————————————————————————————————————————————— */

    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({ antialias: true });
    } catch {
        root.querySelector('[data-map-fallback]').classList.replace('hidden', 'flex');
        return;
    }

    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFShadowMap;
    host.appendChild(renderer.domElement);

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x0b1a24);
    scene.fog = new THREE.Fog(0x0b1a24, 70, 150);

    const camera = new THREE.PerspectiveCamera(38, 1, 0.5, 400);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.maxPolarAngle = 1.32;
    controls.minDistance = 10;
    controls.maxDistance = 120;
    controls.autoRotate = !reduceMotion();
    controls.autoRotateSpeed = 0.3;
    controls.addEventListener('start', () => { controls.autoRotate = false; });

    scene.add(new THREE.HemisphereLight(0xbfe6f3, 0x0b1a24, 0.9));
    const sun = new THREE.DirectionalLight(0xffffff, 1.6);
    sun.position.set(30, 50, 20);
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    Object.assign(sun.shadow.camera, { left: -60, right: 60, top: 60, bottom: -60, far: 150 });
    sun.shadow.camera.updateProjectionMatrix();
    scene.add(sun);

    const ground = new THREE.Mesh(new THREE.CircleGeometry(200, 64), new THREE.MeshStandardMaterial({ color: 0x10242f, roughness: 1 }));
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    scene.add(ground);
    const grid = new THREE.GridHelper(200, 100, 0x1d3a49, 0x15303d);
    grid.position.y = 0.01;
    scene.add(grid);

    const hq = new THREE.Group();
    const tower = new THREE.Mesh(
        new THREE.CylinderGeometry(1.6, 2.2, 9, 6),
        new THREE.MeshStandardMaterial({ color: C.brand, emissive: C.brand, emissiveIntensity: 0.25, roughness: 0.4, metalness: 0.2 }),
    );
    tower.position.y = 4.5;
    tower.castShadow = true;
    hq.add(tower);
    const hqLabel = label('CHAKRA', { bg: hex(C.brand), fg: '#132A38', size: 30, weight: 800 });
    hqLabel.position.y = 10.6;
    hq.add(hqLabel);
    scene.add(hq);

    // The drop marker: a ring on the ground under whatever a person is held over.
    const dropRing = new THREE.Mesh(
        new THREE.RingGeometry(2.6, 3.2, 48),
        new THREE.MeshBasicMaterial({ color: C.green, transparent: true, opacity: 0.85, side: THREE.DoubleSide, depthWrite: false }),
    );
    dropRing.rotation.x = -Math.PI / 2;
    dropRing.position.y = 0.05;
    dropRing.visible = false;
    scene.add(dropRing);

    let town = new THREE.Group();
    let people = new THREE.Group();
    const boards = new THREE.Group(); // outside town: survives rebuilds
    scene.add(town, people, boards);

    let buildings = []; // { mesh, data, glow }
    let walkers = []; // { mesh, body, hit, personId, shoots, home, phase, current }
    const positions = new Map(); // client id -> Vector3
    const tops = new Map(); // client id -> height of whatever is on the roof
    const photoTextures = new Map(); // url -> Texture, kept for the page's life
    const billboards = new Map(); // client id -> board
    let dropTarget = null;

    function build() {
        dispose(town);
        dispose(people);
        scene.remove(town, people);
        town = new THREE.Group();
        people = new THREE.Group();
        buildings = [];
        walkers = [];
        positions.clear();
        tops.clear();

        const L = LENSES[lens];
        const sorted = [...snapshot.buildings].sort(byStateThenName);

        sorted.forEach((b, i) => {
            const at = slot(i);
            positions.set(b.id, at);

            const colour = L.colour(b, snapshot);
            const glow = L.glow(b);
            const height = L.height(b);
            const width = b.type === 'occasion' ? 2.2 : 3;

            const material = new THREE.MeshStandardMaterial({
                color: colour,
                map: windowTexture(height / 1.4),
                emissive: colour,
                emissiveIntensity: glow ? 0.35 : 0.06,
                roughness: 0.55,
                transparent: !b.active,
                opacity: b.active ? 1 : 0.55,
            });
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(width, height, width), material);
            mesh.position.set(at.x, height / 2, at.z);
            mesh.castShadow = true;
            mesh.receiveShadow = true;
            town.add(mesh);

            const roof = new THREE.Mesh(new THREE.BoxGeometry(width + 0.3, 0.25, width + 0.3), new THREE.MeshStandardMaterial({ color: 0x1f2f38, roughness: 0.8 }));
            roof.position.set(at.x, height + 0.12, at.z);
            town.add(roof);

            // Content lens: a tank on the roof, filled to the runway left.
            let top = height + 0.25;
            const fill = L.gauge?.(b);
            if (fill != null) {
                const tankH = 2.2;
                const shell = new THREE.Mesh(
                    new THREE.CylinderGeometry(0.8, 0.8, tankH, 20, 1, true),
                    new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.18, side: THREE.DoubleSide }),
                );
                shell.position.set(at.x, top + tankH / 2, at.z);
                town.add(shell);
                if (fill > 0) {
                    const level = new THREE.Mesh(
                        new THREE.CylinderGeometry(0.72, 0.72, tankH * fill, 20),
                        new THREE.MeshStandardMaterial({ color: colour, emissive: colour, emissiveIntensity: 0.4 }),
                    );
                    level.position.set(at.x, top + (tankH * fill) / 2, at.z);
                    town.add(level);
                }
                top += tankH;
            }
            tops.set(b.id, top);

            const tag = label(b.name.length > 22 ? b.name.slice(0, 21) + '…' : b.name, { size: 20 });
            tag.position.set(at.x, top + 0.9, at.z);
            town.add(tag);

            const badge = L.badge(b);
            if (badge) {
                const sprite = label(badge, { bg: hex(colour), fg: '#0b1a24', size: 18, weight: 800 });
                sprite.position.set(at.x, top + 1.9, at.z);
                town.add(sprite);
            }

            buildings.push({ mesh, data: b, glow });
        });

        // A street to anywhere a shoot is happening today.
        snapshot.day.shoots.forEach((s) => {
            const at = positions.get(s.client_id);
            if (!at) return;
            const road = new THREE.Mesh(new THREE.PlaneGeometry(0.5, at.length()), new THREE.MeshBasicMaterial({ color: C.amber, transparent: true, opacity: 0.25 }));
            road.rotation.x = -Math.PI / 2;
            road.rotation.z = -Math.atan2(at.x, at.z);
            road.position.set(at.x / 2, 0.02, at.z / 2);
            town.add(road);
        });

        // Walkers. Every lens shows the crew of today's shoots; Crew shows
        // the whole team, the ones not out on a shoot at the tower.
        const shootsFor = (id) => snapshot.day.shoots.filter((s) => s.crew_ids.includes(id) && positions.has(s.client_id));
        const cast = lens === 'crew'
            ? snapshot.team
            : snapshot.team.filter((p) => shootsFor(p.id).length);

        cast.forEach((p, i) => {
            const walker = new THREE.Group();
            const body = new THREE.Mesh(
                new THREE.CapsuleGeometry(0.28, 0.6, 4, 8),
                new THREE.MeshStandardMaterial({ color: C.brand, emissive: C.brand, emissiveIntensity: 0.4 }),
            );
            body.position.y = 0.6;
            body.castShadow = true;
            walker.add(body);
            // A fatter, invisible target, so a person is easy to pick up.
            const hit = new THREE.Mesh(new THREE.CylinderGeometry(0.75, 0.75, 2.2, 8), new THREE.MeshBasicMaterial({ visible: false }));
            hit.position.y = 1.1;
            walker.add(hit);
            const tag = label(lens === 'crew' && p.hours_today ? `${firstName(p.name)} · ${p.hours_today}h` : firstName(p.name), { size: 14, bg: 'rgba(0,0,0,0.6)' });
            tag.position.y = 1.7;
            walker.add(tag);
            people.add(walker);

            const angle = (i / Math.max(cast.length, 1)) * Math.PI * 2;
            walkers.push({ mesh: walker, body, hit, personId: p.id, shoots: shootsFor(p.id), home: new THREE.Vector3(Math.cos(angle) * 3.4, 0, Math.sin(angle) * 3.4), phase: i * 0.9, current: null });
        });

        scene.add(town, people);
        billboards.forEach((board, clientId) => placeBoard(board, clientId));
        fitCamera(sorted.length);
    }
    onRebuild = build;

    let fitted = false;
    function fitCamera(count) {
        if (fitted) return;
        fitted = true;
        let rings = 1;
        let room = 6;
        while (room < count) {
            rings += 1;
            room += rings * 6;
        }
        const reach = rings * SPACING + 6;
        camera.position.set(reach * 1.1, reach * 0.95, reach * 1.1);
        controls.target.set(0, 0, 0);
        controls.update();
    }

    function placeWalkers(time, anim) {
        walkers.forEach((w) => {
            let where = { phase: 'home' };
            let to = null;
            w.current = null;
            for (const s of w.shoots) {
                const here = whereAt(s, time);
                if (here.phase !== 'home') {
                    where = here;
                    to = positions.get(s.client_id);
                    w.current = s;
                    break;
                }
            }

            const show = where.phase !== 'home' || lens === 'crew';
            w.mesh.visible = show;
            if (!show) return;

            const colour = PHASE[where.phase].color;
            w.body.material.color.setHex(colour);
            w.body.material.emissive.setHex(colour);
            w.mesh.scale.setScalar(drag?.personId === w.personId ? 1.35 : 1);

            const s = anim * 0.25 + w.phase;
            if (where.phase === 'home') {
                w.mesh.position.copy(w.home);
            } else if (where.phase === 'site') {
                const a = s * 1.4;
                w.mesh.position.set(to.x + Math.cos(a) * 2.6, 0, to.z + Math.sin(a) * 2.6);
            } else {
                const end = to.clone().multiplyScalar(1 - 2.2 / to.length());
                const k = where.progress ?? (Math.sin(s) + 1) / 2;
                w.mesh.position.set(end.x * k, 0, end.z * k);
            }
        });
    }

    // ——— Billboards: each building's latest video photo, for a few hours ———
    function photoTexture(url, onReady) {
        let texture = photoTextures.get(url);
        if (texture) {
            if (texture.image) onReady(texture);
            return texture;
        }
        texture = new THREE.TextureLoader().load(url, onReady);
        texture.colorSpace = THREE.SRGBColorSpace;
        photoTextures.set(url, texture);

        return texture;
    }

    function placeBoard(board, clientId) {
        const at = positions.get(clientId);
        if (at) board.group.position.set(at.x, (tops.get(clientId) ?? 3) + 4.6, at.z);
    }

    function makeBoard(event, clientId, born) {
        const group = new THREE.Group();
        const material = new THREE.SpriteMaterial({ color: 0xffffff, depthWrite: false });
        const photo = new THREE.Sprite(material);
        photo.scale.set(3.2, 3.2, 1);
        material.map = photoTexture(event.photo, (texture) => {
            const ratio = texture.image.width / texture.image.height || 1;
            photo.scale.set(3.2 * Math.min(ratio, 1.6), 3.2 / Math.max(ratio / 1.6, 1), 1);
            material.needsUpdate = true;
        });
        group.add(photo);
        const caption = label(`${event.title} · ${event.time}`, { size: 14, bg: 'rgba(0,0,0,0.75)' });
        caption.position.y = -2.1;
        group.add(caption);
        boards.add(group);

        const board = { group, eventId: event.id, born, photo, caption };
        placeBoard(board, clientId);

        return board;
    }

    function dropBoard(board) {
        boards.remove(board.group);
        board.caption.material.map.dispose();
        board.caption.material.dispose();
        board.photo.material.dispose(); // the photo texture itself is cached
    }

    function syncBillboards(time, elapsed) {
        const latest = new Map();
        for (const e of snapshot.day.events) {
            if (e.type === 'video' && e.photo && e.at <= time && time - e.at <= BILLBOARD_FOR_MS && positions.has(e.client_id)) {
                latest.set(e.client_id, e); // in time order, so the last one wins
            }
        }

        billboards.forEach((board, clientId) => {
            if (latest.get(clientId)?.id !== board.eventId) {
                dropBoard(board);
                billboards.delete(clientId);
            }
        });
        latest.forEach((event, clientId) => {
            if (!billboards.has(clientId)) billboards.set(clientId, makeBoard(event, clientId, elapsed));
        });

        billboards.forEach((board) => {
            const age = elapsed - board.born;
            const scale = reduceMotion() ? 1 : Math.min(1, age / 0.4) * (1 + Math.max(0, 0.15 - Math.max(0, age - 0.4) * 0.3));
            board.group.scale.setScalar(Math.max(scale, 0.001));
        });
    }

    // ——— Picking ———
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();

    function rayFrom(clientX, clientY) {
        const rect = renderer.domElement.getBoundingClientRect();
        if (clientX < rect.left || clientX > rect.right || clientY < rect.top || clientY > rect.bottom) return false;
        pointer.set(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);

        return true;
    }
    function buildingAt(clientX, clientY) {
        if (!rayFrom(clientX, clientY)) return null;
        const hit = raycaster.intersectObjects(buildings.map((b) => b.mesh))[0];

        return hit ? buildings.find((b) => b.mesh === hit.object) : null;
    }
    function walkerAt(clientX, clientY) {
        if (!rayFrom(clientX, clientY)) return null;
        const hit = raycaster.intersectObjects(walkers.filter((w) => w.mesh.visible).map((w) => w.hit))[0];

        return hit ? walkers.find((w) => w.hit === hit.object) : null;
    }

    let hovered = null;
    let downAt = null;
    let longPress = null;

    // Capture phase, so a press on a person stops the camera before
    // OrbitControls starts turning it.
    renderer.domElement.addEventListener('pointerdown', (event) => {
        downAt = [event.clientX, event.clientY];
        const w = walkerAt(event.clientX, event.clientY);
        if (w) {
            controls.enabled = false;
            armDrag(w.personId, w.current, event);
            return;
        }
        // Long-press a building on a touch screen = right-click.
        clearTimeout(longPress);
        if (event.pointerType === 'touch') {
            longPress = setTimeout(() => {
                const b = buildingAt(downAt[0], downAt[1]);
                if (b) select(b.data.id);
            }, 550);
        }
    }, { capture: true });

    renderer.domElement.addEventListener('pointermove', (event) => {
        if (downAt && Math.hypot(event.clientX - downAt[0], event.clientY - downAt[1]) > DRAG_START_PX) clearTimeout(longPress);
        if (drag) return;
        const w = walkerAt(event.clientX, event.clientY);
        const found = w ? null : buildingAt(event.clientX, event.clientY);
        hovered = found;
        renderer.domElement.style.cursor = w ? 'grab' : found ? 'pointer' : '';
        const rect = host.getBoundingClientRect();
        if (w || found) {
            const p = w ? person(w.personId) : null;
            const badge = found ? LENSES[lens].badge(found.data) : null;
            tooltip.textContent = w ? `${p?.name} — drag onto a building` : `${found.data.name}${badge ? ' — ' + badge : ''}`;
            tooltip.style.transform = `translate(${event.clientX - rect.left + 14}px, ${event.clientY - rect.top + 16}px)`;
            tooltip.classList.remove('hidden');
        } else {
            tooltip.classList.add('hidden');
        }
    });
    renderer.domElement.addEventListener('pointerleave', () => tooltip.classList.add('hidden'));
    renderer.domElement.addEventListener('pointerup', (event) => {
        clearTimeout(longPress);
        if (pending || drag) return; // a person press -- handled by the window listener
        // A drag to turn the camera is not a click on what it ends over.
        if (!downAt || Math.hypot(event.clientX - downAt[0], event.clientY - downAt[1]) > 5) return;
        const found = buildingAt(event.clientX, event.clientY);
        if (found) select(found.data.id);
        else closeCard();
    });
    renderer.domElement.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        const found = buildingAt(event.clientX, event.clientY);
        if (found) select(found.data.id);
    });

    let flyTo = null;
    gl = {
        canDrag: true,
        flyTo(id) {
            const at = positions.get(id);
            if (at) {
                controls.autoRotate = false;
                flyTo = at.clone();
            }
        },
        projectTop(id) {
            const at = positions.get(id);
            if (!at) return null;
            const v = new THREE.Vector3(at.x, (tops.get(id) ?? 3) + 2.4, at.z).project(camera);
            if (v.z > 1) return null; // behind the camera
            return { x: (v.x + 1) / 2 * host.clientWidth, y: (1 - v.y) / 2 * host.clientHeight };
        },
        dropTargetAt(clientX, clientY) {
            if (!rayFrom(clientX, clientY)) return null;
            const hits = raycaster.intersectObjects([tower, ...buildings.map((b) => b.mesh)]);
            if (!hits.length) return null;
            if (hits[0].object === tower) return { kind: 'tower' };
            const b = buildings.find((x) => x.mesh === hits[0].object);

            return b ? { kind: 'building', id: b.data.id } : null;
        },
        highlightDrop(target, tone) {
            dropTarget = target && tone ? target : null;
            dropRing.visible = !!dropTarget;
            if (!dropTarget) return;
            dropRing.material.color.setHex({ green: C.green, amber: C.amber, red: C.red }[tone]);
            const at = dropTarget.kind === 'tower' ? new THREE.Vector3() : positions.get(dropTarget.id);
            dropRing.position.set(at.x, 0.05, at.z);
        },
        dragEnded() {
            controls.enabled = true;
        },
    };

    // ——— Loop ———
    const timer = new THREE.Clock();
    let lastTick = 0;
    function frame() {
        const elapsed = timer.getElapsedTime();
        const dt = elapsed - lastTick;
        lastTick = elapsed;
        const anim = reduceMotion() ? 0 : elapsed;

        if (live) {
            t = now();
        } else if (playing) {
            // The whole day in about forty seconds.
            const span = Math.max(now() - snapshot.day.from, 30 * MIN);
            const next = t + (span / 40) * dt;
            if (next >= now()) {
                goLive();
            } else {
                t = next;
                slider.value = t;
                const shown = clockOf(t);
                if (clock.textContent !== shown) {
                    clock.textContent = shown;
                    renderTicker();
                }
            }
        }

        placeWalkers(t, anim);
        syncBillboards(t, elapsed);

        const freshClients = new Set(snapshot.day.events.filter((e) => fresh.has(e.id)).map((e) => e.client_id));
        buildings.forEach((b) => {
            const pulsing = b.glow || (live && freshClients.has(b.data.id));
            const base = pulsing ? 0.25 + Math.sin(anim * 3) * 0.15 : 0.06;
            const lit = b.data.id === selectedId || b === hovered || (dropTarget?.kind === 'building' && dropTarget.id === b.data.id);
            b.mesh.material.emissiveIntensity = lit ? 0.7 : base;
        });
        tower.material.emissiveIntensity = dropTarget?.kind === 'tower' ? 0.8 : 0.25;
        if (dropRing.visible) dropRing.scale.setScalar(1 + Math.sin(anim * 6) * 0.05);

        if (flyTo) {
            controls.target.lerp(flyTo, 0.08);
            if (controls.target.distanceTo(flyTo) < 0.05) flyTo = null;
        }
        controls.update();
        renderer.render(scene, camera);
        positionCard();
        requestAnimationFrame(frame);
    }

    new ResizeObserver(() => {
        const { clientWidth: w, clientHeight: h } = host;
        renderer.setSize(w, h, false);
        renderer.domElement.style.width = '100%';
        renderer.domElement.style.height = '100%';
        camera.aspect = w / Math.max(h, 1);
        camera.updateProjectionMatrix();
    }).observe(host);

    build();
    frame();
}

/* ——— Plain DOM: ticker and lists — work without WebGL ——————————————————— */

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
                        class="pointer-events-auto flex max-w-[240px] items-center gap-2 rounded-lg bg-black/65 px-2 py-1.5 text-left text-xs text-white ring-1 backdrop-blur hover:bg-black/80 ${fresh.has(e.id) ? 'animate-pulse ring-emerald-300' : 'ring-white/10'}">
                    ${e.photo ? `<img src="${esc(e.photo)}" alt="" class="h-8 w-8 shrink-0 rounded object-cover">` : `<span class="h-2 w-2 shrink-0 rounded-full" style="background:${hex(DOT[e.type])}"></span>`}
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">${VERB[e.type](e)}</span>
                        <span class="block truncate text-white/60">${esc(e.client ?? e.shoot)} · ${esc(e.time)}</span>
                    </span>
                </button>
            </li>`).join('')
        : '<li class="rounded-lg bg-black/50 px-3 py-1.5 text-xs text-white/60">Nothing from shoots yet today.</li>';
}

function renderListsIn(root, snapshot) {
    const t = snapshot.totals;
    const set = (key, value) => {
        const el = root.querySelector(`[data-stat="${key}"]`);
        if (el) el.textContent = value;
    };
    set('owed', money(t.owed));
    set('overdue', money(t.overdue));
    set('shoots_today', t.shoots_today);
    set('rolling', t.rolling);
    set('waiting_on_clients', t.waiting_on_clients);
    set('hours_month', Number(t.hours_month).toFixed(1));
    root.querySelector('[data-generated-at]').textContent = snapshot.generated_at;

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
                <span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background:${hex(STATE[b.state].color)}"></span>
                <span class="min-w-0 flex-1 truncate text-white">${esc(b.name)}</span>
                <span class="shrink-0 text-xs text-brand-100/60">${esc(STATE[b.state].label)}${b.owed ? ' · ' + money(b.owed) : ''}</span>
            </button>
        </li>`).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-studio-map]');
    if (root) boot(root);
});
