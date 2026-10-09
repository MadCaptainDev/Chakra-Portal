/*
 * The Studio Map (studio-map/index.blade.php): the studio as a small 3D
 * town. Chakra is the tower in the middle; every client is a building
 * around it, the worst-off ones nearest. Height is hours logged this month,
 * colour is the building's state (App\Services\StudioMap::STATES), and the
 * crew on today's shoots walk out from the tower to them -- or circle the
 * building once the shoot is rolling.
 *
 * Read-only. Clicking a building opens a panel whose every action is a link
 * to the screen that owns it. The snapshot is re-fetched once a minute
 * while the tab is visible.
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
const BRAND = 0x67bcd4;
const SPACING = 7;
const REFRESH_MS = 60000;

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const money = (n) => '₹' + Math.round(n).toLocaleString('en-IN');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const hex = (n) => '#' + n.toString(16).padStart(6, '0');

/*
 * Slot i of a spiral of rings around the tower: ring 1 holds 6, ring 2
 * holds 12, and so on, each ring offset half a slot so streets don't line
 * up into one long avenue.
 */
function slot(i) {
    let ring = 1;
    let start = 0;
    while (i >= start + ring * 6) {
        start += ring * 6;
        ring += 1;
    }
    const count = ring * 6;
    const angle = ((i - start) / count) * Math.PI * 2 + (ring % 2 ? 0 : Math.PI / count);
    const r = ring * SPACING;

    return new THREE.Vector3(Math.cos(angle) * r, 0, Math.sin(angle) * r);
}

// A text sprite that always faces the camera.
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
    const r = canvas.height / 2;
    ctx.beginPath();
    ctx.roundRect(0, 0, canvas.width, canvas.height, r);
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

// Lit windows, tinted by each building's own colour.
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

function boot(root) {
    const host = root.querySelector('[data-map-canvas]');
    const tooltip = root.querySelector('[data-map-tooltip]');
    let snapshot = JSON.parse(root.querySelector('[data-studio-map-snapshot]').textContent);
    let selectedId = null;
    let onSelect = () => {}; // the camera fly-to, once there is a camera

    const renderLists = () => renderListsIn(root, snapshot);
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

    renderLists();

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
    scene.fog = new THREE.Fog(0x0b1a24, 60, 140);

    const camera = new THREE.PerspectiveCamera(38, 1, 0.5, 400);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.maxPolarAngle = 1.32;
    controls.minDistance = 10;
    controls.maxDistance = 120;
    controls.autoRotate = !reduceMotion();
    controls.autoRotateSpeed = 0.35;
    controls.addEventListener('start', () => { controls.autoRotate = false; });

    scene.add(new THREE.HemisphereLight(0xbfe6f3, 0x0b1a24, 0.9));
    const sun = new THREE.DirectionalLight(0xffffff, 1.6);
    sun.position.set(30, 50, 20);
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    Object.assign(sun.shadow.camera, { left: -60, right: 60, top: 60, bottom: -60, far: 150 });
    sun.shadow.camera.updateProjectionMatrix();
    scene.add(sun);

    const ground = new THREE.Mesh(
        new THREE.CircleGeometry(200, 64),
        new THREE.MeshStandardMaterial({ color: 0x10242f, roughness: 1 }),
    );
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    scene.add(ground);
    const grid = new THREE.GridHelper(200, 100, 0x1d3a49, 0x15303d);
    grid.position.y = 0.01;
    scene.add(grid);

    // The tower: always there, whatever the data says.
    const hq = new THREE.Group();
    const tower = new THREE.Mesh(
        new THREE.CylinderGeometry(1.6, 2.2, 9, 6),
        new THREE.MeshStandardMaterial({ color: BRAND, emissive: BRAND, emissiveIntensity: 0.25, roughness: 0.4, metalness: 0.2 }),
    );
    tower.position.y = 4.5;
    tower.castShadow = true;
    hq.add(tower);
    const hqLabel = label('CHAKRA', { bg: hex(BRAND), fg: '#132A38', size: 30, weight: 800 });
    hqLabel.position.y = 10.6;
    hq.add(hqLabel);
    scene.add(hq);

    let town = new THREE.Group();
    scene.add(town);
    let buildings = []; // { mesh, data, glow }
    let walkers = []; // { mesh, from, to, rolling, phase, centre }

    function build() {
        dispose(town);
        scene.remove(town);
        town = new THREE.Group();
        buildings = [];
        walkers = [];

        // Worst state nearest the tower; names break ties so the town
        // doesn't reshuffle on every refresh.
        const sorted = [...snapshot.buildings].sort((a, b) =>
            ORDER.indexOf(a.state) - ORDER.indexOf(b.state) || a.name.localeCompare(b.name));

        const positions = new Map();
        sorted.forEach((b, i) => {
            const at = slot(i);
            positions.set(b.id, at);

            const style = STATE[b.state] ?? STATE.calm;
            const height = 1.2 + Math.min(11, Math.sqrt(b.hours) * 0.9);
            const width = b.type === 'occasion' ? 2.2 : 3;

            const material = new THREE.MeshStandardMaterial({
                color: style.color,
                map: windowTexture(height / 1.4),
                emissive: style.color,
                emissiveIntensity: style.glow ? 0.35 : 0.06,
                roughness: 0.55,
                transparent: !b.active,
                opacity: b.active ? 1 : 0.55,
            });
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(width, height, width), material);
            mesh.position.set(at.x, height / 2, at.z);
            mesh.castShadow = true;
            mesh.receiveShadow = true;
            mesh.userData.id = b.id;
            town.add(mesh);

            const roof = new THREE.Mesh(
                new THREE.BoxGeometry(width + 0.3, 0.25, width + 0.3),
                new THREE.MeshStandardMaterial({ color: 0x1f2f38, roughness: 0.8 }),
            );
            roof.position.set(at.x, height + 0.12, at.z);
            town.add(roof);

            const tag = label(b.name.length > 22 ? b.name.slice(0, 21) + '…' : b.name, { size: 20 });
            tag.position.set(at.x, height + 1.1, at.z);
            town.add(tag);

            const badge = badgeFor(b);
            if (badge) {
                const sprite = label(badge, { bg: hex(style.color), fg: '#0b1a24', size: 18, weight: 800 });
                sprite.position.set(at.x, height + 2.1, at.z);
                town.add(sprite);
            }

            buildings.push({ mesh, data: b, glow: style.glow });

            // A lit street from the tower to anywhere a shoot is on today.
            if (b.state === 'rolling' || b.shoots_today.length) {
                const length = at.length();
                const road = new THREE.Mesh(
                    new THREE.PlaneGeometry(0.5, length),
                    new THREE.MeshBasicMaterial({ color: style.color, transparent: true, opacity: 0.35 }),
                );
                road.rotation.x = -Math.PI / 2;
                road.rotation.z = -Math.atan2(at.x, at.z);
                road.position.set(at.x / 2, 0.02, at.z / 2);
                town.add(road);
            }
        });

        // One walker per crew member per shoot.
        snapshot.crew.forEach((person, i) => {
            const to = positions.get(person.client_id);
            if (!to) return;

            const walker = new THREE.Group();
            const color = person.rolling ? STATE.rolling.color : STATE.shoot.color;
            const body = new THREE.Mesh(
                new THREE.CapsuleGeometry(0.28, 0.6, 4, 8),
                new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: 0.4 }),
            );
            body.position.y = 0.6;
            body.castShadow = true;
            walker.add(body);
            const name = label(person.name.split(' ')[0], { size: 14, bg: 'rgba(0,0,0,0.6)' });
            name.position.y = 1.7;
            walker.add(name);
            town.add(walker);

            walkers.push({ mesh: walker, to, onSite: person.on_site, phase: i * 0.9 });
        });

        scene.add(town);
        placeWalkers(0);
        fitCamera(sorted.length);
    }

    function badgeFor(b) {
        if (b.overdue_days > 0) return `₹ ${b.overdue_days}d overdue`;
        if (b.state === 'rolling') return '● REC';
        if (b.state === 'shoot') return 'SHOOT TODAY';
        const waiting = b.scripts_waiting + b.proposals_waiting;
        if (waiting) return `${waiting} WAITING`;

        return null;
    }

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

    function placeWalkers(t) {
        walkers.forEach((w) => {
            const s = t * 0.25 + w.phase;
            if (w.onSite) {
                // On location (reached or rolling): circling the building.
                const a = s * 1.4;
                w.mesh.position.set(w.to.x + Math.cos(a) * 2.6, 0, w.to.z + Math.sin(a) * 2.6);
            } else {
                // On the way: up and down the street to the shoot.
                const k = (Math.sin(s) + 1) / 2;
                const end = w.to.clone().multiplyScalar(1 - 2.2 / w.to.length());
                w.mesh.position.set(end.x * k, 0, end.z * k);
            }
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
        if (found !== hovered) {
            hovered = found;
            renderer.domElement.style.cursor = found ? 'pointer' : '';
        }
        if (found) {
            const rect = host.getBoundingClientRect();
            tooltip.textContent = `${found.data.name} — ${STATE[found.data.state].label}`;
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
        const entry = buildings.find((x) => x.data.id === id);
        if (entry) {
            controls.autoRotate = false;
            flyTo = entry.mesh.position.clone().setY(0);
        }
    };

    // ——— Loop ———
    const clock = new THREE.Clock();
    function frame() {
        const t = reduceMotion() ? 0 : clock.getElapsedTime();
        placeWalkers(t);
        buildings.forEach((b) => {
            const selected = b.data.id === selectedId;
            const base = b.glow ? 0.25 + Math.sin(t * 3) * 0.15 : 0.06;
            b.mesh.material.emissiveIntensity = selected || b === hovered ? 0.7 : base;
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
    startPolling(build);

    // ——— Shared with the no-WebGL path ———
    function startPolling(rebuild) {
        setInterval(async () => {
            if (document.hidden) return;
            try {
                const response = await fetch(root.dataset.endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) return;
                snapshot = await response.json();
            } catch {
                return; // Offline for a minute -- keep drawing the last one.
            }
            rebuild?.();
            renderLists();
            const still = snapshot.buildings.find((b) => b.id === selectedId);
            still ? openPanel(still) : closePanel();
        }, REFRESH_MS);
    }
}

// The panel, the resource strip and the two lists are plain DOM, filled
// from the snapshot -- outside boot()'s WebGL half so they work without it.
function openPanelIn(root, b) {
    const panel = root.querySelector('[data-map-panel]');
    const style = STATE[b.state] ?? STATE.calm;
    const row = (k, v) => `<div class="flex justify-between gap-3 py-1.5"><dt class="text-brand-100/60">${k}</dt><dd class="text-white font-medium text-right tabular-nums">${v}</dd></div>`;

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
            ${row('Owed', b.owed ? money(b.owed) : '—')}
            ${b.overdue_days ? row('Oldest overdue', `<span class="text-red-300">${b.overdue_days} days</span>`) : ''}
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

    const attention = root.querySelector('[data-attention-list]');
    attention.innerHTML = snapshot.attention.length
        ? snapshot.attention.map((a) => `
            <li class="flex gap-2.5">
                <span class="mt-1.5 w-2 h-2 shrink-0 rounded-full ${a.severity === 'urgent' ? 'bg-red-400' : 'bg-amber-400'}"></span>
                <div class="min-w-0">
                    ${a.url ? `<a href="${esc(a.url)}" class="font-medium text-white hover:underline">${esc(a.title)}</a>` : `<span class="font-medium text-white">${esc(a.title)}</span>`}
                    <p class="text-xs text-brand-100/60">${esc(a.detail)}</p>
                </div>
            </li>`).join('')
        : '<li class="text-brand-100/60">Nothing needs you right now.</li>';

    const sorted = [...snapshot.buildings].sort((a, b) =>
        ORDER.indexOf(a.state) - ORDER.indexOf(b.state) || a.name.localeCompare(b.name));
    const list = root.querySelector('[data-building-list]');
    list.innerHTML = sorted.map((b) => `
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
        if (!button) return;
        root.dispatchEvent(new CustomEvent('studio-map:select', { detail: Number(button.dataset.select) }));
        root.querySelector('[data-map-canvas]').scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'center' });
    });

    boot(root);
});
