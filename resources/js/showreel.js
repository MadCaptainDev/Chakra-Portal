/*
 * The showreel film (resources/views/showreel.blade.php): fifteen seconds of
 * keyframed motion graphics over three parallax rows of real work, and then
 * a landing page whose sections move at their own speeds as it scrolls.
 *
 * No animation library -- a keyframe player this small is cheaper than one.
 * Every element is in the page already; this only moves it, using transforms
 * and opacity so the browser can hand the work to the GPU. Time is a number
 * that can be set (from a scroll position), not a chain of callbacks, so any moment of
 * the film can be drawn on its own.
 *
 * Three kinds of motion:
 *   keyframes  per element and property -- [[seconds, value, easing], ...]
 *   drift      the rows of reels, endlessly, faster while the scene arrives
 *   pointer    on a desktop, layers shift with the mouse by their depth
 *
 * Someone who asked their device for less motion gets the last frame, still.
 */

// The film: a 3.2s opening that plays by itself, then 13s played by scrolling.
export const DURATION = 16.2;

// How much later everything after the opening runs than it was first timed.
const SHIFT = 1.2;

const ease = {
    linear: (p) => p,
    inOut: (p) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2),
    in: (p) => p * p * p,
    out: (p) => 1 - Math.pow(1 - p, 3),
    outExpo: (p) => (p >= 1 ? 1 : 1 - Math.pow(2, -10 * p)),
    outBack: (p) => {
        const c1 = 1.70158;
        const c3 = c1 + 1;
        return 1 + c3 * Math.pow(p - 1, 3) + c1 * Math.pow(p - 1, 2);
    },
};

const POINTER_PX = 22; // how far a depth-1 layer moves with the mouse

// ------------------------------------------------------------------ keyframes

// "115%" → { n: 115, unit: '%' }; 40 → { n: 40, unit: 'px' }
function parseValue(value, defaultUnit) {
    if (typeof value === 'number') return { n: value, unit: defaultUnit };
    const match = /^(-?[\d.]+)([a-z%]*)$/.exec(value);
    return { n: parseFloat(match[1]), unit: match[2] || defaultUnit };
}

const UNITS = { x: 'px', y: 'px', scale: '', rotate: 'deg', opacity: '', tracking: 'em', blur: 'px' };

function compile(prop, frames) {
    return frames.map(([t, value, easing]) => ({ t, ...parseValue(value, UNITS[prop]), ease: easing || ease.inOut }));
}

// The value of one property at time t. Before the first key it holds the
// first value; after the last, the last -- so seeking anywhere is safe.
function sample(frames, t) {
    if (t <= frames[0].t) return frames[0];
    for (let i = 1; i < frames.length; i++) {
        const b = frames[i];
        if (t < b.t) {
            const a = frames[i - 1];
            const p = b.ease((t - a.t) / (b.t - a.t));
            return { n: a.n + (b.n - a.n) * p, unit: a.unit };
        }
    }
    return frames[frames.length - 1];
}

// ------------------------------------------------------------------ the film

export function initShowreel(stage) {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const finePointer = window.matchMedia?.('(pointer: fine)').matches;
    const one = (selector) => stage.querySelector(selector);
    const all = (selector) => Array.from(stage.querySelectorAll(selector));

    const tracks = [];
    const counters = [];

    // Added to every keyframe time from here on: 0 for the opening, SHIFT after.
    let shift = 0;

    function animate(el, props, { depth = 0, extra = null } = {}) {
        if (!el) return;
        const compiled = {};
        for (const [prop, frames] of Object.entries(props)) {
            compiled[prop] = compile(prop, frames.map(([time, ...rest]) => [time + shift, ...rest]));
        }
        const moves = ['x', 'y', 'scale', 'rotate'].some((p) => compiled[p]) || depth || extra;
        tracks.push({ el, props: compiled, depth, extra, moves });
    }

    // ---- 1 · the logo, in the dark, then the light -----------------------
    /*
     * A black, letterboxed opening. CHAKRA slams in from the top right and
     * PRODUCTIONS from the bottom left -- motion-blurred in flight, landing
     * hard: a damped shake, the whole logo jolting, a ring of light where
     * each lands. Lightning strikes and the black camera is backlit into
     * view. Then the bars open, the black lifts and the colour floods in
     * under a projector beam, a streak of light crosses the logo, and the
     * tagline settles. It holds there (INTRO) until the visitor scrolls.
     */
    // A damped shake after a landing at `at`: still until then (so nothing
    // drifts towards the first offset), then alternating offsets that halve.
    const shake = (at, amount, unit = 'vw') => [[at, '0' + unit, ease.linear], ...[0.05, 0.1, 0.15, 0.2, 0.26]
        .map((dt, i) => [at + dt, (i === 4 ? 0 : (i % 2 ? -1 : 1) * amount / Math.pow(2, i)) + unit, ease.linear])];
    const CHAKRA_LANDS = 0.75;
    const PRODUCTIONS_LANDS = 1.2;
    const STRIKE = 1.5;
    const COLOUR = 1.8; // the black starts to lift

    animate(one('[data-sr="blackout"]'), { opacity: [[0, 1], [COLOUR, 1], [COLOUR + 0.95, 0, ease.inOut]] });
    animate(one('[data-sr="bar-top"]'), { y: [[0, '0%'], [COLOUR, '0%'], [COLOUR + 1.0, '-100%', ease.inOut]] });
    animate(one('[data-sr="bar-bottom"]'), { y: [[0, '0%'], [COLOUR, '0%'], [COLOUR + 1.0, '100%', ease.inOut]] });

    animate(one('[data-sr="glow"]'), {
        opacity: [[COLOUR, 0], [COLOUR + 1.0, 1]],
        scale: [[COLOUR, 0.6], [COLOUR + 1.4, 1, ease.out]],
    }, { depth: -1.4, extra: (e) => ({ x: Math.sin(e * 0.21) * 40, y: Math.cos(e * 0.17) * 30 }) });

    for (const name of ['beam', 'dust']) {
        animate(one(`[data-sr="${name}"]`), { opacity: [[COLOUR + 0.1, 0], [COLOUR + 0.9, 1], [3.35, 1], [3.9, 0]] });
    }

    animate(one('[data-sr="logo-chakra"]'), {
        opacity: [[0.2, 0], [0.32, 1]],
        x: [[0.2, '75vw'], [CHAKRA_LANDS, '0vw', ease.out], ...shake(CHAKRA_LANDS, 1.6)],
        y: [[0.2, '-55vh'], [CHAKRA_LANDS, '0vh', ease.out], ...shake(CHAKRA_LANDS, 1.2, 'vh')],
        rotate: [[0.2, 16], [CHAKRA_LANDS, 0, ease.out], ...shake(CHAKRA_LANDS, 3, '')],
        blur: [[0.2, 14], [CHAKRA_LANDS, 0, ease.out]],
    });

    animate(one('[data-sr="logo-productions"]'), {
        opacity: [[0.6, 0], [0.72, 1]],
        x: [[0.6, '-75vw'], [PRODUCTIONS_LANDS, '0vw', ease.out], ...shake(PRODUCTIONS_LANDS, 1.6)],
        y: [[0.6, '55vh'], [PRODUCTIONS_LANDS, '0vh', ease.out], ...shake(PRODUCTIONS_LANDS, 1.2, 'vh')],
        rotate: [[0.6, -16], [PRODUCTIONS_LANDS, 0, ease.out], ...shake(PRODUCTIONS_LANDS, -3, '')],
        blur: [[0.6, 14], [PRODUCTIONS_LANDS, 0, ease.out]],
    });

    // A ring of light bursts out where each word lands.
    for (const [name, at] of [['chakra', CHAKRA_LANDS], ['productions', PRODUCTIONS_LANDS]]) {
        animate(one(`[data-sr="impact-${name}-ring"]`), {
            opacity: [[at - 0.01, 0], [at + 0.02, 0.9, ease.linear], [at + 0.55, 0, ease.out]],
            scale: [[at - 0.01, 0.2], [at + 0.55, 2.2, ease.out]],
        });
    }

    // The whole logo jolts on each impact, and pushes out when the film moves on.
    animate(one('[data-sr="logo"]'), {
        x: [[0, 0], ...shake(CHAKRA_LANDS, 7, 'px'), ...shake(PRODUCTIONS_LANDS, -9, 'px'), ...shake(STRIKE, 5, 'px')],
        y: [[0, 0], ...shake(CHAKRA_LANDS, 5, 'px'), ...shake(PRODUCTIONS_LANDS, 6, 'px'), ...shake(STRIKE, -4, 'px')],
        opacity: [[3.35, 1], [3.85, 0]],
        scale: [[3.35, 1], [3.9, 1.5, ease.in]],
    }, { depth: 0.5 });

    // Lightning: the bolt cracks twice, the stage flashes white with it.
    animate(one('[data-sr="bolt"]'), {
        opacity: [[STRIKE - 0.02, 0], [STRIKE, 1, ease.linear], [STRIKE + 0.08, 0, ease.linear], [STRIKE + 0.12, 0.9, ease.linear], [STRIKE + 0.24, 0, ease.linear]],
    });
    animate(one('[data-sr="flash"]'), {
        opacity: [[STRIKE - 0.02, 0], [STRIKE + 0.02, 0.75, ease.linear], [STRIKE + 0.1, 0.05, ease.linear], [STRIKE + 0.14, 0.45, ease.linear], [STRIKE + 0.45, 0, ease.out]],
    });

    // The black camera, backlit: it flickers into view against its own light.
    const flicker = [[STRIKE, 0], [STRIKE + 0.04, 1, ease.linear], [STRIKE + 0.1, 0.15, ease.linear], [STRIKE + 0.15, 1, ease.linear], [STRIKE + 0.22, 0.35, ease.linear], [STRIKE + 0.3, 1, ease.linear]];
    animate(one('[data-sr="camera"]'), {
        opacity: flicker,
        scale: [[STRIKE, 1.25], [STRIKE + 0.35, 1, ease.outBack]],
    });
    animate(one('[data-sr="camera-light"]'), {
        opacity: [[STRIKE, 0], [STRIKE + 0.06, 1, ease.linear], [STRIKE + 0.12, 0.3, ease.linear], [STRIKE + 0.2, 1, ease.linear], [COLOUR + 0.9, 0.75]],
        scale: [[STRIKE, 0.6], [STRIKE + 0.4, 1, ease.out]],
    });

    // A streak of light crosses the logo as the colour comes in.
    animate(one('[data-sr="streak"]'), {
        opacity: [[COLOUR + 0.2, 0], [COLOUR + 0.4, 1], [COLOUR + 0.75, 1], [COLOUR + 0.95, 0]],
        x: [[COLOUR + 0.2, '-55vw'], [COLOUR + 0.95, '55vw', ease.inOut]],
    });

    animate(one('[data-sr="tag"]'), {
        opacity: [[2.55, 0], [3.0, 1], [3.35, 1], [3.7, 0]],
        tracking: [[2.55, '1em'], [3.05, '0.35em', ease.out]],
    });

    // "Scroll to play": shown while the film waits at the end of its intro.
    animate(one('[data-sr="cue"]'), { opacity: [[2.75, 0], [3.2, 1], [3.45, 1], [3.85, 0]] });

    // Everything after the intro was timed for a 2-second opening; the
    // cinematic one runs 1.2s longer, so the rest of the film starts later.
    shift = SHIFT;

    // ---- 2 · the work ----------------------------------------------------
    animate(one('[data-sr="work"]'), {
        opacity: [[2.25, 0], [2.75, 1]],
        scale: [[2.25, 1.4], [4.3, 1, ease.out]],
    });

    animate(one('[data-sr="shade"]'), { opacity: [[2.7, 0], [3.1, 1], [5.9, 1], [6.4, 0]] });
    animate(one('[data-sr="kicker"]'), {
        opacity: [[2.9, 0], [3.3, 1], [5.85, 1], [6.15, 0]],
        y: [[2.9, 10], [3.3, 0, ease.out]],
    });
    all('[data-sr-word="work"]').forEach((el, i) => {
        const s = 3.0 + i * 0.11;
        const out = 5.8 + i * 0.04;
        animate(el, { y: [[s, '110%'], [s + 0.6, '0%', ease.outExpo], [out, '0%'], [out + 0.4, '-110%', ease.in]] });
    });

    // ---- 3 · the numbers ---------------------------------------------------
    animate(one('[data-sr="veil"]'), { opacity: [[0, 0], [6.1, 0], [6.6, 0.72], [12.4, 0.78], [12.9, 0.9]] });

    all('[data-sr-stat]').forEach((el, i) => {
        const s = 6.45 + i * 0.2;
        const out = 9.2 + i * 0.06;
        animate(el, {
            opacity: [[s, 0], [s + 0.45, 1], [out, 1], [out + 0.35, 0]],
            y: [[s, 50], [s + 0.7, 0, ease.outExpo], [out, 0], [out + 0.4, -36, ease.in]],
        });

        const number = el.querySelector('[data-sr-count]');
        if (number) counters.push(counter(number, s + 0.05 + shift, s + 1.5 + shift));
    });

    // ---- 4 · the clients ---------------------------------------------------
    animate(one('[data-sr="clients-head"]'), {
        opacity: [[9.6, 0], [10.0, 1], [12.3, 1], [12.65, 0]],
        y: [[9.6, 16], [10.0, 0, ease.out]],
    });

    const logos = all('[data-sr-logo]');
    const gap = Math.min(0.12, 1.2 / Math.max(1, logos.length));
    logos.forEach((el, i) => {
        const s = 9.8 + i * gap;
        const out = 12.2 + i * 0.025;
        animate(el, {
            opacity: [[s, 0], [s + 0.3, 1], [out, 1], [out + 0.4, 0]],
            scale: [[s, 0.5], [s + 0.65, 1, ease.outBack], [out, 1], [out + 0.4, 0.8, ease.in]],
            y: [[s, 70], [s + 0.65, 0, ease.outExpo]],
        }, { depth: parseFloat(el.dataset.depth) || 1 });
    });

    // ---- 5 · the ask -------------------------------------------------------
    animate(one('[data-sr="end-kicker"]'), { opacity: [[12.75, 0], [13.15, 1]] });
    all('[data-sr-word="end"]').forEach((el, i) => {
        const s = 12.85 + i * 0.12;
        animate(el, { y: [[s, '110%'], [s + 0.65, '0%', ease.outExpo]] });
    });
    animate(one('[data-sr="end-sub"]'), {
        opacity: [[13.55, 0], [14.0, 1]],
        y: [[13.55, 14], [14.0, 0, ease.out]],
    });
    const ctas = one('[data-sr="end-ctas"]');
    animate(ctas, {
        opacity: [[13.85, 0], [14.3, 1]],
        y: [[13.85, 16], [14.3, 0, ease.out]],
    });

    // ---- the rows ----------------------------------------------------------
    const rows = all('[data-sr-row]').map((el) => ({
        el,
        set: el.querySelector('[data-sr-set]'),
        speed: parseFloat(el.dataset.speed) || 40,
        dir: parseFloat(el.dataset.dir) || -1,
        depth: parseFloat(el.dataset.depth) || 1,
        width: 0,
        offset: Math.random() * 400,
    }));

    // Arriving fast, then cruising: a camera pulling back into the work.
    const rush = compile('scale', [[0, 5], [2.25 + SHIFT, 5], [4.3 + SHIFT, 1, ease.out]]);

    // The rows are tilted and wider than the stage, so each needs enough
    // copies of its covers to never show an end while it loops.
    function layoutRows() {
        const need = stage.offsetWidth * 2.4;
        for (const row of rows) {
            row.el.querySelectorAll('[data-sr-clone]').forEach((node) => node.remove());
            row.width = row.set.offsetWidth;
            if (!row.width) continue;
            for (let total = row.width; total < need + row.width; total += row.width) {
                const clone = row.set.cloneNode(true);
                clone.setAttribute('data-sr-clone', '');
                row.el.appendChild(clone);
            }
        }
    }

    // ---- drawing -------------------------------------------------------------
    const pointer = { x: 0, y: 0, tx: 0, ty: 0 };
    const progress = one('[data-sr-progress]');
    const scale = () => Math.max(0.55, stage.offsetWidth / 1000);

    function draw(t, elapsed) {
        for (const track of tracks) {
            const { el, props } = track;
            const get = (prop, fallback) => (props[prop] ? sample(props[prop], t) : fallback);

            if (props.opacity) el.style.opacity = get('opacity').n.toFixed(3);
            if (props.blur) {
                const b = get('blur').n;
                el.style.filter = b > 0.05 ? `blur(${b.toFixed(2)}px)` : '';
            }
            if (props.tracking) {
                const v = get('tracking');
                el.style.letterSpacing = v.n.toFixed(3) + v.unit;
                el.style.paddingLeft = v.n.toFixed(3) + v.unit;
            }

            if (track.moves) {
                const x = get('x', { n: 0, unit: 'px' });
                const y = get('y', { n: 0, unit: 'px' });
                const s = get('scale', { n: 1 }).n;
                const r = get('rotate', { n: 0 }).n;
                const extra = track.extra ? track.extra(elapsed) : { x: 0, y: 0 };
                const px = pointer.x * track.depth * POINTER_PX + extra.x;
                const py = pointer.y * track.depth * POINTER_PX + extra.y;
                el.style.transform = `translate3d(${x.n.toFixed(2)}${x.unit}, ${y.n.toFixed(2)}${y.unit}, 0) `
                    + `translate3d(${px.toFixed(2)}px, ${py.toFixed(2)}px, 0) rotate(${r.toFixed(2)}deg) scale(${s.toFixed(4)})`;
            }
        }

        for (const row of rows) {
            if (!row.width) continue;
            const loop = row.offset % row.width;
            const x = row.dir < 0 ? -loop : -row.width + loop;
            const px = pointer.x * row.depth * POINTER_PX * 1.6;
            const py = pointer.y * row.depth * POINTER_PX * 0.8;
            row.el.style.transform = `translate3d(${(x + px).toFixed(2)}px, ${py.toFixed(2)}px, 0)`;
        }

        counters.forEach((c) => c(t));

        if (progress) progress.style.transform = `scaleX(${(t / DURATION).toFixed(4)})`;
        if (ctas) ctas.style.pointerEvents = t >= 13.9 + SHIFT ? 'auto' : 'none';
    }

    // ---- the track: scrolling is the film's clock --------------------------
    /*
     * The stage is sticky inside a tall track. How far the track has been
     * scrolled through is how far the film has played: the intro (the name)
     * plays by itself and holds at INTRO, and the rest of the film is spread
     * over the scroll. Scrolling back rewinds it.
     */
    const INTRO = 3.2;
    const TRACK_SCREENS = 6.2;
    const track = stage.closest('[data-showreel-track]');
    const svh = window.CSS?.supports?.('height', '1svh');

    function sizeTrack() {
        if (!track) return;
        track.style.height = svh ? `${TRACK_SCREENS * 100}svh` : `${Math.round(window.innerHeight * TRACK_SCREENS)}px`;
    }

    function scrolled() {
        if (!track) return 0;
        const rect = track.getBoundingClientRect();
        const room = rect.height - stage.offsetHeight;
        return room > 0 ? Math.min(1, Math.max(0, -rect.top / room)) : 0;
    }

    // ---- the clock -----------------------------------------------------------
    let t = 0;
    let auto = 0;
    let elapsed = 0;
    let last = null;
    let raf = null;
    let visible = true;

    function tick(now) {
        const dt = last === null ? 0 : Math.min(0.05, (now - last) / 1000);
        last = now;
        elapsed += dt;

        auto = Math.min(INTRO, auto + dt);
        const p = scrolled();
        const target = Math.max(auto, p > 0 ? INTRO + p * (DURATION - INTRO) : 0);

        // Chased, not jumped to: a flick of the finger plays smoothly rather
        // than cutting, and the film settles where the scroll stops.
        t += (target - t) * Math.min(1, dt * 6);
        if (Math.abs(target - t) < 0.002) t = target;

        // The pointer is chased the same way, so the layers glide.
        pointer.x += (pointer.tx - pointer.x) * Math.min(1, dt * 4);
        pointer.y += (pointer.ty - pointer.y) * Math.min(1, dt * 4);

        const mult = sample(rush, t).n;
        const k = scale();
        for (const row of rows) row.offset += dt * row.speed * k * mult;

        draw(t, elapsed);
        raf = requestAnimationFrame(tick);
    }

    function run() {
        if (raf === null && visible && !document.hidden) {
            last = null;
            raf = requestAnimationFrame(tick);
        }
    }

    function pause() {
        if (raf !== null) cancelAnimationFrame(raf);
        raf = null;
    }

    // ---- start ---------------------------------------------------------------
    layoutRows();
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            layoutRows();
            if (!reduce && !svh) sizeTrack();
            if (reduce) draw(DURATION, elapsed);
        }, 150);
    });

    // Less motion asked for: no long track, just the last frame, still.
    if (reduce) {
        draw(DURATION, elapsed);
        stage.classList.add('sr-ready');
        return;
    }

    sizeTrack();

    if (finePointer) {
        stage.addEventListener('pointermove', (event) => {
            const rect = stage.getBoundingClientRect();
            pointer.tx = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
            pointer.ty = ((event.clientY - rect.top) / rect.height - 0.5) * 2;
        });
        stage.addEventListener('pointerleave', () => {
            pointer.tx = 0;
            pointer.ty = 0;
        });
    }

    // Only spend frames while the film is on screen and the tab is open.
    new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        visible ? run() : pause();
    }).observe(stage);
    document.addEventListener('visibilitychange', () => (document.hidden ? pause() : run()));

    // Start once the type and the front covers are ready -- or after 2.5s
    // regardless, so a slow image never holds the film back for good.
    const covers = all('[data-sr-set] img').slice(0, 10);
    const ready = Promise.all([
        document.fonts?.ready ?? Promise.resolve(),
        // The logo's face (home/_logo) is a web font; ask for it by name, as
        // fonts.ready can settle before the stylesheet has even asked.
        document.fonts?.load?.('400 1em Anton').catch(() => {}) ?? Promise.resolve(),
        ...covers.map((img) => (img.complete ? Promise.resolve() : new Promise((resolve) => {
            img.addEventListener('load', resolve, { once: true });
            img.addEventListener('error', resolve, { once: true });
        }))),
    ]);

    Promise.race([ready, new Promise((resolve) => setTimeout(resolve, 2500))]).then(() => {
        // Arriving part-way down (a refresh, a back button) starts the film
        // where the scroll already is, not from the top.
        const p = scrolled();
        t = p > 0 ? INTRO + p * (DURATION - INTRO) : 0;
        auto = p > 0 ? INTRO : 0;
        draw(t, 0);
        stage.classList.add('sr-ready');
        run();
    });
}
// A number that counts up from zero between two moments, keeping the shape
// of what the server wrote: "12.6M+" counts 0.0 → 12.6 and keeps "M+".
function counter(el, start, end) {
    const finalText = el.textContent.trim();
    const match = /^([\d.,]+)(.*)$/.exec(finalText);
    if (!match) return () => {};

    const digits = match[1];
    const suffix = match[2];
    const target = parseFloat(digits.replace(/,/g, ''));
    const decimals = digits.includes('.') ? digits.split('.')[1].length : 0;
    const grouped = digits.includes(',');
    const format = (n) => (grouped
        ? n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
        : n.toFixed(decimals));

    let shown = null;
    return (t) => {
        let text;
        if (t >= end) text = finalText;
        else if (t <= start) text = format(0) + suffix;
        else text = format(target * ease.out((t - start) / (end - start))) + suffix;

        if (text !== shown) {
            el.textContent = text;
            shown = text;
        }
    };
}

// ------------------------------------------------------------------ the page

/*
 * Scroll parallax for the landing page under the film.
 *
 * [data-parallax="N"] drifts vertically by up to N pixels either way as its
 * section crosses the screen: -N when the section enters at the bottom, +N
 * as it leaves at the top. Bounded on purpose -- a factor of the distance
 * grows with the section, and on a long work wall that pushed a column up
 * over its own heading. Phones get a gentler version (60%).
 * [data-parallax-lg] is the same, on wide screens only: on a phone those
 * elements sit in a sideways-swiping row, where moving them up and down
 * would only clip them.
 * [data-scroll-x="f"] slides sideways by f × how far its section has moved.
 *
 * Everything is measured from the parent, which does not move, so the
 * motion never feeds back into itself.
 */
export function initScrollParallax() {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

    const wide = window.matchMedia?.('(min-width: 1024px)');
    const vertical = [
        ...Array.from(document.querySelectorAll('[data-parallax]')).map((el) => ({ el, amount: parseFloat(el.dataset.parallax) || 0, wideOnly: false })),
        ...Array.from(document.querySelectorAll('[data-parallax-lg]')).map((el) => ({ el, amount: parseFloat(el.dataset.parallaxLg) || 0, wideOnly: true })),
    ].filter((item) => item.amount !== 0);
    const sideways = Array.from(document.querySelectorAll('[data-scroll-x]'))
        .map((el) => ({ el, factor: parseFloat(el.dataset.scrollX) || 0 }));

    if (!vertical.length && !sideways.length) return;

    let queued = false;

    function update() {
        queued = false;
        const vh = window.innerHeight;
        const isWide = wide ? wide.matches : true;
        const gentle = isWide ? 1 : 0.6;

        for (const { el, amount, wideOnly } of vertical) {
            if (wideOnly && !isWide) {
                el.style.transform = '';
                continue;
            }
            const rect = el.parentElement.getBoundingClientRect();
            if (rect.bottom < -vh || rect.top > vh * 2) continue;
            // 0 as the section's top enters at the bottom, 1 as its bottom leaves at the top.
            const p = Math.min(1, Math.max(0, (vh - rect.top) / (vh + rect.height)));
            const offset = (p - 0.5) * 2 * amount * gentle;
            el.style.transform = `translate3d(0, ${offset.toFixed(1)}px, 0)`;
        }

        for (const { el, factor } of sideways) {
            const rect = el.parentElement.getBoundingClientRect();
            if (rect.bottom < -vh || rect.top > vh * 2) continue;
            el.style.transform = `translate3d(${((rect.top - vh) * factor).toFixed(1)}px, 0, 0)`;
        }
    }

    const queue = () => {
        if (!queued) {
            queued = true;
            requestAnimationFrame(update);
        }
    };

    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue);
    update();
}

// ------------------------------------------------------------------ how we work

/*
 * The six steps (resources/views/home/_process.blade.php), played by scroll.
 *
 * The stage is pinned while its track scrolls past; the track's progress is
 * split evenly between the steps. Within a step, its own progress drives the
 * illustration -- each element says what it does (data-a) and when (data-at,
 * a fraction of the step) -- and the words cross-fade between steps. The
 * first 80% of a step's scroll plays it; the rest holds it finished, so a
 * step is never left half-drawn as the next one comes in.
 */
export function initProcess(section) {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const track = section.querySelector('[data-pr-track]');
    const stage = section.querySelector('[data-pr-stage]');
    const arts = Array.from(section.querySelectorAll('[data-pr-art]'));
    const texts = Array.from(section.querySelectorAll('[data-pr-text]'));
    const bars = Array.from(section.querySelectorAll('[data-pr-bar]'));
    const labels = Array.from(section.querySelectorAll('[data-pr-label]'));
    const nums = Array.from(section.querySelectorAll('[data-pr-num]'));
    const blobs = Array.from(section.querySelectorAll('[data-pr-blob]'));
    const count = arts.length;
    if (!track || !stage || !count) return;

    const SCREENS_PER_STEP = 0.75;
    const svh = window.CSS?.supports?.('height', '1svh');
    const sizeTrack = () => {
        const screens = 1 + count * SCREENS_PER_STEP;
        track.style.height = svh ? `${screens * 100}svh` : `${Math.round(window.innerHeight * screens)}px`;
    };

    // Every animated element, with its timing and what it needs to move.
    const items = arts.map((art) => Array.from(art.querySelectorAll('[data-a]')).map((el) => {
        const [from, to] = (el.dataset.at || '0,1').split(',').map(Number);
        const item = { el, type: el.dataset.a, from, to, dx: parseFloat(el.dataset.dx) || 0 };
        if (item.type === 'draw') el.style.strokeDasharray = '1';
        if (['pop', 'grow', 'scalex'].includes(item.type)) {
            el.style.transformBox = 'fill-box';
            el.style.transformOrigin = item.type === 'scalex' ? 'left center' : 'center';
        }
        return item;
    }));
    const pens = arts.map((art) => Array.from(art.querySelectorAll('[data-pen]')));

    const clamp = (v) => Math.min(1, Math.max(0, v));

    function apply({ el, type, from, to, dx }, local) {
        const q = clamp((local - from) / Math.max(0.0001, to - from));
        const e = ease.out(q);
        const s = el.style;
        switch (type) {
            case 'draw': s.strokeDashoffset = (1 - q).toFixed(4); break;
            case 'fade': s.opacity = q.toFixed(3); break;
            case 'pop': s.opacity = Math.min(1, q * 2).toFixed(3); s.transform = `scale(${(0.3 + 0.7 * ease.outBack(q)).toFixed(4)})`; break;
            case 'rise': s.opacity = q.toFixed(3); s.transform = `translateY(${((1 - e) * 28).toFixed(2)}px)`; break;
            case 'slide': s.opacity = q.toFixed(3); s.transform = `translateX(${((1 - e) * -60).toFixed(2)}px)`; break;
            case 'grow': s.transform = `scaleY(${Math.max(0.001, e).toFixed(4)})`; break;
            case 'scalex': s.transform = `scaleX(${Math.max(0.001, q).toFixed(4)})`; break;
            case 'move': s.transform = `translateX(${(ease.inOut(q) * dx).toFixed(2)}px)`; break;
            case 'clap': s.transform = `rotate(${(-32 * (1 - ease.in(q))).toFixed(2)}deg)`; break;
            case 'flash': s.opacity = (Math.sin(Math.PI * q) * 0.8).toFixed(3); break;
            case 'float': s.opacity = (q > 0 ? Math.sin(Math.PI * q) : 0).toFixed(3); s.transform = `translate(${(Math.sin(q * 6) * 10).toFixed(2)}px, ${(-q * 150).toFixed(2)}px)`; break;
            default: break;
        }
    }

    // The pen sits at the tip of whichever line is being written, and rests
    // at the end of the last one once the writing is done.
    function placePen(i, local) {
        const pen = arts[i].querySelector('[data-a="pen"]');
        const lines = pens[i];
        if (!pen || !lines.length) return;
        let target = lines[lines.length - 1];
        let q = 1;
        for (const line of lines) {
            const [from, to] = line.dataset.at.split(',').map(Number);
            if (local < to) {
                target = line;
                q = clamp((local - from) / (to - from));
                break;
            }
        }
        try {
            const length = target.getTotalLength();
            const point = target.getPointAtLength(length * q);
            pen.style.transform = `translate(${point.x.toFixed(2)}px, ${point.y.toFixed(2)}px)`;
            const writing = local > 0.18 && local < 0.82;
            pen.style.opacity = writing || local >= 0.82 ? '1' : '0';
        } catch (e) {
            pen.style.opacity = '0';
        }
    }

    let current = -1;

    function render(progress) {
        const f = progress * count;
        const index = Math.min(count - 1, Math.floor(f));

        arts.forEach((art, i) => {
            // Before its turn a step is unplayed, after it finished.
            const local = i < index ? 1 : i > index ? 0 : clamp((f - i) / 0.8);
            art.setAttribute('opacity', i === index ? '1' : '0');
            if (i === index || Math.abs(i - index) === 1) {
                items[i].forEach((item) => apply(item, reduce ? 1 : local));
                placePen(i, reduce ? 1 : local);
            }
            if (bars[i]) bars[i].style.transform = `scaleX(${i < index ? 1 : i > index ? 0 : clamp(f - i).toFixed(3)})`;
        });

        if (index !== current) {
            current = index;
            texts.forEach((text, i) => {
                text.classList.toggle('opacity-0', i !== index);
                text.classList.toggle('translate-y-4', i > index);
                text.classList.toggle('-translate-y-4', i < index);
            });
            labels.forEach((label, i) => {
                label.classList.toggle('text-white', i === index);
                label.classList.toggle('text-brand-100/40', i !== index);
            });
        }

        // Parallax: each step's giant number slides through as its step
        // passes, the lights drift at their own rates.
        if (!reduce) {
            nums.forEach((num, i) => {
                const d = f - (i + 0.5);
                num.style.opacity = Math.max(0, 1 - Math.abs(d) * 1.4).toFixed(3);
                num.style.transform = `translate3d(0, calc(-50% + ${(-d * 38).toFixed(2)}vh), 0)`;
            });
            blobs.forEach((blob) => {
                const k = parseFloat(blob.dataset.prBlob) || 0;
                blob.style.transform = `translate3d(${(progress * k * 30).toFixed(2)}vw, ${(progress * k * -25).toFixed(2)}vh, 0)`;
            });
        }
    }

    function progressNow() {
        const rect = track.getBoundingClientRect();
        const room = rect.height - stage.offsetHeight;
        return room > 0 ? clamp(-rect.top / room) : 0;
    }

    let queued = false;
    const queue = () => {
        if (!queued) {
            queued = true;
            requestAnimationFrame(() => {
                queued = false;
                render(progressNow());
            });
        }
    };

    sizeTrack();
    render(progressNow());
    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', () => {
        if (!svh) sizeTrack();
        queue();
    });
}
