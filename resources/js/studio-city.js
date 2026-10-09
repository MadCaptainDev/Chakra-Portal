/*
 * The Studio Map's city: an isometric, SimCity-style region drawn on one
 * canvas. studio-map.js owns everything around it (panels, dialogs, the
 * bench, replay); this file owns what is on the ground.
 *
 * The plan comes from the server (App\Support\CityDistricts): towns in
 * slots round the studio's own, in the direction they really lie and
 * further out the further away they are. Each slot is a 12×12-tile square.
 *
 *  - Towns (a district with clients in it) have streets every six tiles;
 *    clients take every other lot near the centre, so buildings have room,
 *    and the rest is filler buildings, parks and gaps from a seeded random --
 *    the same town on every visit.
 *  - Suburbs ring the studio's own town: the same streets, thinner and lower.
 *  - Everything else is countryside -- fields, trees, the odd hut -- crossed
 *    by highways from the studio's town to each of the others. Cars use the
 *    streets and highways; crew walk them, never through anything.
 *
 * Light by design:
 *  - every building is drawn once into a sprite and stamped after that;
 *  - anything off screen is skipped;
 *  - the loop runs at 30 fps for a minute after you touch the map, 12 fps
 *    after that, and not at all when the tab is hidden, the map is scrolled
 *    out of view, or the system asks for reduced motion (then it draws only
 *    when something changes).
 */

const TW = 64; // tile width at zoom 1
const TH = 32; // tile height at zoom 1
const FLOOR = 11; // one storey, in pixels at zoom 1
const DISTRICT = 18; // tiles per slot side: three blocks of five, with streets between
const BLOCKS = [0, 6, 12]; // where each block's street sits, inside a slot
const LINES = [0, 6, 12, 18]; // the streets of a slot
const ROAD = 6; // a street every six tiles in town
const MIN_ZOOM = 0.12;
const MAX_ZOOM = 2.4;
const OPENING_ZOOM = 1.15; // the most the map opens at
const ACTIVE_FPS = 30;
const IDLE_FPS = 12;
const ACTIVE_FOR_MS = 60000;
const BILLBOARD_FOR_MS = 3 * 60 * 60 * 1000;

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
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
    grass: [[160, 196, 120], [30, 50, 40]],
    park: [[150, 196, 128], [36, 62, 48]],
    tree: [[86, 148, 82], [28, 52, 40]],
    dash: [[246, 241, 228], [120, 126, 134]],
    skyTop: [[180, 216, 232], [6, 12, 26]],
    skyBottom: [[232, 240, 232], [22, 34, 56]],
};
const tone = (key, dark) => mix(PALETTE[key][0], PALETTE[key][1], dark);
const FIELDS = [[146, 190, 96], [182, 204, 112], [216, 198, 128], [170, 140, 98], [120, 170, 90], [196, 212, 140]];
const FILLER = [0xb9b2a6, 0xa9b4bd, 0xc4b8a0, 0x9fa9a2, 0xbfb5b0];
const HUT = [0xd8b48a, 0xc9a27a, 0xe0c9a6];
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

/* ——— Paths along streets and highways ——— */
function pathOf(points) {
    const lengths = [0];
    for (let i = 1; i < points.length; i++) lengths.push(lengths[i - 1] + Math.abs(points[i].x - points[i - 1].x) + Math.abs(points[i].y - points[i - 1].y));
    return { points, lengths, total: lengths[lengths.length - 1] || 1 };
}
function along(path, k) {
    const want = clamp(k, 0, 1) * path.total;
    for (let i = 1; i < path.points.length; i++) {
        if (path.lengths[i] >= want) {
            const a = path.points[i - 1];
            const b = path.points[i];
            const seg = path.lengths[i] - path.lengths[i - 1] || 1;
            const f = (want - path.lengths[i - 1]) / seg;
            return { x: a.x + (b.x - a.x) * f, y: a.y + (b.y - a.y) * f, axis: a.y === b.y ? 'x' : 'y', dir: Math.sign((b.x - a.x) + (b.y - a.y)) || 1 };
        }
    }
    const p = path.points[path.points.length - 1];
    return { x: p.x, y: p.y, axis: 'x', dir: 1 };
}
// Right-angled: along x first, then y.
const elbow = (a, b) => [a, { x: b.x, y: a.y }, b];

export function createCity(options) {
    const {
        host, getSnapshot, getLens, lenses, getTime, getSelected, getFresh,
        whereAt, phaseColor, onPick, onBackground, onPersonDown, onView, firstName,
    } = options;

    const canvas = document.createElement('canvas');
    canvas.className = 'absolute inset-0 h-full w-full touch-none select-none';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', 'The studio and its clients, drawn as a city and the towns around it. Every client is also listed under the map.');
    host.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    let W = 0;
    let H = 0;
    let dpr = 1;
    const cam = { x: 0, y: 0, zoom: 1 };
    let flight = null;
    let skyMode = 'auto';
    let hovered = null;
    let dropTarget = null;
    let draggingPerson = null;
    let hits = [];
    let lastInteraction = performance.now();
    let visible = true;
    let needsRender = true;

    let city = null;
    let cars = [];
    let cityTops = new Map();
    let roadsKey = null;
    const images = new Map();
    const boardBorn = new Map();

    /* ——— The plan ——————————————————————————————————————————————— */

    function buildPlan() {
        const snap = getSnapshot();
        const bySlot = new Map(snap.districts.map((d) => [d.slot.join(','), d]));
        const home = snap.districts.find((d) => d.home) ?? { slot: [0, 0] };
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
        const centreOf = ([x, y]) => ({ x: x * DISTRICT + ROAD + 0.5, y: y * DISTRICT + ROAD + 0.5 });
        const homeCentre = centreOf(home.slot);

        // Highways: from the studio's town to every other, along x then y.
        const highways = snap.districts.filter((d) => !d.home).map((d) => ({ key: d.key, path: pathOf(elbow(homeCentre, centreOf(d.slot))) }));
        const onHighway = new Set();
        highways.forEach(({ path }) => {
            for (let i = 1; i < path.points.length; i++) {
                const a = path.points[i - 1];
                const b = path.points[i];
                const steps = Math.abs(b.x - a.x) + Math.abs(b.y - a.y);
                for (let s = 0; s <= steps; s++) {
                    const x = Math.floor(a.x + Math.sign(b.x - a.x) * s);
                    const y = Math.floor(a.y + Math.sign(b.y - a.y) * s);
                    onHighway.add(`${x},${y}`);
                }
            }
        });

        const cells = [];
        const objects = [];
        const byClient = new Map();
        const labels = [];
        let hq = null;

        for (let dx = minX; dx <= maxX; dx++) {
            for (let dy = minY; dy <= maxY; dy++) {
                const district = bySlot.get(`${dx},${dy}`) ?? null;
                const nearHome = Math.max(Math.abs(dx - home.slot[0]), Math.abs(dy - home.slot[1])) === 1;
                const type = district ? 'town' : nearHome ? 'suburb' : 'country';
                const ox = dx * DISTRICT;
                const oy = dy * DISTRICT;
                const rnd = random(hash(`${dx}:${dy}`));
                cells.push({ dx, dy, ox, oy, type, home: !!district?.home, seed: hash(`f${dx}:${dy}`) });

                if (type === 'country') {
                    for (let i = 0; i < DISTRICT; i++) {
                        for (let j = 0; j < DISTRICT; j++) {
                            const gx = ox + i;
                            const gy = oy + j;
                            if (onHighway.has(`${gx},${gy}`)) continue;
                            const r = rnd();
                            if (r < 0.1) objects.push({ kind: 'tree', gx, gy, w: 1, d: 1, size: 0.6 + rnd() * 0.6 });
                            else if (r < 0.125) objects.push({ kind: 'filler', gx, gy, w: 1, d: 1, floors: 1, colour: HUT[Math.floor(rnd() * HUT.length)] });
                        }
                    }
                    continue;
                }

                // Town: three blocks a side. Each block holds four 2x2 plots
                // with a one-tile garden cross between them, so every client
                // building stands on its own with space round it.
                const centre = { x: ox + DISTRICT / 2, y: oy + DISTRICT / 2 };
                const plots = [];
                for (const bi of BLOCKS) {
                    for (const bj of BLOCKS) {
                        for (const [pi, pj] of [[1, 1], [4, 1], [1, 4], [4, 4]]) {
                            const gx = ox + bi + pi;
                            const gy = oy + bj + pj;
                            plots.push({ gx, gy, central: bi === 6 && bj === 6, d: Math.hypot(gx + 1 - centre.x, gy + 1 - centre.y) });
                        }
                    }
                }
                plots.sort((a, b) => a.d - b.d || a.gx - b.gx || a.gy - b.gy);
                const used = new Set();

                if (district?.home) {
                    // Chakra fills the middle block, a plaza round a 3x3 tower.
                    hq = { kind: 'hq', gx: ox + 8, gy: oy + 8, w: 3, d: 3, floors: 10 };
                    objects.push(hq);
                    plots.filter((p) => p.central).forEach((p) => used.add(p));
                }

                if (district) {
                    labels.push({ name: district.name, km: district.km, gx: ox + 12.5, gy: oy + 12.5, home: district.home });
                    const residents = snap.buildings.filter((b) => b.district === district.key).sort((a, b) => a.id - b.id);
                    const open = plots.filter((p) => !used.has(p));
                    residents.forEach((b, k) => {
                        const plot = open[k];
                        if (!plot) return;
                        used.add(plot);
                        const o = { kind: 'client', id: b.id, gx: plot.gx, gy: plot.gy, w: 2, d: 2 };
                        objects.push(o);
                        byClient.set(b.id, o);
                    });
                }

                // Unused plots: small filler buildings, trees and parks. The
                // garden crosses stay green.
                plots.forEach((p) => {
                    if (used.has(p)) return;
                    for (const [a, b] of [[0, 0], [1, 0], [0, 1], [1, 1]]) {
                        const gx = p.gx + a;
                        const gy = p.gy + b;
                        const r = rnd();
                        const downtown = type === 'town' ? clamp(1 - p.d / 12, 0, 1) : 0;
                        const density = type === 'town' ? 0.5 : 0.3;
                        if (r < 0.1) objects.push({ kind: 'tree', gx, gy, w: 1, d: 1, size: 0.7 + rnd() * 0.5 });
                        else if (r < density) objects.push({ kind: 'filler', gx, gy, w: 1, d: 1, floors: 1 + Math.floor(rnd() * (type === 'town' ? 2 + downtown * 3 : 2)), colour: FILLER[Math.floor(rnd() * FILLER.length)] });
                    }
                });
                for (const bi of BLOCKS) {
                    for (const bj of BLOCKS) {
                        if (district?.home && bi === 6 && bj === 6) continue; // Chakra's plaza
                        for (let k = 1; k <= 5; k++) {
                            for (const [gx, gy] of [[ox + bi + 3, oy + bj + k], [ox + bi + k, oy + bj + 3]]) {
                                if (rnd() < 0.18) objects.push({ kind: 'tree', gx, gy, w: 1, d: 1, size: 0.55 + rnd() * 0.35 });
                            }
                        }
                    }
                }
            }
        }

        const bounds = { x0: minX * DISTRICT, y0: minY * DISTRICT, x1: (maxX + 1) * DISTRICT, y1: (maxY + 1) * DISTRICT };
        const planKey = `${minX},${minY},${maxX},${maxY}|${highways.map((h) => h.key).join(',')}`;
        city = { cells, objects, byClient, hq, labels, bounds, highways, homeSlot: home.slot, homeCentre };
        // Cars keep driving through a refresh; only new roads get new cars.
        if (planKey !== roadsKey) {
            roadsKey = planKey;
            cars = makeCars(planKey);
        }
        needsRender = true;
    }

    // Cars: one or two on each town's main streets, two on every highway.
    function makeCars(planKey) {
        const rnd = random(hash(`cars:${planKey}`));
        const list = [];
        const add = (path) => list.push({ path, k: rnd(), speed: (0.9 + rnd() * 1.3) / path.total, dir: rnd() < 0.5 ? 1 : -1, colour: CAR[Math.floor(rnd() * CAR.length)] });
        city.cells.forEach((c) => {
            if (c.type === 'country') return;
            const mid = ROAD + 0.5;
            if (rnd() < 0.8) add(pathOf([{ x: c.ox, y: c.oy + mid }, { x: c.ox + DISTRICT + 1, y: c.oy + mid }]));
            if (rnd() < 0.8) add(pathOf([{ x: c.ox + mid, y: c.oy }, { x: c.ox + mid, y: c.oy + DISTRICT + 1 }]));
        });
        city.highways.forEach(({ path }) => {
            add(path);
            add(path);
        });
        return list;
    }

    /* ——— Walking: along streets and highways ——— */

    // The middle of the street nearest a lot's front door.
    function door(o) {
        const gx = o.gx + o.w / 2;
        const gy = o.gy + o.d / 2;
        const options = [
            { x: Math.floor(gx / ROAD) * ROAD + 0.5, y: gy },
            { x: Math.ceil(gx / ROAD) * ROAD + 0.5, y: gy },
            { x: gx, y: Math.floor(gy / ROAD) * ROAD + 0.5 },
            { x: gx, y: Math.ceil(gy / ROAD) * ROAD + 0.5 },
        ];
        return options.reduce((best, p) => (Math.abs(p.x - gx) + Math.abs(p.y - gy) < Math.abs(best.x - gx) + Math.abs(best.y - gy) ? p : best));
    }
    const corner = (p) => ({ x: Math.round((p.x - 0.5) / ROAD) * ROAD + 0.5, y: Math.round((p.y - 0.5) / ROAD) * ROAD + 0.5 });
    const slotOf = (o) => [Math.floor(o.gx / DISTRICT), Math.floor(o.gy / DISTRICT)];

    // Door to corner, through town to its centre, down the highway, then the
    // same in reverse at the other end.
    function route(from, to) {
        const a = door(from);
        const b = door(to);
        const ia = corner(a);
        const ib = corner(b);
        const [tx, ty] = slotOf(to);
        const sameTown = tx === city.homeSlot[0] && ty === city.homeSlot[1];
        if (sameTown) return pathOf([a, ...elbow(ia, ib), b]);
        const hc = city.homeCentre;
        const tc = { x: tx * DISTRICT + ROAD + 0.5, y: ty * DISTRICT + ROAD + 0.5 };
        return pathOf([a, ...elbow(ia, hc), ...elbow(hc, tc).slice(1), ...elbow(tc, ib).slice(1), b]);
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
                pos = { x: city.hq.gx + 1 + Math.cos(a) * 1.7, y: city.hq.gy + 1 + Math.sin(a) * 1.7 };
            } else {
                const target = city.byClient.get(shoot.client_id);
                if (where.phase === 'site') {
                    const n = atSite.get(target.id) ?? 0;
                    atSite.set(target.id, n + 1);
                    const d = door(target);
                    const a = n * 1.9 + anim * 0.6;
                    pos = { x: d.x + Math.cos(a) * 0.32, y: d.y + Math.sin(a) * 0.32 };
                } else {
                    pos = along(route(city.hq, target), where.progress ?? 0.5 + Math.sin(anim * 0.25 + p.id) * 0.3);
                }
            }
            list.push({ person: p, phase: where.phase, shoot, x: pos.x, y: pos.y, axis: pos.axis ?? "x" });
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
        const ph = ((w + d) * TH) / 2 + h;
        const off = document.createElement('canvas');
        off.width = Math.ceil(pw * bucket) + 2;
        off.height = Math.ceil(ph * bucket) + 2;
        const g = off.getContext('2d');
        g.scale(bucket, bucket);
        const ax = (d * TW) / 2;
        const ay = h;
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
        const inset = (p, q, f) => ({ x: p.x + (q.x - p.x) * f, y: p.y + (q.y - p.y) * f });
        const c = { x: (up(T).x + up(B).x) / 2, y: (up(T).y + up(B).y) / 2 };
        poly([inset(up(T), c, 0.18), inset(up(R), c, 0.18), inset(up(B), c, 0.18), inset(up(L), c, 0.18)], css(shade(base, opts?.roof ?? 0.04)));

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
    const onScreen = (p, margin = 160) => p.x > -margin && p.x < W + margin && p.y > -margin * 1.5 && p.y < H + margin;

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
    const tileRect = (x0, y0, x1, y1, fill) => quad(groundAt(x0, y0), groundAt(x1, y0), groundAt(x1, y1), groundAt(x0, y1), fill);

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

    function cellOnScreen(c) {
        const m = groundAt(c.ox + DISTRICT / 2, c.oy + DISTRICT / 2);
        const r = (DISTRICT * TW * cam.zoom) / 1.4;
        return m.x > -r && m.x < W + r && m.y > -r && m.y < H + r;
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

        const b = city.bounds;
        tileRect(b.x0, b.y0, b.x1, b.y1, css(tone('grass', dark)));

        // Countryside first: a patchwork of fields with hedges between.
        const visibleCells = city.cells.filter(cellOnScreen);
        visibleCells.forEach((c) => {
            if (c.type !== 'country') return;
            const rnd = random(c.seed);
            for (const i of BLOCKS) for (const j of BLOCKS) {
                const split = rnd() < 0.5;
                const parts = split ? [[0, 0, 6, 3], [0, 3, 6, 6]] : [[0, 0, 3, 6], [3, 0, 6, 6]];
                parts.forEach(([a, b2, c2, d]) => {
                    const colour = atNight(FIELDS[Math.floor(rnd() * FIELDS.length)], dark);
                    tileRect(c.ox + i + a + 0.12, c.oy + j + b2 + 0.12, c.ox + i + c2 - 0.12, c.oy + j + d - 0.12, css(colour));
                });
            }
        });

        // Towns and suburbs: asphalt, with the blocks laid over it.
        visibleCells.forEach((c) => {
            if (c.type === 'country') return;
            tileRect(c.ox, c.oy, c.ox + DISTRICT + 1, c.oy + DISTRICT + 1, css(tone('asphalt', dark)));
            for (const i of BLOCKS) for (const j of BLOCKS) {
                tileRect(c.ox + i + 1, c.oy + j + 1, c.ox + i + ROAD, c.oy + j + ROAD, css(tone(c.home ? 'homePavement' : 'pavement', dark)));
                // The garden cross between each block's four plots.
                if (c.home && i === 6 && j === 6) continue;
                const green = css(tone('park', dark));
                tileRect(c.ox + i + 3, c.oy + j + 1, c.ox + i + 4, c.oy + j + ROAD, green);
                tileRect(c.ox + i + 1, c.oy + j + 3, c.ox + i + ROAD, c.oy + j + 4, green);
            }
        });

        // Highways across the country.
        const roadColour = css(tone('asphalt', dark));
        city.highways.forEach(({ path }) => {
            for (let i = 1; i < path.points.length; i++) {
                const p = path.points[i - 1];
                const q = path.points[i];
                tileRect(Math.min(p.x, q.x) - 0.5, Math.min(p.y, q.y) - 0.5, Math.max(p.x, q.x) + 0.5, Math.max(p.y, q.y) + 0.5, roadColour);
            }
        });

        // Lane markings and, after dark, street lamps.
        if (cam.zoom > 0.45) {
            ctx.save();
            ctx.strokeStyle = css(tone('dash', dark), 0.85);
            ctx.lineWidth = Math.max(1, 1.4 * cam.zoom);
            ctx.setLineDash([6 * cam.zoom, 8 * cam.zoom]);
            const line = (x0, y0, x1, y1) => {
                const p = groundAt(x0, y0);
                const q = groundAt(x1, y1);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                ctx.lineTo(q.x, q.y);
                ctx.stroke();
            };
            visibleCells.forEach((c) => {
                if (c.type === 'country') return;
                for (const k of LINES) {
                    line(c.ox + k + 0.5, c.oy, c.ox + k + 0.5, c.oy + DISTRICT + 1);
                    line(c.ox, c.oy + k + 0.5, c.ox + DISTRICT + 1, c.oy + k + 0.5);
                }
            });
            city.highways.forEach(({ path }) => {
                for (let i = 1; i < path.points.length; i++) line(path.points[i - 1].x, path.points[i - 1].y, path.points[i].x, path.points[i].y);
            });
            ctx.restore();
        }
        if (dark > 0.3) {
            const lamp = (x, y) => {
                const p = groundAt(x, y);
                if (!onScreen(p, 40)) return;
                const r = 24 * cam.zoom;
                const glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, r);
                glow.addColorStop(0, `rgba(255,214,120,${0.45 * dark})`);
                glow.addColorStop(1, 'rgba(255,214,120,0)');
                ctx.fillStyle = glow;
                ctx.fillRect(p.x - r, p.y - r, r * 2, r * 2);
            };
            visibleCells.forEach((c) => {
                if (c.type === 'country') return;
                for (const i of LINES) for (const j of LINES) lamp(c.ox + i + 0.5, c.oy + j + 0.5);
            });
        }

        // Town names on the ground, with how far they are.
        city.labels.forEach((l) => {
            const p = groundAt(l.gx + 2.6, l.gy + 2.6);
            if (!onScreen(p, 300)) return;
            const size = Math.max(10, Math.round((l.home ? 22 : 18) * cam.zoom));
            ctx.font = `800 ${size}px Poppins, ui-sans-serif, system-ui, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = dark > 0.5 ? 'rgba(255,255,255,0.28)' : 'rgba(19,42,56,0.34)';
            if (ctx.letterSpacing !== undefined) ctx.letterSpacing = `${3 * cam.zoom}px`;
            ctx.fillText(l.name.toUpperCase(), p.x, p.y);
            if (ctx.letterSpacing !== undefined) ctx.letterSpacing = '0px';
            if (!l.home && l.km != null) {
                ctx.font = `700 ${Math.max(9, Math.round(11 * cam.zoom))}px Poppins, ui-sans-serif, system-ui, sans-serif`;
                ctx.fillText(`${l.km} km from the studio`, p.x, p.y + size * 0.95);
            }
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
            if (o.kind !== 'park') return;
            const p = groundAt(o.gx, o.gy);
            if (onScreen(p)) tileRect(o.gx, o.gy, o.gx + 1, o.gy + 1, css(tone('park', dark)));
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
            if (o.kind === 'park') return;
            if (o.kind === 'filler' || o.kind === 'tree') {
                if (!onScreen(groundAt(o.gx, o.gy))) return;
            }
            things.push({ depth: o.gx + o.w + o.gy + o.d, o });
        });
        cars.forEach((car) => {
            const p = along(car.path, car.k);
            const dir = p.dir * car.dir;
            const lane = dir > 0 ? -0.2 : 0.2;
            const x = p.axis === 'x' ? p.x : p.x + lane;
            const y = p.axis === 'x' ? p.y + lane : p.y;
            if (onScreen(groundAt(x, y))) things.push({ depth: x + y + 0.4, car: { ...car, axis: p.axis, dir }, x, y });
        });
        // On the way: the crew of a shoot ride together, one van per shoot.
        const vans = new Map();
        const walking = people.filter((p) => p.phase === "walking" && p.shoot);
        walking.forEach((p) => {
            const van = vans.get(p.shoot.id) ?? { x: p.x, y: p.y, axis: p.axis, crew: [], shoot: p.shoot };
            van.crew.push(p.person);
            vans.set(p.shoot.id, van);
        });
        vans.forEach((van) => things.push({ depth: van.x + van.y + 0.5, van }));
        people.filter((p) => !walking.includes(p)).forEach((p) => things.push({ depth: p.x + p.y + 0.5, person: p }));
        things.sort((a, b2) => a.depth - b2.depth);

        const tops = new Map();
        things.forEach((t) => {
            if (t.car) return drawCar(t, dark);
            if (t.van) return drawVan(t.van, dark);
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
            ctx.globalAlpha = faded ? 0.55 : 1;
            ctx.drawImage(s.canvas, T.x - s.ax * cam.zoom, T.y - s.ay * cam.zoom, s.w * cam.zoom, s.h * cam.zoom);
            ctx.globalAlpha = 1;

            if (o.kind === 'client' || o.kind === 'hq') {
                const h = floors * FLOOR;
                const P = (gx, gy, z) => groundAt(gx, gy, z);
                const outline = [P(o.gx, o.gy, h), P(o.gx + o.w, o.gy, h), P(o.gx + o.w, o.gy, 0), P(o.gx + o.w, o.gy + o.d, 0), P(o.gx, o.gy + o.d, 0), P(o.gx, o.gy + o.d, h)];
                hits.push({ kind: o.kind === 'hq' ? 'hq' : 'client', id: o.kind === 'hq' ? 'hq' : o.id, poly: outline });
                const roofTop = P(o.gx + o.w / 2, o.gy + o.d / 2, h);
                tops.set(o.kind === 'hq' ? 'hq' : o.id, roofTop);
                if (o.kind === 'client' && L.gauge) drawTank(roofTop, L.gauge(bld), L.colour(bld, snap), dark);
                if (o.kind === 'hq') drawBeacon(roofTop, anim);
            }
        });

        // Labels over everything: names, badges, then photos.
        const far = cam.zoom < 0.6;
        const labelled = [];
        city.byClient.forEach((o, id) => {
            const bld = snap.buildings.find((x) => x.id === id);
            const top = tops.get(id);
            if (!bld || !top || !onScreen(top, 60)) return;
            const loud = L.loud(bld) || selected === id || hovered === id;
            if (far && !loud) return;
            labelled.push({ bld, top });
        });
        labelled.sort((a, b2) => a.top.y - b2.top.y).forEach(({ bld, top }) => {
            const name = bld.name.length > 24 ? bld.name.slice(0, 23) + '…' : bld.name;
            const r = pill(name, top.x, top.y - 8 * cam.zoom - (L.gauge?.(bld) != null ? 24 * cam.zoom : 0), { size: 11 });
            hits.push({ kind: 'client', id: bld.id, rect: r });
            const badge = L.badge(bld);
            if (badge) pill(badge, top.x, r.y - 3, { bg: css(rgb(L.colour(bld, snap))), fg: '#0b1a24', size: 10, weight: 800 });
        });
        if (tops.has('hq')) {
            const top = tops.get('hq');
            const r = pill('CHAKRA', top.x, top.y - 22 * cam.zoom, { bg: '#67bcd4', fg: '#132a38', size: 12, weight: 900 });
            hits.push({ kind: 'hq', id: 'hq', rect: r });
        }

        drawBillboards(snap, time, tops, now);

        if (cam.zoom > 0.6 || lens === 'crew') {
            people.filter((p) => !walking.includes(p)).forEach((p) => {
                const s = groundAt(p.x, p.y, 18);
                if (onScreen(s, 40)) pill(firstName(p.person.name) + (lens === 'crew' && p.person.hours_today ? ` · ${p.person.hours_today}h` : ''), s.x, s.y - 4, { size: 10, bg: 'rgba(0,0,0,0.7)', weight: 600 });
            });
        }

        cityTops = tops;
        onView?.();
    }

    function drawCar(t, dark) {
        const { car, x, y } = t;
        const [ax, ay] = car.axis === 'x' ? [0.42, 0.24] : [0.24, 0.42];
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


    // The crew van: Chakra blue, the crew's names over it, always readable.
    function drawVan(van, dark) {
        const axis = van.axis;
        const [ax, ay] = axis === 'x' ? [0.8, 0.42] : [0.42, 0.8];
        const gx = van.x - ax / 2;
        const gy = van.y - ay / 2;
        const h = 10;
        const z = Math.max(cam.zoom, 0.6);
        const body = atNight([103, 188, 212], dark * 0.4);
        const P = (a, b, zz) => groundAt(gx + a, gy + b, zz);
        quad(P(0, ay, 0), P(ax, ay, 0), P(ax, ay, h), P(0, ay, h), css(shade(body, -0.25)));
        quad(P(ax, ay, 0), P(ax, 0, 0), P(ax, 0, h), P(ax, ay, h), css(shade(body, -0.08)));
        quad(P(0, 0, h), P(ax, 0, h), P(ax, ay, h), P(0, ay, h), css(shade(body, 0.2)));
        const top = groundAt(van.x, van.y, h + 4);
        const names = van.crew.map((c) => firstName(c.name));
        const text = names.length > 2 ? `${names[0]}, ${names[1]} +${names.length - 2}` : names.join(', ');
        const r = pill(`🚐 ${text} · on the way`, top.x, top.y - 4, { size: 10, bg: 'rgba(56,189,248,0.95)', fg: '#0b1a24', weight: 700 });
        hits.push({ kind: 'person', id: van.crew[0].id, shoot: van.shoot, rect: r });
        hits.push({ kind: 'person', id: van.crew[0].id, shoot: van.shoot, circle: { x: top.x, y: top.y + 6 * z, r: 14 * z } });
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
        const z = Math.max(cam.zoom, 0.55); // stay findable when zoomed out
        ctx.globalAlpha = draggingPerson === p.person.id ? 0.35 : 1;
        ctx.beginPath();
        ctx.ellipse(s.x, s.y, 5 * z, 2.5 * z, 0, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,0,0,0.25)';
        ctx.fill();
        ctx.beginPath();
        ctx.roundRect(s.x - 4 * z, s.y - 14 * z, 8 * z, 11 * z, 3 * z);
        ctx.fillStyle = phaseColor(p.phase);
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

    function drawTank(top, fill, colour, dark) {
        if (fill == null) return;
        const z = cam.zoom;
        const r = 9 * z;
        const h = 18 * z;
        ctx.fillStyle = 'rgba(255,255,255,0.25)';
        ctx.fillRect(top.x - r, top.y - h, r * 2, h);
        ctx.fillStyle = css(atNight(rgb(colour), dark * 0.5));
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
            const top = tops.get(clientId);
            if (!onScreen(top, 120)) return;
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
            if (h.circle && Math.hypot(sx - h.circle.x, sy - h.circle.y) <= h.circle.r) return h;
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

    function fitTo(points, maxZoom, padding = 60) {
        if (!points.length || !W) return;
        const xs = points.map((p) => p.x);
        const ys = points.map((p) => p.y);
        const w = Math.max(...xs) - Math.min(...xs) || 1;
        const h = Math.max(...ys) - Math.min(...ys) || 1;
        cam.zoom = clamp(Math.min((W - padding * 2) / w, (H - padding * 2 - 110) / h), MIN_ZOOM, maxZoom);
        cam.x = (Math.max(...xs) + Math.min(...xs)) / 2;
        cam.y = (Math.max(...ys) + Math.min(...ys)) / 2 - 30 / cam.zoom;
        touched();
    }

    // Opens on the studio's own town with a ring of what is round it; the
    // button fits the whole region, out-of-town districts and all.
    function fit(everything = false) {
        if (!city) return;
        const b = city.bounds;
        if (everything) return fitTo([iso(b.x0, b.y0), iso(b.x1, b.y0), iso(b.x1, b.y1), iso(b.x0, b.y1, 120)], 1.2);
        // The studio's own clients and the tower, close enough that the
        // buildings read as buildings.
        const [hx, hy] = city.homeSlot;
        const pts = [];
        city.objects.forEach((o) => {
            if ((o.kind === 'client' || o.kind === 'hq') && Math.floor(o.gx / DISTRICT) === hx && Math.floor(o.gy / DISTRICT) === hy) {
                pts.push(iso(o.gx, o.gy, 150), iso(o.gx + o.w, o.gy + o.d), iso(o.gx + o.w, o.gy), iso(o.gx, o.gy + o.d));
            }
        });
        fitTo(pts, OPENING_ZOOM, 40);
    }

    /* ——— Pointer ——————————————————————————————————————————————— */

    const pointers = new Map();
    let pan = null;
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
        if (!reduceMotion()) {
            cars.forEach((car) => {
                car.k += car.dir * car.speed * dt;
                if (car.k > 1) car.k -= 1;
                if (car.k < 0) car.k += 1;
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
            flight = reduceMotion() ? null : { from: { x: cam.x, y: cam.y }, to, start: performance.now(), ms: 500 };
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
