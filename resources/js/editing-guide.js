/*
 * "How My Editing works" (my/editing-guide.blade.php): a self-playing,
 * phone-shaped walkthrough of the My Editing screen for editors.
 *
 * The phone is a mock drawn from a small state object (render()); each
 * scene sets the state it starts from, so chapters can be jumped to, then
 * scripts a finger tapping and typing through it. "Save as video" records
 * the stage with the browser's own screen capture (Chrome: share this tab)
 * and downloads an MP4 where the browser can make one, WebM otherwise.
 */
const root = document.querySelector('[data-editing-guide]');

if (root) {
    const $ = (sel) => root.querySelector(sel);
    const stage = $('[data-stage]');
    const fit = $('[data-fit]');
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

    const ABORT = Symbol('abort');
    let run = 0; // bumps on every jump; a stale scene throws ABORT at its next wait
    let paused = true;
    let current = 0;
    let scale = 1;
    let onFinished = null;

    // ——— Fit the 405×720 stage to the screen ———
    function resize() {
        scale = fit.clientWidth / 405;
        stage.style.transform = `scale(${scale})`;
        fit.style.height = `${720 * scale}px`;
    }
    window.addEventListener('resize', resize);
    resize();

    // ——— The phone's state, and drawing it ———
    const KINDS = { reel: 'Reel', post: 'Post', story: 'Story', none: 'Not posting' };
    let s;
    const fresh = new Set();

    function blank() {
        return { input: '', focus: false, drop: null, hi: -1, tab: 'todo', cards: [], fixing: null, fixText: '', toast: '', spin: false, ring: null };
    }

    const laneOf = (c) => c.posted ? 'posted' : c.lane;
    const esc = (t) => String(t).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));

    function cardHtml(c) {
        const open = c.fixes.filter((f) => !f.ok).length;
        const sub = [esc(c.client), c.publishes ? `publishes ${esc(c.publishes)}` : null,
            c.minutes != null ? `<span class="eg-timer">${c.minutes >= 60 ? `${Math.floor(c.minutes / 60)}h ${c.minutes % 60}m` : `${c.minutes}m`} worked</span>` : null]
            .filter(Boolean).join(' · ');
        let h = `<div class="eg-card" ${fresh.has(c.id) ? '' : 'style="animation:none"'}><div class="eg-ct">${esc(c.title)}</div><div class="eg-cs">${sub}</div>`;
        if (c.fixes.length) {
            h += '<div class="eg-fixes">' + c.fixes.map((f) =>
                `<div class="eg-fix ${f.ok ? 'ok' : ''}"><span class="eg-box" data-t="box-${f.id}">${f.ok ? '✓' : ''}</span>${esc(f.text)}</div>`).join('') + '</div>';
        }
        if (s.fixing === c.id) {
            h += `<div class="eg-fixin"><div class="eg-input focus">${s.fixText ? esc(s.fixText) : '<span class="ph">What needs fixing?</span>'}<span class="eg-caret"></span></div>
                  <div class="eg-a" style="background:var(--amber);color:var(--b9)" data-t="fixadd-${c.id}">Add</div></div>`;
        }
        const lane = laneOf(c);
        if (lane === 'todo') {
            h += `<div class="eg-acts"><div class="eg-a start" data-t="start-${c.id}">▶ Start working</div><div class="eg-a fix" data-t="fix-${c.id}">+ Correction</div></div>`;
        } else if (lane === 'working') {
            h += `<div class="eg-acts"><div class="eg-a done ${open ? 'off' : ''}" data-t="done-${c.id}">✓ Done</div><div class="eg-a fix" data-t="fix-${c.id}">+ Correction</div></div>`;
        } else if (lane === 'done') {
            h += `<div class="eg-acts"><div class="eg-a fix" data-t="fix-${c.id}">+ Correction</div></div>`;
            h += '<div class="eg-where"><div class="eg-kinds">' + Object.entries(KINDS).map(([k, l]) =>
                `<div class="eg-k ${c.kind === k ? 'on' : ''}" data-t="kind-${c.id}-${k}">${l}</div>`).join('') + '</div>';
            if (c.kind && c.kind !== 'none') {
                h += `<div class="eg-post"><div class="eg-input" data-t="link-${c.id}">${c.link ? esc(c.link) : '<span class="ph">Link (optional)</span>'}${c.typingLink ? '<span class="eg-caret"></span>' : ''}</div>
                      <div class="eg-a posted" data-t="posted-${c.id}">Mark posted</div></div>`;
            }
            h += '</div>';
        } else {
            h += `<div class="eg-ok"><span>✓ ${KINDS[c.kind] ?? 'Posted'} posted ${esc(c.posted)}</span><span>Open · Undo</span></div>`;
        }
        return h + '</div>';
    }

    function render() {
        const counts = { todo: 0, working: 0, done: 0, posted: 0 };
        s.cards.forEach((c) => { counts[laneOf(c)]++; });
        const lane = s.cards.filter((c) => laneOf(c) === s.tab);
        const date = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });

        let drop = '';
        if (s.drop) {
            drop = '<div class="eg-drop">' + (s.input ? '' : '<div class="lbl">Up next for you</div>') + s.drop.map((o, i) => {
                const badge = { p: '＋', c: 'Aa', t: '↺' }[o.type];
                return `<div class="eg-opt ${s.hi === i ? 'hi' : ''}" data-t="opt-${i}"><span class="eg-badge ${o.type}">${badge}</span>
                    <span style="min-width:0;flex:1"><div class="eg-ot">${esc(o.title)}</div><div class="eg-os">${o.sub}</div></span></div>`;
            }).join('') + '</div>';
        }

        screen.innerHTML = `
            <div class="eg-head"><div><div class="eg-date">${date}</div><div class="eg-h1">My Editing</div></div>
              <div class="eg-icons"><span class="eg-ico">?</span><span class="eg-ico ${s.ring === 'refresh' ? 'eg-ring' : ''}" data-t="refresh" style="${s.spin ? 'animation:spin 1s linear infinite' : ''}">↻</span></div></div>
            <div class="eg-add">
              <div class="eg-row"><div class="eg-input ${s.focus ? 'focus' : ''}" data-t="input">🔍&nbsp;${s.input ? esc(s.input) : '<span class="ph">Type a video or client — e.g. SVA</span>'}${s.focus ? '<span class="eg-caret"></span>' : ''}</div>
              <div class="eg-btn ${s.input.trim() ? '' : 'off'}" data-t="add">Add</div></div>
              <div class="eg-chips">${['SVA', 'Suryas', 'TPC', 'Janet'].map((c) => `<span class="eg-chip" data-t="chip-${c}">${c} –</span>`).join('')}</div>
              ${drop}
            </div>
            <div class="eg-tabs">${Object.entries({ todo: 'To do', working: 'Working', done: 'Done', posted: 'Posted' }).map(([k, l]) =>
                `<div class="eg-tab ${s.tab === k ? 'on' : ''}" data-t="tab-${k}">${l}<span class="n ${k} ${s.bump === k ? 'bump' : ''}">${counts[k]}</span></div>`).join('')}</div>
            <div class="eg-lane">${lane.length ? lane.map(cardHtml).join('') : `<div class="eg-empty">${s.tab === 'todo' ? 'Nothing to do yet — type above or pick from Up next.' : 'Nothing here'}</div>`}</div>
            ${s.toast ? `<div class="eg-toast">${esc(s.toast)}</div>` : ''}`;
        fresh.clear();
        s.bump = null;
    }

    // ——— Timing, the finger, typing ———
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

    async function tap(sel, { pause = 450 } = {}) {
        const el = typeof sel === 'string' ? stage.querySelector(sel.startsWith('[') ? sel : `[data-t="${sel}"]`) : sel;
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
    }

    async function type(text, set, speed = 75) {
        for (let i = 1; i <= text.length; i++) {
            set(text.slice(0, i));
            render();
            await wait(speed);
        }
    }

    function say(html) {
        cap.innerHTML = html;
        cap.classList.remove('swap');
        void cap.offsetWidth;
        cap.classList.add('swap');
    }

    async function toast(text, ms = 1600) {
        s.toast = text;
        render();
        await wait(ms);
        s.toast = '';
        render();
    }

    function hideFinger() {
        finger.style.opacity = '0';
    }

    // ——— The story ———
    const card = (id, title, client, publishes, more = {}) => ({ id, title, client, publishes, lane: 'todo', fixes: [], minutes: null, kind: null, posted: null, ...more });
    const A = () => card(1, 'SVA - Chettinad Cotton Frock', 'SVA Silks', 'Tomorrow');
    const B = () => card(2, 'Janet - 10 Foods For New Moms', 'Janet', 'Sat 11 Oct');
    const C = () => card(3, 'Suryas - Cream Bun Montage', "Surya's Restaurant", null);
    const MINE = [
        { type: 'p', title: 'SVA - Chettinad Cotton Frock', sub: 'Publishes Tomorrow · To Be Edited' },
        { type: 'p', title: 'TPC - Home Grooming Ad', sub: '<span class="late">Publishes Thu 9 Oct</span> · Under Review' },
        { type: 'p', title: 'SVA Tier 2 - Kids Party Wear', sub: 'Publishes Mon 13 Oct · To Be Edited' },
    ];

    const scenes = [
        {
            step: 'Find it', title: 'Open My Editing',
            from: () => blank(),
            async play() {
                say('Open the menu <b>☰</b> and tap <b>My Editing</b> — the film icon.');
                await wait(2600);
                cover.classList.add('hide');
                await wait(700);
                shade.classList.add('open');
                side.classList.add('open');
                await wait(900);
                await tap('[data-side-editing]');
                stage.querySelector('[data-side-editing]').classList.add('on');
                await wait(500);
                side.classList.remove('open');
                shade.classList.remove('open');
                await wait(1400);
            },
        },
        {
            step: 'Add a video', title: 'Pick from Up next',
            from: () => blank(),
            async play() {
                say('Tap the box. <b>Up next for you</b> shows what the Reel Planner has for you. Tap one to add it.');
                await wait(1400);
                await tap('input');
                s.focus = true;
                s.drop = MINE;
                render();
                await wait(1600);
                s.hi = 0;
                render();
                await tap('opt-0');
                Object.assign(s, { drop: null, hi: -1, focus: false, bump: 'todo' });
                s.cards.push(A());
                fresh.add(1);
                await toast('Added: SVA - Chettinad Cotton Frock', 1800);
                await wait(900);
            },
        },
        {
            step: 'Add a video', title: 'Search as you type',
            from: () => ({ ...blank(), cards: [A()] }),
            async play() {
                say('Not in your list? Start typing a client or a word. Matches from the <b>whole planner</b> show up.');
                await wait(1200);
                await tap('input');
                s.focus = true;
                render();
                await type('jan', (v) => {
                    s.input = v;
                    s.drop = v.length < 2 ? null : [
                        { type: 'c', title: 'Janet - …', sub: 'Start a title for this client' },
                        { type: 'p', title: 'Janet - 10 Foods For New Moms', sub: 'Publishes Sat 11 Oct · To Be Edited · Gokul’s' },
                        { type: 't', title: 'Janet - Baby Food Myths', sub: 'You did one like this — tap to edit the name' },
                    ];
                }, 160);
                await wait(1500);
                s.hi = 1;
                render();
                await tap('opt-1');
                Object.assign(s, { drop: null, hi: -1, focus: false, input: '', bump: 'todo' });
                s.cards.push(B());
                fresh.add(2);
                await toast('Added: Janet - 10 Foods For New Moms', 1700);
                await wait(700);
            },
        },
        {
            step: 'Add a video', title: 'Something new',
            from: () => ({ ...blank(), cards: [A(), B()] }),
            async play() {
                say('A video that isn’t in the planner? Tap the <b>client chip</b>, type the name, tap <b>Add</b>.');
                await wait(1300);
                await tap('chip-Suryas');
                Object.assign(s, { focus: true, input: 'Suryas - ' });
                render();
                await wait(500);
                await type('Suryas - Cream Bun Montage', (v) => { s.input = v; }, 70);
                await wait(500);
                await tap('add');
                Object.assign(s, { focus: false, input: '', bump: 'todo' });
                s.cards.push(C());
                fresh.add(3);
                await toast('Added.', 1500);
                await wait(800);
            },
        },
        {
            step: 'Work on it', title: 'Start working',
            from: () => ({ ...blank(), cards: [A(), B(), C()] }),
            async play() {
                say('Opening the project? Tap <b>▶ Start working</b>. The timer runs by itself — no time to log.');
                await wait(1500);
                await tap('start-1');
                const c = s.cards[0];
                Object.assign(c, { lane: 'working', minutes: 0 });
                Object.assign(s, { tab: 'working', bump: 'working' });
                fresh.add(1);
                render();
                await wait(900);
                say('It moves to <b>Working</b>. The tabs show how many are in each stage.');
                for (let m = 1; m <= 24; m += 1) {
                    c.minutes = m;
                    render();
                    await wait(60);
                }
                await wait(1800);
            },
        },
        {
            step: 'Work on it', title: 'Corrections',
            from: () => ({ ...blank(), tab: 'working', cards: [{ ...A(), lane: 'working', minutes: 24 }, B(), C()] }),
            async play() {
                say('Got feedback? Tap <b>+ Correction</b> and write it. It hangs on the video like a branch.');
                await wait(1300);
                await tap('fix-1');
                Object.assign(s, { fixing: 1, fixText: '' });
                render();
                await type('Logo cut at the end', (v) => { s.fixText = v; }, 60);
                await tap('fixadd-1');
                s.cards[0].fixes.push({ id: 11, text: 'Logo cut at the end', ok: false });
                s.fixing = null;
                render();
                await wait(500);
                await tap('fix-1');
                Object.assign(s, { fixing: 1, fixText: '' });
                render();
                await type('Music too loud at 0:12', (v) => { s.fixText = v; }, 60);
                await tap('fixadd-1');
                s.cards[0].fixes.push({ id: 12, text: 'Music too loud at 0:12', ok: false });
                s.fixing = null;
                render();
                await wait(600);
                say('<b>Done</b> stays locked until every correction is ticked. Fixed one? Tick it.');
                await wait(1600);
                await tap('box-11');
                s.cards[0].fixes[0].ok = true;
                render();
                await tap('box-12');
                s.cards[0].fixes[1].ok = true;
                render();
                await wait(1400);
            },
        },
        {
            step: 'Finish', title: 'Done',
            from: () => ({ ...blank(), tab: 'working', cards: [{ ...A(), lane: 'working', minutes: 41, fixes: [{ id: 11, text: 'Logo cut at the end', ok: true }, { id: 12, text: 'Music too loud at 0:12', ok: true }] }, B(), C()] }),
            async play() {
                say('Finished the edit? Tap <b>✓ Done</b>.');
                await wait(1200);
                await tap('done-1');
                Object.assign(s.cards[0], { lane: 'done' });
                Object.assign(s, { tab: 'done', bump: 'done' });
                fresh.add(1);
                render();
                await wait(2200);
            },
        },
        {
            step: 'Finish', title: 'Where does it go?',
            from: () => ({ ...blank(), tab: 'done', cards: [{ ...A(), lane: 'done', minutes: 41, fixes: [{ id: 11, text: 'Logo cut at the end', ok: true }, { id: 12, text: 'Music too loud at 0:12', ok: true }] }, B(), C()] }),
            async play() {
                say('Pick where it goes: <b>Reel, Post, Story</b> — or <b>Not posting</b> if it is only for the client.');
                await wait(1500);
                await tap('kind-1-reel');
                s.cards[0].kind = 'reel';
                render();
                await wait(900);
                say('Once it is live, paste the link (optional) and tap <b>Mark posted</b>.');
                await wait(1200);
                await tap('link-1');
                s.cards[0].typingLink = true;
                await type('instagram.com/reel/DK8h2', (v) => { s.cards[0].link = v; }, 55);
                s.cards[0].typingLink = false;
                await tap('posted-1');
                Object.assign(s.cards[0], { posted: '6:42 PM' });
                Object.assign(s, { tab: 'posted', bump: 'posted' });
                fresh.add(1);
                await toast('Posted ✓', 1400);
                await wait(1200);
            },
        },
        {
            step: 'Changes later', title: 'Sent back after Done',
            from: () => ({ ...blank(), tab: 'done', cards: [{ ...A(), posted: '6:42 PM', kind: 'reel', lane: 'done' }, { ...B(), lane: 'done', minutes: 35 }, C()] }),
            async play() {
                say('Client wants a change after it is done? Add a correction — the video goes back to <b>Working</b> on its own.');
                await wait(1600);
                await tap('fix-2');
                Object.assign(s, { fixing: 2, fixText: '' });
                render();
                await type('Change thumbnail text', (v) => { s.fixText = v; }, 60);
                await tap('fixadd-2');
                const c = s.cards[1];
                c.fixes.push({ id: 21, text: 'Change thumbnail text', ok: false });
                Object.assign(c, { lane: 'working', kind: null });
                Object.assign(s, { fixing: null, tab: 'working', bump: 'working' });
                fresh.add(2);
                render();
                await wait(2600);
            },
        },
        {
            step: 'Tip', title: 'Fresh from Notion',
            from: () => ({ ...blank(), tab: 'working', cards: [{ ...B(), lane: 'working', minutes: 35, fixes: [{ id: 21, text: 'Change thumbnail text', ok: false }] }, C()] }),
            async play() {
                say('Planner changed in Notion? The small <b>↻</b> at the top pulls the latest. Tomorrow’s and next week’s videos are already there — <b>edit ahead</b>.');
                s.ring = 'refresh';
                render();
                await wait(1600);
                await tap('refresh');
                s.spin = true;
                render();
                await wait(1300);
                Object.assign(s, { spin: false, ring: null });
                await toast('Reel Planner refreshed', 1500);
                await wait(1600);
            },
        },
        {
            step: 'Recap', title: 'That’s it',
            from: () => blank(),
            async play() {
                hideFinger();
                say('Questions? Ask your admin. Happy editing!');
                end.classList.remove('hide');
                await wait(6500);
            },
        },
    ];

    // ——— Playing, jumping, chapters ———
    chaptersEl.innerHTML = scenes.map((sc, i) => `<button type="button" class="eg-chap" data-chap="${i}">${i + 1}. ${sc.title}</button>`).join('');
    chaptersEl.addEventListener('click', (e) => {
        const b = e.target.closest('[data-chap]');
        if (b) start(Number(b.dataset.chap));
    });

    function showStep(i) {
        current = i;
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
        if (from > 0) cover.classList.add('hide');
        else cover.classList.remove('hide');
        end.classList.add('hide');
        side.classList.remove('open');
        shade.classList.remove('open');
        stage.querySelector('[data-side-editing]').classList.remove('on');
        hideFinger();
        try {
            for (let i = from; i < scenes.length; i++) {
                showStep(i);
                s = scenes[i].from();
                render();
                await scenes[i].play();
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
    s = blank();
    render();
    showStep(0);
    say('Press <b>Play</b> to see how My Editing works. About a minute.');

    // ——— Save as video ———
    const recordBtn = $('[data-record]');
    if (!navigator.mediaDevices?.getDisplayMedia || typeof MediaRecorder === 'undefined') {
        recordBtn.remove();
        $('[data-note]').remove();
    } else {
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
                if (window.CropTarget && track.cropTo) await track.cropTo(await CropTarget.fromElement(stage));
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
                a.download = `My-Editing-how-to.${(rec.mimeType || '').includes('mp4') ? 'mp4' : 'webm'}`;
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
}
