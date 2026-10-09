/*
 * The Studio Map (studio-map/index.blade.php): the studio as a small 3D
 * town. Chakra is the tower in the middle; every client is a building
 * around it, the ones that need you nearest.
 *
 * Three things on top of the town:
 *
 *  - Lenses. One town, five questions -- Status (what needs you), Money
 *    (rupees per hour of work), Hours, Content (days of reels left) and Crew
 *    (where everybody is). A lens decides each building's colour, height,
 *    badge and the legend.
 *  - The live feed. Today's shoot events -- reached, rolling, each video,
 *    wrapped -- scroll along the bottom, and a video's photo goes up as a
 *    billboard over its client's building for a few hours.
 *  - Replay. The slider is the time of day. Everything that moves -- crew,
 *    billboards, the feed -- is worked out from the timeline for whatever
 *    time it says, so "live" is just the slider pinned to now.
 *
 * Read-only. Every action in the side panel is a link. The snapshot is
 * re-fetched every 30 seconds while the tab is visible.
 *
 * Its own Vite entry, loaded by that one page, so three.js never lands in
 * app.js for everybody else.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

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

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const money = (n) => '₹' + Math.round(n).toLocaleString('en-IN');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const hex = (n) => '#' + n.toString(16).padStart(6, '0');
const clockOf = (ms) => new Date(ms).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' });
const firstName = (name) => String(name).split(' ')[0];
const byStateThenName = (a, b) => ORDER.indexOf(a.state) - ORDER.indexOf(b.state) || a.name.localeCompare(b.name);

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
        note: 'Hours by a name: logged today',
    },
};

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

/* ——— The page ——————————————————————————————————————————————————————— */

function boot(root) {
    const host = root.querySelector('[data-map-canvas]');
    const tooltip = root.querySelector('[data-map-tooltip]');
    const slider = root.querySelector('[data-replay-slider]');
    const clock = root.querySelector('[data-replay-clock]');
    const liveButton = root.querySelector('[data-replay-live]');
    const playButton = root.querySelector('[data-replay-play]');

    let snapshot = JSON.parse(root.querySelector('[data-studio-map-snapshot]').textContent);
    let skew = snapshot.day.now - Date.now(); // server clock minus ours
    let lens = 'status';
    let selectedId = null;
    let live = true;
    let playing = false;
    let t = Date.now() + skew;
    const seen = new Set(snapshot.day.events.map((e) => e.id));
    let fresh = new Set(); // event ids that arrived on the latest refresh

    let onSelect = () => {};
    let onRebuild = () => {};

    const openPanel = (b) => openPanelIn(root, b);
    const closePanel = () => {
        selectedId = null;
        closePanelIn(root);
    };
    function select(id) {
        const b = snapshot.buildings.find((x) => x.id === id);
        if (!b) return closePanel();
        selectedId = id;
        openPanel(b);
        onSelect(id);
    }
    root.addEventListener('studio-map:select', (event) => select(event.detail));
    root.addEventListener('studio-map:close', () => { selectedId = null; });

    // ——— Lens switcher ———
    function renderLegend() {
        const L = LENSES[lens];
        root.querySelector('[data-legend]').innerHTML = L.legend(snapshot)
            .map(([c, text]) => `<div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background:${hex(c)}"></span>${esc(text)}</div>`)
            .join('') + `<div class="pt-1 text-brand-100/50">${esc(L.note)}</div>`;
    }
    root.querySelectorAll('[data-lens]').forEach((button) => button.addEventListener('click', () => {
        lens = button.dataset.lens;
        root.querySelectorAll('[data-lens]').forEach((b) => b.setAttribute('aria-selected', String(b === button)));
        renderLegend();
        onRebuild();
    }));

    // ——— Replay ———
    const now = () => Date.now() + skew;
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
    playButton.addEventListener('click', () => {
        if (playing) return stopPlaying();
        // From the top of the day, unless already part-way through a replay.
        if (live || t >= now() - MIN) scrubTo(snapshot.day.from);
        playing = true;
        playButton.textContent = '❚❚ Pause';
    });

    renderListsIn(root, snapshot);
    renderLegend();
    syncSlider();
    renderTicker();

    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({ antialias: true });
    } catch {
        root.querySelector('[data-map-fallback]').classList.replace('hidden', 'flex');
        startPolling();
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

    let town = new THREE.Group();
    let people = new THREE.Group();
    const boards = new THREE.Group(); // outside town: survives rebuilds
    scene.add(town, people, boards);

    let buildings = []; // { mesh, data, glow }
    let walkers = []; // { mesh, body, shoots, home, phase }
    const positions = new Map(); // client id -> Vector3
    const tops = new Map(); // client id -> height of whatever is on the roof
    const photoTextures = new Map(); // url -> Texture, kept for the page's life
    const billboards = new Map(); // client id -> { group, eventId, born, caption }

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
        const shootsFor = (name) => snapshot.day.shoots.filter((s) => s.crew.includes(name) && positions.has(s.client_id));
        const cast = lens === 'crew'
            ? snapshot.team.map((p) => ({ name: p.name, hours: p.hours_today, shoots: shootsFor(p.name) }))
            : [...new Set(snapshot.day.shoots.flatMap((s) => s.crew))]
                .map((name) => ({ name, hours: null, shoots: shootsFor(name) }))
                .filter((p) => p.shoots.length);

        cast.forEach((p, i) => {
            const walker = new THREE.Group();
            const body = new THREE.Mesh(
                new THREE.CapsuleGeometry(0.28, 0.6, 4, 8),
                new THREE.MeshStandardMaterial({ color: C.brand, emissive: C.brand, emissiveIntensity: 0.4 }),
            );
            body.position.y = 0.6;
            body.castShadow = true;
            walker.add(body);
            const tag = label(p.hours ? `${firstName(p.name)} · ${p.hours}h` : firstName(p.name), { size: 14, bg: 'rgba(0,0,0,0.6)' });
            tag.position.y = 1.7;
            walker.add(tag);
            people.add(walker);

            const angle = (i / Math.max(cast.length, 1)) * Math.PI * 2;
            walkers.push({ mesh: walker, body, shoots: p.shoots, home: new THREE.Vector3(Math.cos(angle) * 3.4, 0, Math.sin(angle) * 3.4), phase: i * 0.9 });
        });

        scene.add(town, people);
        // Billboards sit on roofs whose height may just have changed.
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
            // The first of their shoots that has them out of the studio.
            let where = { phase: 'home' };
            let to = null;
            for (const s of w.shoots) {
                const here = whereAt(s, time);
                if (here.phase !== 'home') {
                    where = here;
                    to = positions.get(s.client_id);
                    break;
                }
            }

            const show = where.phase !== 'home' || lens === 'crew';
            w.mesh.visible = show;
            if (!show) return;

            const colour = { site: C.green, walking: C.amber, home: C.brand }[where.phase];
            w.body.material.color.setHex(colour);
            w.body.material.emissive.setHex(colour);

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

    function makeBoard(event, clientId, anim) {
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

        const board = { group, eventId: event.id, born: anim, photo, caption };
        placeBoard(board, clientId);

        return board;
    }

    function dropBoard(board) {
        boards.remove(board.group);
        board.caption.material.map.dispose();
        board.caption.material.dispose();
        board.photo.material.dispose(); // the photo texture itself is cached
    }

    function syncBillboards(time, anim) {
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
            if (!billboards.has(clientId)) billboards.set(clientId, makeBoard(event, clientId, anim));
        });

        // Pop in with a small overshoot.
        billboards.forEach((board) => {
            const age = anim - board.born;
            const scale = reduceMotion() ? 1 : Math.min(1, age / 0.4) * (1 + Math.max(0, 0.15 - Math.max(0, age - 0.4) * 0.3));
            board.group.scale.setScalar(Math.max(scale, 0.001));
        });
    }

    // ——— Hover and click ———
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    let hovered = null;
    let downAt = null;

    function pick(event) {
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        const hit = raycaster.intersectObjects(buildings.map((b) => b.mesh))[0];

        return hit ? buildings.find((b) => b.mesh === hit.object) : null;
    }

    renderer.domElement.addEventListener('pointermove', (event) => {
        const found = pick(event);
        hovered = found;
        renderer.domElement.style.cursor = found ? 'pointer' : '';
        if (found) {
            const rect = host.getBoundingClientRect();
            const badge = LENSES[lens].badge(found.data);
            tooltip.textContent = `${found.data.name}${badge ? ' — ' + badge : ''}`;
            tooltip.style.transform = `translate(${event.clientX - rect.left + 4}px, ${event.clientY - rect.top + 12}px)`;
            tooltip.classList.remove('hidden');
        } else {
            tooltip.classList.add('hidden');
        }
    });
    renderer.domElement.addEventListener('pointerleave', () => tooltip.classList.add('hidden'));
    renderer.domElement.addEventListener('pointerdown', (event) => { downAt = [event.clientX, event.clientY]; });
    renderer.domElement.addEventListener('pointerup', (event) => {
        // A drag to turn the camera is not a click on what it ends over.
        if (!downAt || Math.hypot(event.clientX - downAt[0], event.clientY - downAt[1]) > 5) return;
        const found = pick(event);
        found ? select(found.data.id) : closePanel();
    });

    let flyTo = null;
    onSelect = (id) => {
        const at = positions.get(id);
        if (at) {
            controls.autoRotate = false;
            flyTo = at.clone();
        }
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
            b.mesh.material.emissiveIntensity = b.data.id === selectedId || b === hovered ? 0.7 : base;
        });
        if (flyTo) {
            controls.target.lerp(flyTo, 0.08);
            if (controls.target.distanceTo(flyTo) < 0.05) flyTo = null;
        }
        controls.update();
        renderer.render(scene, camera);
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
    startPolling();

    // Hoisted, so the no-WebGL path above can use it too.
    function startPolling() {
        setInterval(async () => {
            if (document.hidden) return;
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
            syncSlider();
            renderTicker();
            const still = snapshot.buildings.find((b) => b.id === selectedId);
            still ? openPanel(still) : closePanel();
        }, REFRESH_MS);
    }
}

/* ——— Plain DOM: ticker, panel, lists — all work without WebGL ——————————— */

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
                        class="pointer-events-auto flex items-center gap-2 rounded-lg bg-black/65 backdrop-blur px-2 py-1.5 text-left text-xs text-white ring-1 ${fresh.has(e.id) ? 'ring-emerald-300 animate-pulse' : 'ring-white/10'} hover:bg-black/80 max-w-[240px]">
                    ${e.photo ? `<img src="${esc(e.photo)}" alt="" class="h-8 w-8 shrink-0 rounded object-cover">` : `<span class="h-2 w-2 shrink-0 rounded-full" style="background:${hex(DOT[e.type])}"></span>`}
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">${VERB[e.type](e)}</span>
                        <span class="block truncate text-white/60">${esc(e.client ?? e.shoot)} · ${esc(e.time)}</span>
                    </span>
                </button>
            </li>`).join('')
        : '<li class="rounded-lg bg-black/50 px-3 py-1.5 text-xs text-white/60">Nothing from shoots yet today.</li>';
}

function openPanelIn(root, b) {
    const panel = root.querySelector('[data-map-panel]');
    const style = STATE[b.state] ?? STATE.calm;
    const row = (k, v) => `<div class="flex justify-between gap-3 py-1.5"><dt class="text-brand-100/60">${k}</dt><dd class="text-white font-medium text-right tabular-nums">${v}</dd></div>`;
    const content = b.content
        ? (b.content.days_left != null ? `${b.content.days_left} days (${b.content.remaining} reels)` : `${b.content.remaining} reels`)
        : '—';

    const shoots = b.shoots_today.map((s) => `
        <li class="rounded-lg bg-white/5 p-2.5">
            <a href="${esc(s.url)}" class="font-semibold text-white hover:underline">${esc(s.title)}</a>
            <p class="text-xs text-brand-100/60">${s.rolling ? '<span class="text-emerald-300 font-semibold">Rolling</span> · ' : (s.reached ? '<span class="text-amber-200 font-semibold">Reached ' + esc(s.reached) + '</span> · ' : '')}${esc(s.when ?? '')}${s.location ? ' · ' + esc(s.location) : ''}</p>
            <p class="text-xs text-brand-100/60">${s.crew.length ? 'Crew: ' + s.crew.map(esc).join(', ') : '<span class="text-red-300">Nobody crewed</span>'}</p>
        </li>`).join('');

    panel.innerHTML = `
        <div class="p-4 border-b border-white/10 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em]" style="color:${hex(style.color)}">${esc(style.label)}</p>
                <h3 class="mt-0.5 text-lg font-bold text-white leading-tight">${esc(b.name)}</h3>
                <p class="text-xs text-brand-100/50">${b.type === 'occasion' ? 'Occasion client' : 'Regular client'}${b.active ? '' : ' · inactive'}</p>
            </div>
            <button type="button" data-panel-close class="shrink-0 rounded-md p-1.5 text-brand-100/60 hover:text-white hover:bg-white/10" aria-label="Close">✕</button>
        </div>
        <dl class="px-4 py-2 text-sm divide-y divide-white/5">
            ${row('Hours this month', b.hours.toFixed(1))}
            ${row('Invoiced this month', b.invoiced_month ? money(b.invoiced_month) : '—')}
            ${row('Per hour of work', b.per_hour != null ? money(b.per_hour) : '—')}
            ${row('Owed', b.owed ? money(b.owed) : '—')}
            ${b.overdue_days ? row('Oldest overdue', `<span class="text-red-300">${b.overdue_days} days</span>`) : ''}
            ${row('Content left', content)}
            ${row('Scripts waiting on them', b.scripts_waiting || '—')}
            ${row('Proposals waiting on them', b.proposals_waiting || '—')}
            ${b.next_shoot ? row('Next shoot', `<a class="hover:underline" href="${esc(b.next_shoot.url)}">${esc(b.next_shoot.when)}</a>`) : ''}
        </dl>
        ${shoots ? `<div class="px-4 pb-2"><p class="text-xs font-semibold text-white mb-1.5">Today</p><ul class="space-y-2 text-sm">${shoots}</ul></div>` : ''}
        <div class="mt-auto p-4 flex gap-2">
            <a href="${esc(b.links.client)}" class="flex-1 text-center rounded-lg bg-brand-400 text-brand-900 text-sm font-semibold py-2 hover:bg-brand-300">Open client</a>
            <a href="${esc(b.links.invoices)}" class="flex-1 text-center rounded-lg bg-white/10 text-white text-sm font-semibold py-2 hover:bg-white/15">Invoices</a>
        </div>`;
    panel.classList.replace('hidden', 'flex');
    panel.querySelector('[data-panel-close]').addEventListener('click', () => {
        root.dispatchEvent(new CustomEvent('studio-map:close'));
        closePanelIn(root);
    });
}

function closePanelIn(root) {
    root.querySelector('[data-map-panel]').classList.replace('flex', 'hidden');
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
                <span class="mt-1.5 w-2 h-2 shrink-0 rounded-full ${a.severity === 'urgent' ? 'bg-red-400' : 'bg-amber-400'}"></span>
                <div class="min-w-0">
                    ${a.url ? `<a href="${esc(a.url)}" class="font-medium text-white hover:underline">${esc(a.title)}</a>` : `<span class="font-medium text-white">${esc(a.title)}</span>`}
                    <p class="text-xs text-brand-100/60">${esc(a.detail)}</p>
                </div>
            </li>`).join('')
        : '<li class="text-brand-100/60">Nothing needs you right now.</li>';

    root.querySelector('[data-building-list]').innerHTML = [...snapshot.buildings].sort(byStateThenName).map((b) => `
        <li>
            <button type="button" data-select="${b.id}" class="w-full flex items-center gap-3 py-2 text-left hover:bg-white/5 rounded-md px-1.5">
                <span class="w-2.5 h-2.5 shrink-0 rounded-sm" style="background:${hex(STATE[b.state].color)}"></span>
                <span class="flex-1 min-w-0 truncate text-white">${esc(b.name)}</span>
                <span class="shrink-0 text-xs text-brand-100/60">${esc(STATE[b.state].label)}${b.owed ? ' · ' + money(b.owed) : ''}</span>
            </button>
        </li>`).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-studio-map]');
    if (!root) return;

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-select]');
        if (!button || !button.dataset.select) return;
        root.dispatchEvent(new CustomEvent('studio-map:select', { detail: Number(button.dataset.select) }));
        root.querySelector('[data-map-canvas]').scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'center' });
    });

    boot(root);
});
