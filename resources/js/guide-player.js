/*
 * The player behind the "how it works" walkthroughs (guides/_player.blade.php):
 * a phone-shaped 405×720 stage, a finger that taps and types, a caption,
 * chapters, and "Save as video".
 *
 * A guide hands in build(g) and gets back { blank, render, scenes }:
 *   blank()        the screen's starting state
 *   render(state)  draws the phone's screen into g.screen from that state
 *   scenes[]       { step, title, from(): state, async play(state) }
 * Each scene sets the state it starts from, so chapters can jump anywhere.
 * Every pause goes through g.wait(), which is what makes pause and jumping
 * work: a stale scene throws at its next wait and stops.
 */
const ABORT = Symbol('abort');

export function startGuide(root, build) {
    const $ = (sel) => root.querySelector(sel);
    const stage = $('[data-stage]');
    const fit = $('[data-fit]');
    const phone = $('[data-phone]');
    const screen = $('[data-screen]');
    const finger = $('[data-finger]');
    const cap = $('[data-cap]');
    const cover = $('[data-cover]');
    const end = $('[data-end]');
    const side = $('[data-side]');
    const shade = $('[data-shade]');
    const playBtn = $('[data-play]');
    const chaptersEl = $('[data-chapters]');
    const progress = $('[data-progress]');

    let run = 0;
    let paused = true;
    let scale = 1;
    let onFinished = null;
    let state = null;

    function resize() {
        scale = fit.clientWidth / 405;
        stage.style.transform = `scale(${scale})`;
        fit.style.height = `${720 * scale}px`;
    }
    window.addEventListener('resize', resize);
    resize();

    function wait(ms) {
        const mine = run;
        return new Promise((resolve, reject) => {
            let left = ms;
            let last = performance.now();
            const tick = (now) => {
                if (mine !== run) return reject(ABORT);
                if (!paused) left -= now - last;
                last = now;
                if (left <= 0) resolve();
                else requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        });
    }

    function point(el) {
        const st = stage.getBoundingClientRect();
        const r = el.getBoundingClientRect();
        return { x: (r.left + r.width / 2 - st.left) / scale, y: (r.top + r.height / 2 - st.top) / scale };
    }

    const g = {
        stage,
        screen,
        wait,
        draw() {
            guide.render(state);
        },
        /** Move the finger to an element ([data-t="name"], or any selector) and tap it. */
        async tap(sel, { pause = 450 } = {}) {
            const el = stage.querySelector(sel.startsWith('[') ? sel : `[data-t="${sel}"]`);
            if (!el) return;
            const { x, y } = point(el);
            finger.style.opacity = '1';
            finger.style.transform = `translate(${x}px, ${y}px)`;
            await wait(750);
            finger.classList.remove('press');
            void finger.offsetWidth;
            finger.classList.add('press');
            const ripple = document.createElement('div');
            ripple.className = 'eg-ripple';
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            stage.appendChild(ripple);
            setTimeout(() => ripple.remove(), 700);
            await wait(pause);
        },
        /** Type text one letter at a time; set(v) puts it in the state. */
        async type(text, set, speed = 75) {
            for (let i = 1; i <= text.length; i++) {
                set(text.slice(0, i));
                g.draw();
                await wait(speed);
            }
        },
        say(html) {
            cap.innerHTML = html;
            cap.classList.remove('swap');
            void cap.offsetWidth;
            cap.classList.add('swap');
        },
        async toast(text, ms = 1600) {
            const t = document.createElement('div');
            t.className = 'eg-toast';
            t.textContent = text;
            phone.appendChild(t);
            try {
                await wait(ms);
            } finally {
                t.remove();
            }
        },
        hideFinger() {
            finger.style.opacity = '0';
        },
        hideCover() {
            cover.classList.add('hide');
        },
        showEnd() {
            g.hideFinger();
            end.classList.remove('hide');
        },
        /** The app's sidebar: slide it in, tap the link, slide it out. */
        async openFromMenu(sel) {
            shade.classList.add('open');
            side.classList.add('open');
            await wait(900);
            await g.tap(sel);
            stage.querySelector(sel)?.classList.add('on');
            await wait(500);
            side.classList.remove('open');
            shade.classList.remove('open');
        },
    };

    const guide = build(g);
    const { scenes } = guide;

    chaptersEl.innerHTML = scenes.map((sc, i) => `<button type="button" class="eg-chap" data-chap="${i}">${i + 1}. ${sc.title}</button>`).join('');
    chaptersEl.addEventListener('click', (e) => {
        const b = e.target.closest('[data-chap]');
        if (b) start(Number(b.dataset.chap));
    });

    function showStep(i) {
        $('[data-stepno]').textContent = i + 1;
        $('[data-stepsub]').textContent = `Step ${i + 1} of ${scenes.length} · ${scenes[i].step}`;
        $('[data-steptitle]').textContent = scenes[i].title;
        progress.style.width = `${(i / (scenes.length - 1)) * 100}%`;
        chaptersEl.querySelectorAll('[data-chap]').forEach((b) => b.classList.toggle('on', Number(b.dataset.chap) === i));
    }

    function setPaused(p) {
        paused = p;
        playBtn.textContent = p ? '▶ Play' : '❚❚ Pause';
    }

    async function start(from = 0) {
        const mine = ++run;
        setPaused(false);
        cover.classList.toggle('hide', from > 0);
        end.classList.add('hide');
        side.classList.remove('open');
        shade.classList.remove('open');
        side.querySelectorAll('.on').forEach((el) => el.classList.remove('on'));
        phone.querySelectorAll('.eg-toast').forEach((el) => el.remove());
        g.hideFinger();
        try {
            for (let i = from; i < scenes.length; i++) {
                showStep(i);
                state = scenes[i].from();
                g.draw();
                await scenes[i].play(state);
            }
        } catch (e) {
            if (e !== ABORT) throw e;
            return;
        }
        if (mine === run) {
            progress.style.width = '100%';
            setPaused(true);
            playBtn.textContent = '↺ Play again';
            if (onFinished) onFinished();
        }
    }

    playBtn.addEventListener('click', () => {
        if (playBtn.textContent.includes('again') || run === 0) start(0);
        else setPaused(!paused);
    });
    $('[data-restart]').addEventListener('click', () => start(0));

    // First frame before anyone presses play.
    state = guide.blank();
    g.draw();
    showStep(0);

    // ——— Save as video ———
    const recordBtn = $('[data-record]');
    if (!navigator.mediaDevices?.getDisplayMedia || typeof MediaRecorder === 'undefined') {
        recordBtn.remove();
        $('[data-note]')?.remove();
        return;
    }
    recordBtn.addEventListener('click', async () => {
        let stream;
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: { frameRate: 30 },
                audio: false,
                preferCurrentTab: true,
                selfBrowserSurface: 'include',
            });
        } catch {
            return; // they said no
        }
        const [track] = stream.getVideoTracks();
        // Chrome can crop the capture to just the stage.
        try {
            if (window.CropTarget && track.cropTo) await track.cropTo(await window.CropTarget.fromElement(stage));
        } catch { /* whole tab, then */ }

        const type = ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm']
            .find((t) => MediaRecorder.isTypeSupported(t)) ?? '';
        const rec = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: 6_000_000 } : undefined);
        const chunks = [];
        rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
        rec.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            const blob = new Blob(chunks, { type: rec.mimeType || 'video/webm' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `${root.dataset.file || 'how-to'}.${(rec.mimeType || '').includes('mp4') ? 'mp4' : 'webm'}`;
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 10000);
            recordBtn.disabled = false;
            recordBtn.textContent = '⬇ Save as video';
        };
        track.addEventListener('ended', () => rec.state === 'recording' && rec.stop());

        recordBtn.disabled = true;
        recordBtn.textContent = '● Recording…';
        onFinished = () => {
            onFinished = null;
            setTimeout(() => rec.state === 'recording' && rec.stop(), 800);
        };
        await new Promise((r) => setTimeout(r, 600)); // let the share bar settle
        rec.start(1000);
        start(0);
    });
}
