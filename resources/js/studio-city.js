/*
 * The Studio Map's city: an isometric, SimCity-style town drawn on one
 * canvas. studio-map.js owns everything around it (panels, dialogs, the
 * bench, replay); this file owns what is on the ground.
 *
 * The plan comes from the server (App\Support\CityDistricts): districts in
 * slots round the studio's own, in the direction they really lie. Each
 * district is a 12×12-tile square cut by streets every six tiles. Clients
 * take the lots nearest their district's centre; the rest of the lots get
 * filler buildings, parks and gaps from a seeded random -- the same city on
 * every visit -- and empty slots inside the city's bounds become suburbs, so
 * it always reads as a city rather than a few towers.
 *
 * Light by design:
 *  - every building is drawn once into a sprite and stamped after that;
 *  - the loop runs at 30 fps for a minute after you touch the map, 12 fps
 *    after that, and not at all when the tab is hidden, the map is scrolled
 *    out of view, or the system asks for reduced motion (then it draws only
 *    when something changes).
 */

const TW = 64; // tile width at zoom 1
const TH = 32; // tile height at zoom 1
const FLOOR = 11; // one storey, in pixels at zoom 1
const DISTRICT = 12; // tiles per district side
const ROAD = 6; // a street every six tiles
const MIN_ZOOM = 0.35;
const MAX_ZOOM = 2.6;
const ACTIVE_FPS = 30;
const IDLE_FPS = 12;
const ACTIVE_FOR_MS = 60000;
const BILLBOARD_FOR_MS = 3 * 60 * 60 * 1000;
const MIN = 60 * 1000;

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const mod = (n, m) => ((n % m) + m) % m;
const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

/* ——— Seeded randomness: the same city every visit ——— */
function hash(text) {
    let h = 2166136261;
    for (let i = 0; i < text.length; i++) h = Math.imul(h ^ text.charCodeAt(i), 16777619);
    return h >>> 0;
}
function random(seed) {
    let s = seed >>> 0;
    return () => {
        s = (s + 0x6d2b79f5) | 0;
        let t = Math.imul(s ^ (s >>> 15), 1 | s);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/* ——— Colour ——— */
const rgb = (n) => [(n >> 16) & 255, (n >> 8) & 255, n & 255];
const css = ([r, g, b], a = 1) => `rgba(${Math.round(r)},${Math.round(g)},${Math.round(b)},${a})`;
const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t);
const shade = (c, f) => (f >= 0 ? mix(c, [255, 255, 255], f) : mix(c, [0, 0, 0], -f));
const NIGHT_TINT = [20, 30, 52];
const atNight = (c, dark) => mix(c, mix(c, NIGHT_TINT, 0.72), dark);

const PALETTE = {
    asphalt: [[104, 112, 122], [34, 40, 50]],
    pavement: [[221, 216, 204], [52, 58, 68]],
    homePavement: [[230, 222, 200], [60, 62, 70]],
    park: [[150, 196, 128], [36, 62, 48]],
    tree: [[86, 148, 82], [28, 52, 40]],
    dash: [[246, 241, 228], [120, 126, 134]],
    skyTop: [[180, 216, 232], [6, 12, 26]],
    skyBottom: [[232, 240, 232], [22, 34, 56]],
};
const tone = (key, dark) => mix(PALETTE[key][0], PALETTE[key][1], dark);
const FILLER = [0xb9b2a6, 0xa9b4bd, 0xc4b8a0, 0x9fa9a2, 0xbfb5b0];
const CAR = [0xf87171, 0x60a5fa, 0xfbbf24, 0xf1f5f9, 0x34d399, 0x1f2937, 0xa78bfa];

/* ——— Isometric projection ——— */
const iso = (gx, gy, z = 0) => ({ x: ((gx - gy) * TW) / 2, y: ((gx + gy) * TH) / 2 - z });

/**
 * How dark it is at a given moment: 0 by day, 1 at night, with an hour of
 * dawn and dusk between. Follows the replay slider, so a replay sees the
 * lights come on in the evening.
 */
function darknessAt(ms) {
    const d = new Date(ms);
    const h = d.getHours() + d.getMinutes() / 60;
    if (h >= 7 && h < 17.75) return 0;
    if (h >= 19.25 || h < 5.5) return 1;
    if (h < 7) return 1 - (h - 5.5) / 1.5;
    return (h - 17.75) / 1.5;
}

export function createCity(options) {
    const {
        host, getSnapshot, getLens, lenses, getTime, getSelected, getFresh,
        whereAt, phaseColor, onPick, onBackground, onPersonDown, onView, initials, firstName,
    } = options;

    const canvas = document.createElement('canvas');
    canvas.className = 'absolute inset-0 h-full w-full touch-none select-none';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', 'The studio and its clients, drawn as a city. Every client is also listed under the map.');
    host.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    let W = 0;
    let H = 0;
    let dpr = 1;
    const cam = { x: 0, y: 0, zoom: 1 };
    let flight = null; // { from, to, start, ms }
    let skyMode = 'auto';
    let hovered = null;
    let dropTarget = null; // { kind, id, tone }
    let draggingPerson = null;
    let hits = []; // what is on screen, front-most last
    let lastInteraction = performance.now();
    let visible = true;
    let needsRender = true;

    let city = null; // the plan: districts, objects, roads, bounds
    let cars = [];
    const images = new Map(); // photo url -> Image
    const boardBorn = new Map(); // event id -> when it first showed

    /* ——— The plan ——————————————————————————————————————————————— */

    function buildPlan() {
        const snap = getSnapshot();
        const bySlot = new Map(snap.districts.map((d) => [d.slot.join(','), d]));
        let minX = -1;
        let maxX = 1;
        let minY = -1;
        let maxY = 1;
        snap.districts.forEach(({ slot: [x, y] }) => {
            minX = Math.min(minX, x);
            maxX = Math.max(maxX, x);
            minY = Math.min(minY, y);
            maxY = Math.max(maxY, y);
        });

        const objects = [];
        const lots = new Map(); // "gx,gy" -> object, for walkers to find a door
        const byClient = new Map();
        const labels = [];
        let hq = null;

        for (let dx = minX; dx <= maxX; dx++) {
            for (let dy = minY; dy <= maxY; dy++) {
                const district = bySlot.get(`${dx},${dy}`) ?? null;
                const ox = dx * DISTRICT;
                const oy = dy * DISTRICT;
                const cx = ox + ROAD;
                const cy = oy + ROAD;
                const rnd = random(hash(`${dx}:${dy}`));

                const free = [];
                for (let i = 1; i < DISTRICT; i++) {
                    for (let j = 1; j < DISTRICT; j++) {
                        if (i % ROAD === 0 || j % ROAD === 0) continue;
                        free.push({ gx: ox + i, gy: oy + j, d: Math.hypot(ox + i + 0.5 - cx, oy + j + 0.5 - cy) });
                    }
                }
                free.sort((a, b) => a.d - b.d || a.gx - b.gx || a.gy - b.gy);
                const taken = new Set();
                const take = (gx, gy) => taken.add(`${gx},${gy}`);

                if (district?.home) {
                    hq = { kind: 'hq', gx: cx + 1, gy: cy - 2, w: 2, d: 2, floors: 9 };
                    for (let a = 0; a < 2; a++) for (let b = 0; b < 2; b++) take(hq.gx + a, hq.gy + b);
                    objects.push(hq);
                }

                if (district) {
                    labels.push({ name: district.name, gx: cx + 0.5, gy: cy + 0.5, home: district.home });
                    const residents = snap.buildings.filter((b) => b.district === district.key).sort((a, b) => a.id - b.id);
                    const spots = free.filter((s) => !taken.has(`${s.gx},${s.gy}`));
                    residents.forEach((b, k) => {
                        const spot = spots[k];
                        if (!spot) return;
                        take(spot.gx, spot.gy);
                        const o = { kind: 'client', id: b.id, gx: spot.gx, gy: spot.gy, w: 1, d: 1 };
                        objects.push(o);
                        byClient.set(b.id, o);
                    });
                }

                // Filler: denser and taller downtown, thinner in the suburbs.
                free.forEach((s) => {
                    if (taken.has(`${s.gx},${s.gy}`)) return;
                    const r = rnd();
                    const downtown = district ? clamp(1 - s.d / 9, 0, 1) : 0;
                    if (r < 0.1) {
                        objects.push({ kind: 'tree', gx: s.gx, gy: s.gy, w: 1, d: 1, size: 0.7 + rnd() * 0.5 });
                    } else if (r < (district ? 0.68 : 0.42)) {
                        const floors = 1 + Math.floor(rnd() * (district ? 2 + downtown * 5 : 2));
                        objects.push({ kind: 'filler', gx: s.gx, gy: s.gy, w: 1, d: 1, floors, colour: FILLER[Math.floor(rnd() * FILLER.length)], seed: hash(`${s.gx},${s.gy}`) });
                    } else if (r < 0.78) {
                        objects.push({ kind: 'park', gx: s.gx, gy: s.gy, w: 1, d: 1 });
                    }
                });
            }
        }

        objects.forEach((o) => {
            for (let a = 0; a < o.w; a++) for (let b = 0; b < o.d; b++) lots.set(`${o.gx + a},${o.gy + b}`, o);
        });

        const bounds = { x0: minX * DISTRICT, y0: minY * DISTRICT, x1: (maxX + 1) * DISTRICT + 1, y1: (maxY + 1) * DISTRICT + 1 };
        const boundsKey = `${minX},${minY},${maxX},${maxY}`;
        if (!city || city.boundsKey !== boundsKey) cars = makeCars(bounds);

        city = { objects, byClient, hq, labels, bounds, boundsKey, homeDistrict: snap.districts.find((d) => d.home) };
        needsRender = true;
    }

    function makeCars(b) {
        const rnd = random(hash(`cars:${b.x0}:${b.y0}:${b.x1}:${b.y1}`));
        const list = [];
        for (let k = Math.ceil(b.x0 / ROAD); k * ROAD < b.x1; k++) {
            if (rnd() < 0.75) list.push({ axis: 'y', line: k * ROAD, dir: rnd() < 0.5 ? 1 : -1, p: b.y0 + rnd() * (b.y1 - b.y0), speed: 0.9 + rnd() * 1.4, colour: CAR[Math.floor(rnd() * CAR.length)] });
        }
        for (let k = Math.ceil(b.y0 / ROAD); k * ROAD < b.y1; k++) {
            if (rnd() < 0.75) list.push({ axis: 'x', line: k * ROAD, dir: rnd() < 0.5 ? 1 : -1, p: b.x0 + rnd() * (b.x1 - b.x0), speed: 0.9 + rnd() * 1.4, colour: CAR[Math.floor(rnd() * CAR.length)] });
        }
        return list;
    }

    /* ——— Walking: along the streets, never through buildings ——— */

    // The middle of the street nearest a lot's front door.
    function door(o) {
        const gx = o.gx + o.w / 2;
        const gy = o.gy + o.d / 2;
        const toX = gx - (Math.floor(gx / ROAD) * ROAD + 0.5);
        const toXRight = Math.ceil(gx / ROAD) * ROAD + 0.5 - gx;
        const toY = gy - (Math.floor(gy / ROAD) * ROAD + 0.5);
        const toYDown = Math.ceil(gy / ROAD) * ROAD + 0.5 - gy;
        const best = Math.min(toX, toXRight, toY, toYDown);
        if (best === toX) return { x: Math.floor(gx / ROAD) * ROAD + 0.5, y: gy };
        if (best === toXRight) return { x: Math.ceil(gx / ROAD) * ROAD + 0.5, y: gy };
        if (best === toY) return { x: gx, y: Math.floor(gy / ROAD) * ROAD + 0.5 };
        return { x: gx, y: Math.ceil(gy / ROAD) * ROAD + 0.5 };
    }
    const corner = (v) => Math.round((v - 0.5) / ROAD) * ROAD + 0.5;

    function route(from, to) {
        const a = door(from);
        const b = door(to);
        const ia = { x: corner(a.x), y: corner(a.y) };
        const ib = { x: corner(b.x), y: corner(b.y) };
        const points = [a, ia, { x: ib.x, y: ia.y }, ib, b];
        const lengths = [0];
        for (let i = 1; i < points.length; i++) lengths.push(lengths[i - 1] + Math.abs(points[i].x - points[i - 1].x) + Math.abs(points[i].y - points[i - 1].y));
        return { points, lengths, total: lengths[lengths.length - 1] || 1 };
    }

    function along(r, k) {
        const want = clamp(k, 0, 1) * r.total;
        for (let i = 1; i < r.points.length; i++) {
            if (r.lengths[i] >= want) {
                const seg = r.lengths[i] - r.lengths[i - 1] || 1;
                const f = (want - r.lengths[i - 1]) / seg;
                return { x: r.points[i - 1].x + (r.points[i].x - r.points[i - 1].x) * f, y: r.points[i - 1].y + (r.points[i].y - r.points[i - 1].y) * f };
            }
        }
        return r.points[r.points.length - 1];
    }

    function walkers(time, anim) {
        const snap = getSnapshot();
        const lens = getLens();
        if (!city?.hq) return [];
        const list = [];
        const atSite = new Map();
        let atHome = 0;

        snap.team.forEach((p) => {
            let where = { phase: 'home' };
            let shoot = null;
            for (const s of snap.day.shoots) {
                if (!s.crew_ids.includes(p.id) || !city.byClient.has(s.client_id)) continue;
                const here = whereAt(s, time);
                if (here.phase !== 'home') {
                    where = here;
                    shoot = s;
                    break;
                }
            }
            if (where.phase === 'home' && lens !== 'crew') return;

            let pos;
            if (where.phase === 'home') {
                const a = (atHome++ / Math.max(snap.team.length, 1)) * Math.PI * 2;
                const h = door(city.hq);
                pos = { x: h.x + Math.cos(a) * 0.9, y: h.y + Math.sin(a) * 0.9 };
            } else {
                const target = city.byClient.get(shoot.client_id);
                if (where.phase === 'site') {
                    const n = atSite.get(target.id) ?? 0;
                    atSite.set(target.id, n + 1);
                    const d = door(target);
                    const a = n * 1.9 + anim * 0.6;
                    pos = { x: d.x + Math.cos(a) * 0.32, y: d.y + Math.sin(a) * 0.32 };
                } else {
                    const r = route(city.hq, target);
                    pos = along(r, where.progress ?? 0.5 + Math.sin(anim * 0.25 + p.id) * 0.3);
                }
            }
            list.push({ person: p, phase: where.phase, shoot, x: pos.x, y: pos.y });
        });

        return list;
    }

    /* ——— Sprites ———————————————————————————————————————————————— */

    const sprites = new Map();
    let spriteScale = 0;

    function sprite(key, w, d, floors, colour, dark, opts) {
        const scale = cam.zoom * dpr;
        const bucket = 2 ** (Math.round(Math.log2(scale) * 2) / 2);
        if (bucket !== spriteScale) {
            sprites.clear();
            spriteScale = bucket;
        }
        const id = `${key}|${w}|${d}|${floors}|${colour}|${dark.toFixed(1)}`;
        let s = sprites.get(id);
        if (s) return s;

        const h = floors * FLOOR;
        const pw = ((w + d) * TW) / 2;
        const ph = ((w + d) * TH) / 2 + h + (opts?.crown ?? 0);
        const off = document.createElement('canvas');
        off.width = Math.ceil(pw * bucket) + 2;
        off.height = Math.ceil(ph * bucket) + 2;
        const g = off.getContext('2d');
        g.scale(bucket, bucket);
        // Footprint corners relative to the sprite, the top corner at (d·TW/2, h + crown).
        const ax = (d * TW) / 2;
        const ay = h + (opts?.crown ?? 0);
        const T = { x: ax, y: ay };
        const R = { x: ax + (w * TW) / 2, y: ay + (w * TH) / 2 };
        const B = { x: ax + ((w - d) * TW) / 2, y: ay + ((w + d) * TH) / 2 };
        const L = { x: ax - (d * TW) / 2, y: ay + (d * TH) / 2 };
        const up = (p) => ({ x: p.x, y: p.y - h });
        const base = atNight(rgb(colour), dark);
        const poly = (pts, fill) => {
            g.beginPath();
            pts.forEach((p, i) => (i ? g.lineTo(p.x, p.y) : g.moveTo(p.x, p.y)));
            g.closePath();
            g.fillStyle = fill;
            g.fill();
        };

        poly([L, B, up(B), up(L)], css(shade(base, -0.22)));
        poly([B, R, up(R), up(B)], css(shade(base, -0.06)));
        poly([up(T), up(R), up(B), up(L)], css(shade(base, 0.16)));
        // A parapet: the roof a little lower inside its rim.
        const inset = (p, q, f) => ({ x: p.x + (q.x - p.x) * f, y: p.y + (q.y - p.y) * f });
        const c = { x: (up(T).x + up(B).x) / 2, y: (up(T).y + up(B).y) / 2 };
        poly([inset(up(T), c, 0.18), inset(up(R), c, 0.18), inset(up(B), c, 0.18), inset(up(L), c, 0.18)], css(shade(base, opts?.roof ?? 0.04)));

        // Windows: a grid on both front faces; lit at night, glass by day.
        const rnd = random(hash(id));
        const face = (p0, p1, cols) => {
            for (let f = 0; f < floors; f++) {
                for (let k = 0; k < cols; k++) {
                    const u0 = (k + 0.28) / cols;
                    const u1 = (k + 0.72) / cols;
                    const v0 = f * FLOOR + 3;
                    const v1 = f * FLOOR + FLOOR - 3;
                    const at = (u, v) => ({ x: p0.x + (p1.x - p0.x) * u, y: p0.y + (p1.y - p0.y) * u - v });
                    const lit = rnd() < 0.6;
                    const day = css(shade(base, 0.35), 0.55);
                    const night = lit ? css([255, 214, 120], 0.95) : css(shade(base, -0.4), 0.9);
                    poly([at(u0, v0), at(u1, v0), at(u1, v1), at(u0, v1)], dark > 0.5 ? night : dark > 0 ? (lit ? css([255, 220, 150], 0.6) : day) : day);
                }
            }
        };
        face(L, B, w * 2);
        face(B, R, d * 2);

        s = { canvas: off, w: off.width / bucket, h: off.height / bucket, ax, ay };
        sprites.set(id, s);
        return s;
    }

    /* ——— Drawing ——————————————————————————————————————————————— */

    const toScreen = (p) => ({ x: (p.x - cam.x) * cam.zoom + W / 2, y: (p.y - cam.y) * cam.zoom + H / 2 });
    const fromScreen = (sx, sy) => ({ x: (sx - W / 2) / cam.zoom + cam.x, y: (sy - H / 2) / cam.zoom + cam.y });
    const groundAt = (gx, gy, z = 0) => toScreen(iso(gx, gy, z));

    function quad(a, b, c, d, fill) {
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.lineTo(c.x, c.y);
        ctx.lineTo(d.x, d.y);
        ctx.closePath();
        ctx.fillStyle = fill;
        ctx.fill();
    }

    function pill(text, x, y, { bg = 'rgba(11,26,36,0.88)', fg = '#fff', size = 11, weight = 700 } = {}) {
        ctx.font = `${weight} ${size}px Poppins, ui-sans-serif, system-ui, sans-serif`;
        const w = ctx.measureText(text).width + size * 1.1;
        const h = size * 1.75;
        ctx.beginPath();
        ctx.roundRect(x - w / 2, y - h, w, h, h / 2);
        ctx.fillStyle = bg;
        ctx.fill();
        ctx.fillStyle = fg;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, x, y - h / 2 + 0.5);
        return { x: x - w / 2, y: y - h, w, h };
    }

    function render(now = performance.now()) {
        if (!city || !W) return;
        needsRender = false;
        const snap = getSnapshot();
        const lens = getLens();
        const L = lenses[lens];
        const time = getTime();
        const dark = skyMode === 'day' ? 0 : skyMode === 'night' ? 1 : darknessAt(time);
        const darkBucket = Math.round(dark * 2) / 2;
        const anim = reduceMotion() ? 0 : now / 1000;
        const fresh = getFresh();
        const selected = getSelected();
        hits = [];

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        const sky = ctx.createLinearGradient(0, 0, 0, H);
        sky.addColorStop(0, css(tone('skyTop', dark)));
        sky.addColorStop(1, css(tone('skyBottom', dark)));
        ctx.fillStyle = sky;
        ctx.fillRect(0, 0, W, H);

        // Ground: the whole city in asphalt, then the blocks laid over it --
        // the streets are what shows between them.
        const b = city.bounds;
        quad(groundAt(b.x0, b.y0), groundAt(b.x1, b.y0), groundAt(b.x1, b.y1), groundAt(b.x0, b.y1), css(tone('asphalt', dark)));
        const homeSlot = city.homeDistrict?.slot ?? [0, 0];
        for (let bx = b.x0; bx < b.x1 - 1; bx += ROAD) {
            for (let by = b.y0; by < b.y1 - 1; by += ROAD) {
                const home = Math.floor(bx / DISTRICT) === homeSlot[0] && Math.floor(by / DISTRICT) === homeSlot[1];
                quad(groundAt(bx + 1, by + 1), groundAt(bx + ROAD, by + 1), groundAt(bx + ROAD, by + ROAD), groundAt(bx + 1, by + ROAD), css(tone(home ? 'homePavement' : 'pavement', dark)));
            }
        }

        // Lane markings, and street lamps once it is dark.
        if (cam.zoom > 0.55) {
            ctx.save();
            ctx.strokeStyle = css(tone('dash', dark), 0.85);
            ctx.lineWidth = Math.max(1, 1.4 * cam.zoom);
            ctx.setLineDash([6 * cam.zoom, 8 * cam.zoom]);
            for (let k = Math.ceil(b.x0 / ROAD) * ROAD; k < b.x1; k += ROAD) {
                const p = groundAt(k + 0.5, b.y0);
                const q = groundAt(k + 0.5, b.y1);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                ctx.lineTo(q.x, q.y);
                ctx.stroke();
            }
            for (let k = Math.ceil(b.y0 / ROAD) * ROAD; k < b.y1; k += ROAD) {
                const p = groundAt(b.x0, k + 0.5);
                const q = groundAt(b.x1, k + 0.5);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                ctx.lineTo(q.x, q.y);
                ctx.stroke();
            }
            ctx.restore();
        }
        if (dark > 0.3) {
            for (let x = Math.ceil(b.x0 / ROAD) * ROAD; x < b.x1; x += ROAD) {
                for (let y = Math.ceil(b.y0 / ROAD) * ROAD; y < b.y1; y += ROAD) {
                    const p = groundAt(x + 0.5, y + 0.5);
                    const glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 26 * cam.zoom);
                    glow.addColorStop(0, `rgba(255,214,120,${0.45 * dark})`);
                    glow.addColorStop(1, 'rgba(255,214,120,0)');
                    ctx.fillStyle = glow;
                    ctx.fillRect(p.x - 26 * cam.zoom, p.y - 26 * cam.zoom, 52 * cam.zoom, 52 * cam.zoom);
                }
            }
        }

        // District names, painted on the ground so buildings stand over them.
        city.labels.forEach((l) => {
            const p = groundAt(l.gx + 2.6, l.gy + 2.6);
            ctx.font = `800 ${Math.round((l.home ? 22 : 18) * cam.zoom)}px Poppins, ui-sans-serif, system-ui, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = dark > 0.5 ? 'rgba(255,255,255,0.22)' : 'rgba(19,42,56,0.28)';
            if (ctx.letterSpacing !== undefined) ctx.letterSpacing = `${3 * cam.zoom}px`;
            ctx.fillText(l.name.toUpperCase(), p.x, p.y);
            if (ctx.letterSpacing !== undefined) ctx.letterSpacing = '0px';
        });

        // Parks and the rings under buildings that need you.
        const ring = (o, colour, pulse = 0) => {
            const c = groundAt(o.gx + o.w / 2, o.gy + o.d / 2);
            const r = (0.9 + pulse) * o.w * TW * 0.5 * cam.zoom;
            ctx.beginPath();
            ctx.ellipse(c.x, c.y, r, r / 2, 0, 0, Math.PI * 2);
            ctx.strokeStyle = colour;
            ctx.lineWidth = 3 * cam.zoom;
            ctx.stroke();
        };
        city.objects.forEach((o) => {
            if (o.kind === 'park') quad(groundAt(o.gx, o.gy), groundAt(o.gx + 1, o.gy), groundAt(o.gx + 1, o.gy + 1), groundAt(o.gx, o.gy + 1), css(tone('park', dark)));
        });
        const freshClients = new Set(snap.day.events.filter((e) => fresh.has(e.id)).map((e) => e.client_id));
        city.byClient.forEach((o, id) => {
            const bld = snap.buildings.find((x) => x.id === id);
            if (!bld) return;
            const pulse = (anim * 0.7) % 1;
            if (L.glow(bld) || freshClients.has(id)) ring(o, css(rgb(L.colour(bld, snap)), 1 - pulse), pulse * 0.9);
            if (dropTarget?.kind === 'client' && dropTarget.id === id) ring(o, { green: '#34d399', amber: '#fbbf24' }[dropTarget.tone] ?? '#fff', 0.15);
            if (selected === id || hovered === id) ring(o, 'rgba(255,255,255,0.9)', 0.1);
        });
        if (city.hq && dropTarget?.kind === 'hq') ring(city.hq, '#f87171', 0.2);

        // Everything that stands up, back to front.
        const people = walkers(time, anim);
        const things = [];
        city.objects.forEach((o) => {
            if (o.kind !== 'park') things.push({ depth: o.gx + o.w + o.gy + o.d, o });
        });
        cars.forEach((car) => {
            const lane = car.dir > 0 ? 0.3 : 0.7;
            const x = car.axis === 'x' ? car.p : car.line + lane;
            const y = car.axis === 'x' ? car.line + lane : car.p;
            things.push({ depth: x + y + 0.4, car, x, y });
        });
        people.forEach((p) => things.push({ depth: p.x + p.y + 0.5, person: p }));
        things.sort((a, b2) => a.depth - b2.depth);

        const tops = new Map(); // id -> screen point above the roof
        things.forEach((t) => {
            if (t.car) return drawCar(t, dark);
            if (t.person) return drawPerson(t.person, dark, anim);
            const o = t.o;
            if (o.kind === 'tree') return drawTree(o, dark);

            let floors = o.floors;
            let colour = o.colour;
            let faded = false;
            let key = 'f';
            let bld = null;
            if (o.kind === 'client') {
                bld = snap.buildings.find((x) => x.id === o.id);
                if (!bld) return;
                floors = L.floors(bld);
                colour = L.colour(bld, snap);
                faded = !bld.active;
                key = 'c';
            } else if (o.kind === 'hq') {
                colour = 0x67bcd4;
                key = 'hq';
            } else if (lens === 'crew') {
                faded = true;
            }

            const s = sprite(key, o.w, o.d, floors, colour, darkBucket, o.kind === 'hq' ? { roof: 0.2 } : null);
            const T = groundAt(o.gx, o.gy);
            const x = T.x - s.ax * cam.zoom;
            const y = T.y - s.ay * cam.zoom;
            ctx.globalAlpha = faded ? (o.kind === 'filler' ? 0.55 : 0.6) : 1;
            ctx.drawImage(s.canvas, x, y, s.w * cam.zoom, s.h * cam.zoom);
            ctx.globalAlpha = 1;

            if (o.kind === 'client' || o.kind === 'hq') {
                const h = floors * FLOOR;
                const P = (gx, gy, z) => groundAt(gx, gy, z);
                const outline = [P(o.gx, o.gy, h), P(o.gx + o.w, o.gy, h), P(o.gx + o.w, o.gy, 0), P(o.gx + o.w, o.gy + o.d, 0), P(o.gx, o.gy + o.d, 0), P(o.gx, o.gy + o.d, h)];
                hits.push({ kind: o.kind === 'hq' ? 'hq' : 'client', id: o.kind === 'hq' ? 'hq' : o.id, poly: outline });
                const roofTop = P(o.gx + o.w / 2, o.gy + o.d / 2, h);
                tops.set(o.kind === 'hq' ? 'hq' : o.id, roofTop);
                if (o.kind === 'client' && L.gauge) drawTank(o, roofTop, L.gauge(bld), L.colour(bld, snap), dark);
                if (o.kind === 'hq') drawBeacon(roofTop, anim);
            }
        });

        // Labels over everything: names, badges, then photos.
        const far = cam.zoom < 0.75;
        const labelled = [];
        city.byClient.forEach((o, id) => {
            const bld = snap.buildings.find((x) => x.id === id);
            const top = tops.get(id);
            if (!bld || !top) return;
            const loud = L.loud(bld) || selected === id || hovered === id;
            if (far && !loud) return;
            labelled.push({ bld, top, loud });
        });
        labelled.sort((a, b2) => a.top.y - b2.top.y).forEach(({ bld, top }) => {
            const name = bld.name.length > 24 ? bld.name.slice(0, 23) + '…' : bld.name;
            const r = pill(name, top.x, top.y - 8 * cam.zoom - (L.gauge?.(bld) != null ? 24 * cam.zoom : 0), { size: 11 });
            hits.push({ kind: 'client', id: bld.id, rect: r });
            const badge = L.badge(bld);
            if (badge) {
                const c = rgb(L.colour(bld, snap));
                pill(badge, top.x, r.y - 3, { bg: css(c), fg: '#0b1a24', size: 10, weight: 800 });
            }
        });
        if (tops.has('hq')) {
            const top = tops.get('hq');
            const r = pill('CHAKRA', top.x, top.y - 22 * cam.zoom, { bg: '#67bcd4', fg: '#132a38', size: 12, weight: 900 });
            hits.push({ kind: 'hq', id: 'hq', rect: r });
        }

        drawBillboards(snap, time, tops, now);

        // People's names last, so they read over roofs.
        if (cam.zoom > 0.7 || lens === 'crew') {
            people.forEach((p) => {
                const s = groundAt(p.x, p.y, 18);
                pill(firstName(p.person.name) + (lens === 'crew' && p.person.hours_today ? ` · ${p.person.hours_today}h` : ''), s.x, s.y - 4, { size: 10, bg: 'rgba(0,0,0,0.7)', weight: 600 });
            });
        }

        cityTops = tops;
        onView?.();
    }

    let cityTops = new Map();

    function drawCar(t, dark) {
        const { car, x, y } = t;
        const len = 0.42;
        const wid = 0.24;
        const [ax, ay] = car.axis === 'x' ? [len, wid] : [wid, len];
        const gx = x - ax / 2;
        const gy = y - ay / 2;
        const h = 5;
        const body = atNight(rgb(car.colour), dark * 0.6);
        const P = (a, b, z) => groundAt(gx + a, gy + b, z);
        quad(P(0, ay, 0), P(ax, ay, 0), P(ax, ay, h), P(0, ay, h), css(shade(body, -0.25)));
        quad(P(ax, ay, 0), P(ax, 0, 0), P(ax, 0, h), P(ax, ay, h), css(shade(body, -0.1)));
        quad(P(0, 0, h), P(ax, 0, h), P(ax, ay, h), P(0, ay, h), css(shade(body, 0.12)));
        if (dark > 0.4) {
            const front = car.axis === 'x' ? (car.dir > 0 ? P(ax, ay / 2, 2) : P(0, ay / 2, 2)) : (car.dir > 0 ? P(ax / 2, ay, 2) : P(ax / 2, 0, 2));
            ctx.beginPath();
            ctx.arc(front.x, front.y, 2.2 * cam.zoom, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,240,180,0.95)';
            ctx.fill();
        }
    }

    function drawTree(o, dark) {
        const base = groundAt(o.gx + 0.5, o.gy + 0.5);
        const r = 13 * o.size * cam.zoom;
        ctx.fillStyle = css(atNight([110, 80, 60], dark));
        ctx.fillRect(base.x - 1.5 * cam.zoom, base.y - r, 3 * cam.zoom, r);
        const crown = tone('tree', dark);
        ctx.beginPath();
        ctx.arc(base.x, base.y - r * 1.25, r, 0, Math.PI * 2);
        ctx.fillStyle = css(crown);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(base.x - r * 0.3, base.y - r * 1.45, r * 0.5, 0, Math.PI * 2);
        ctx.fillStyle = css(shade(crown, 0.15));
        ctx.fill();
    }

    function drawPerson(p, dark, anim) {
        const bob = p.phase === 'walking' && anim ? Math.abs(Math.sin(anim * 8 + p.person.id)) * 2 : 0;
        const s = groundAt(p.x, p.y, bob);
        const z = cam.zoom;
        const colour = phaseColor(p.phase);
        const dim = draggingPerson === p.person.id ? 0.35 : 1;
        ctx.globalAlpha = dim;
        ctx.beginPath();
        ctx.ellipse(s.x, s.y, 5 * z, 2.5 * z, 0, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,0,0,0.25)';
        ctx.fill();
        ctx.beginPath();
        ctx.roundRect(s.x - 4 * z, s.y - 14 * z, 8 * z, 11 * z, 3 * z);
        ctx.fillStyle = colour;
        ctx.fill();
        ctx.lineWidth = 1.5 * z;
        ctx.strokeStyle = '#fff';
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(s.x, s.y - 17 * z, 3.6 * z, 0, Math.PI * 2);
        ctx.fillStyle = dark > 0.5 ? '#d9b89c' : '#c99a78';
        ctx.fill();
        ctx.stroke();
        ctx.globalAlpha = 1;
        hits.push({ kind: 'person', id: p.person.id, shoot: p.shoot, circle: { x: s.x, y: s.y - 10 * z, r: Math.max(12, 12 * z) } });
    }

    function drawTank(o, top, fill, colour, dark) {
        if (fill == null) return;
        const z = cam.zoom;
        const r = 9 * z;
        const h = 18 * z;
        const c = css(atNight(rgb(colour), dark * 0.5));
        ctx.fillStyle = 'rgba(255,255,255,0.25)';
        ctx.fillRect(top.x - r, top.y - h, r * 2, h);
        ctx.fillStyle = c;
        ctx.fillRect(top.x - r, top.y - h * fill, r * 2, h * fill);
        ctx.beginPath();
        ctx.ellipse(top.x, top.y - h, r, r / 2, 0, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.45)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,0.8)';
        ctx.lineWidth = 1;
        ctx.strokeRect(top.x - r, top.y - h, r * 2, h);
    }

    function drawBeacon(top, anim) {
        const z = cam.zoom;
        ctx.fillStyle = '#132a38';
        ctx.fillRect(top.x - 1.5 * z, top.y - 16 * z, 3 * z, 16 * z);
        const pulse = anim ? (Math.sin(anim * 3) + 1) / 2 : 1;
        ctx.beginPath();
        ctx.arc(top.x, top.y - 17 * z, (3 + pulse * 2) * z, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(103,188,212,${0.5 + pulse * 0.5})`;
        ctx.fill();
    }

    function drawBillboards(snap, time, tops, now) {
        const latest = new Map();
        for (const e of snap.day.events) {
            if (e.type === 'video' && e.photo && e.client_id && tops.has(e.client_id) && e.at <= time && time - e.at <= BILLBOARD_FOR_MS) latest.set(e.client_id, e);
        }
        latest.forEach((e, clientId) => {
            let img = images.get(e.photo);
            if (!img) {
                img = new Image();
                img.onload = () => { needsRender = true; };
                img.src = e.photo;
                images.set(e.photo, img);
            }
            if (!boardBorn.has(e.id)) boardBorn.set(e.id, now);
            const age = (now - boardBorn.get(e.id)) / 1000;
            const pop = reduceMotion() ? 1 : Math.min(1, age / 0.35) * (1 + Math.max(0, 0.12 - Math.max(0, age - 0.35) * 0.4));
            const top = tops.get(clientId);
            const w = 118 * pop;
            const ph = 74 * pop;
            const x = top.x - w / 2;
            const y = top.y - 54 * cam.zoom - ph - 22;
            ctx.save();
            ctx.shadowColor = 'rgba(0,0,0,0.35)';
            ctx.shadowBlur = 12;
            ctx.beginPath();
            ctx.roundRect(x, y, w, ph + 18 * pop, 10 * pop);
            ctx.fillStyle = '#0b1a24';
            ctx.fill();
            ctx.restore();
            if (img.complete && img.naturalWidth) {
                ctx.save();
                ctx.beginPath();
                ctx.roundRect(x + 2, y + 2, w - 4, ph - 2, 8 * pop);
                ctx.clip();
                const ratio = Math.max((w - 4) / img.naturalWidth, (ph - 2) / img.naturalHeight);
                ctx.drawImage(img, x + 2 + (w - 4 - img.naturalWidth * ratio) / 2, y + 2 + (ph - 2 - img.naturalHeight * ratio) / 2, img.naturalWidth * ratio, img.naturalHeight * ratio);
                ctx.restore();
            }
            if (pop > 0.8) {
                ctx.font = '600 10px Poppins, ui-sans-serif, system-ui, sans-serif';
                ctx.fillStyle = '#fff';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                const caption = `${e.title} · ${e.time}`;
                ctx.fillText(caption.length > 22 ? caption.slice(0, 21) + '…' : caption, top.x, y + ph + 9 * pop);
            }
            hits.push({ kind: 'client', id: clientId, rect: { x, y, w, h: ph + 18 * pop } });
        });
    }

    /* ——— Picking ——————————————————————————————————————————————— */

    function inPoly(pt, poly) {
        let inside = false;
        for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
            const a = poly[i];
            const b = poly[j];
            if ((a.y > pt.y) !== (b.y > pt.y) && pt.x < ((b.x - a.x) * (pt.y - a.y)) / (b.y - a.y) + a.x) inside = !inside;
        }
        return inside;
    }

    function pick(sx, sy, kinds = ['person', 'client', 'hq']) {
        for (let i = hits.length - 1; i >= 0; i--) {
            const h = hits[i];
            if (!kinds.includes(h.kind)) continue;
            if (h.circle && Math.hypot(sx - h.circle.x, sy - (h.circle.y)) <= h.circle.r) return h;
            if (h.rect && sx >= h.rect.x && sx <= h.rect.x + h.rect.w && sy >= h.rect.y && sy <= h.rect.y + h.rect.h) return h;
            if (h.poly && inPoly({ x: sx, y: sy }, h.poly)) return h;
        }
        return null;
    }

    const local = (event) => {
        const r = canvas.getBoundingClientRect();
        return { x: event.clientX - r.left, y: event.clientY - r.top };
    };

    /* ——— Camera ——————————————————————————————————————————————— */

    function touched() {
        lastInteraction = performance.now();
        needsRender = true;
    }

    function zoomAt(factor, sx, sy) {
        const before = fromScreen(sx, sy);
        cam.zoom = clamp(cam.zoom * factor, MIN_ZOOM, MAX_ZOOM);
        const after = fromScreen(sx, sy);
        cam.x += before.x - after.x;
        cam.y += before.y - after.y;
        flight = null;
        touched();
    }

    function fitTo(points, padding = 70) {
        if (!points.length || !W) return;
        const xs = points.map((p) => p.x);
        const ys = points.map((p) => p.y);
        const w = Math.max(...xs) - Math.min(...xs) || 1;
        const h = Math.max(...ys) - Math.min(...ys) || 1;
        cam.zoom = clamp(Math.min((W - padding * 2) / w, (H - padding * 2 - 120) / h), MIN_ZOOM, 1.4);
        cam.x = (Math.max(...xs) + Math.min(...xs)) / 2;
        cam.y = (Math.max(...ys) + Math.min(...ys)) / 2 - 30 / cam.zoom;
        touched();
    }

    // Opens on every district that has someone in it; the button fits the
    // whole city including its suburbs.
    function fit(everything = false) {
        if (!city) return;
        const b = city.bounds;
        if (everything) {
            return fitTo([iso(b.x0, b.y0), iso(b.x1, b.y0), iso(b.x1, b.y1), iso(b.x0, b.y1, 120)]);
        }
        const pts = [];
        city.objects.forEach((o) => {
            if (o.kind === 'client' || o.kind === 'hq') pts.push(iso(o.gx, o.gy, 140), iso(o.gx + o.w, o.gy + o.d));
        });
        fitTo(pts.length ? pts : [iso(b.x0, b.y0), iso(b.x1, b.y1)]);
    }

    /* ——— Pointer ——————————————————————————————————————————————— */

    const pointers = new Map();
    let pan = null; // { x, y, camX, camY, moved }
    let pinch = null;

    canvas.addEventListener('pointerdown', (event) => {
        touched();
        const p = local(event);
        pointers.set(event.pointerId, p);
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), zoom: cam.zoom };
            pan = null;
            return;
        }
        const person = event.button === 0 ? pick(p.x, p.y, ['person']) : null;
        if (person) {
            onPersonDown(person.id, person.shoot, event);
            return;
        }
        canvas.setPointerCapture(event.pointerId);
        pan = { x: p.x, y: p.y, camX: cam.x, camY: cam.y, moved: false };
        flight = null;
    });

    canvas.addEventListener('pointermove', (event) => {
        const p = local(event);
        if (pointers.has(event.pointerId)) pointers.set(event.pointerId, p);
        if (pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const factor = (pinch.zoom * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.d)) / cam.zoom;
            zoomAt(factor, (a.x + b.x) / 2, (a.y + b.y) / 2);
            return;
        }
        if (pan) {
            if (Math.hypot(p.x - pan.x, p.y - pan.y) > 4) pan.moved = true;
            if (pan.moved) {
                cam.x = pan.camX - (p.x - pan.x) / cam.zoom;
                cam.y = pan.camY - (p.y - pan.y) / cam.zoom;
                canvas.style.cursor = 'grabbing';
                touched();
            }
            return;
        }
        const over = pick(p.x, p.y);
        const id = over && over.kind !== 'person' ? over.id : null;
        canvas.style.cursor = over?.kind === 'person' ? 'grab' : over ? 'pointer' : 'default';
        if (id !== hovered) {
            hovered = id;
            needsRender = true;
        }
    });

    const release = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) pinch = null;
        if (!pan) return;
        const p = local(event);
        const wasClick = !pan.moved;
        pan = null;
        canvas.style.cursor = 'default';
        if (!wasClick || event.type === 'pointercancel') return;
        const hit = pick(p.x, p.y, ['client', 'hq']);
        hit ? onPick(hit.id) : onBackground();
    };
    canvas.addEventListener('pointerup', release);
    canvas.addEventListener('pointercancel', release);
    canvas.addEventListener('pointerleave', () => {
        if (hovered !== null) {
            hovered = null;
            needsRender = true;
        }
    });
    canvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        const p = local(event);
        zoomAt(Math.exp(-event.deltaY * 0.0015), p.x, p.y);
    }, { passive: false });
    canvas.addEventListener('dblclick', (event) => {
        const p = local(event);
        zoomAt(1.6, p.x, p.y);
    });
    canvas.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        const p = local(event);
        const hit = pick(p.x, p.y, ['client', 'hq']);
        if (hit) onPick(hit.id);
    });

    /* ——— Size, visibility, loop ——————————————————————————————————— */

    let firstFit = true;
    function resize() {
        const r = host.getBoundingClientRect();
        W = r.width;
        H = r.height;
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.round(W * dpr);
        canvas.height = Math.round(H * dpr);
        if (firstFit && W) {
            firstFit = false;
            fit();
        }
        needsRender = true;
        render();
    }
    new ResizeObserver(resize).observe(host);
    new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        if (visible) needsRender = true;
    }).observe(host);

    let lastFrame = 0;
    let lastTick = performance.now();
    function loop(now) {
        requestAnimationFrame(loop);
        if (!visible || document.hidden) {
            lastTick = now;
            return;
        }
        const still = reduceMotion();
        const fps = now - lastInteraction < ACTIVE_FOR_MS || flight ? ACTIVE_FPS : IDLE_FPS;
        if (now - lastFrame < 1000 / fps - 1) return;
        lastFrame = now;
        const dt = Math.min(0.1, (now - lastTick) / 1000);
        lastTick = now;

        if (flight) {
            const k = clamp((now - flight.start) / flight.ms, 0, 1);
            const e = 1 - (1 - k) ** 3;
            cam.x = flight.from.x + (flight.to.x - flight.from.x) * e;
            cam.y = flight.from.y + (flight.to.y - flight.from.y) * e;
            if (k >= 1) flight = null;
            needsRender = true;
        }
        if (!still) {
            const b = city?.bounds;
            cars.forEach((car) => {
                const [lo, hi] = car.axis === 'x' ? [b.x0, b.x1] : [b.y0, b.y1];
                car.p += car.dir * car.speed * dt;
                if (car.p > hi) car.p = lo;
                if (car.p < lo) car.p = hi;
            });
            needsRender = true;
        }
        if (needsRender) render(now);
    }

    buildPlan();
    resize();
    requestAnimationFrame(loop);

    /* ——— What studio-map.js asks of the city ———————————————————————— */

    return {
        flyTo(id) {
            const o = id === 'hq' ? city?.hq : city?.byClient.get(id);
            if (!o) return;
            const to = iso(o.gx + o.w / 2, o.gy + o.d / 2, 40);
            flight = reduceMotion() ? null : { from: { x: cam.x, y: cam.y }, to, start: performance.now(), ms: 450 };
            if (!flight) {
                cam.x = to.x;
                cam.y = to.y;
            }
            touched();
        },
        pointOf(id) {
            const top = cityTops.get(id);
            return top ? { x: top.x, y: top.y - (id === 'hq' ? 40 : 30) * cam.zoom - 14 } : null;
        },
        dropTargetAt(clientX, clientY) {
            const r = canvas.getBoundingClientRect();
            if (clientX < r.left || clientX > r.right || clientY < r.top || clientY > r.bottom) return null;
            const hit = pick(clientX - r.left, clientY - r.top, ['client', 'hq']);
            return hit ? (hit.kind === 'hq' ? { kind: 'hq' } : { kind: 'client', id: hit.id }) : null;
        },
        highlightDrop(target, toneName) {
            dropTarget = target && toneName ? { ...target, tone: toneName } : null;
            touched();
        },
        markDragging(personId, on) {
            draggingPerson = on ? personId : null;
            touched();
        },
        dragEnded() {
            needsRender = true;
        },
        redraw() {
            buildPlan();
            render();
        },
        refreshPins() {
            touched();
        },
        tick() {
            touched();
        },
        setSky(mode) {
            skyMode = mode;
            sprites.clear();
            touched();
        },
        fit() {
            fit(true);
        },
        zoomBy(factor) {
            zoomAt(factor, W / 2, H / 2);
        },
    };
}
