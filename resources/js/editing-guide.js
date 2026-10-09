/*
 * "How My Editing works" (my/editing-guide.blade.php): the scenes and the
 * mock screen for the My Editing walkthrough. The player -- finger, timing,
 * chapters, Save as video -- is resources/js/guide-player.js.
 */
import { startGuide } from './guide-player.js';

const root = document.querySelector('[data-guide]');

const KINDS = { reel: 'Reel', post: 'Post', story: 'Story', none: 'Not posting' };
const esc = (t) => String(t).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
const laneOf = (c) => (c.posted ? 'posted' : c.lane);

if (root) startGuide(root, (g) => {
    const fresh = new Set(); // cards that animate in on the next draw

    const blank = () => ({ input: '', focus: false, drop: null, hi: -1, tab: 'todo', cards: [], fixing: null, fixText: '', spin: false, ring: null, bump: null });

    function cardHtml(s, c) {
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

    function render(s) {
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

        g.screen.innerHTML = `
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
            <div class="eg-lane">${lane.length ? lane.map((c) => cardHtml(s, c)).join('') : `<div class="eg-empty">${s.tab === 'todo' ? 'Nothing to do yet — type above or pick from Up next.' : 'Nothing here'}</div>`}</div>`;
        fresh.clear();
        s.bump = null;
    }

    // ——— The story ———
    const card = (id, title, client, publishes, more = {}) => ({ id, title, client, publishes, lane: 'todo', fixes: [], minutes: null, kind: null, posted: null, ...more });
    const A = () => card(1, 'SVA - Chettinad Cotton Frock', 'SVA Silks', 'Tomorrow');
    const B = () => card(2, 'Janet - 10 Foods For New Moms', 'Janet', 'Sat 11 Oct');
    const C = () => card(3, 'Suryas - Cream Bun Montage', "Surya's Restaurant", null);
    const fixedA = () => [{ id: 11, text: 'Logo cut at the end', ok: true }, { id: 12, text: 'Music too loud at 0:12', ok: true }];
    const MINE = [
        { type: 'p', title: 'SVA - Chettinad Cotton Frock', sub: 'Publishes Tomorrow · To Be Edited' },
        { type: 'p', title: 'TPC - Home Grooming Ad', sub: '<span class="late">Publishes Thu 9 Oct</span> · Under Review' },
        { type: 'p', title: 'SVA Tier 2 - Kids Party Wear', sub: 'Publishes Mon 13 Oct · To Be Edited' },
    ];

    async function addFix(s, cardId, text, fixId) {
        await g.tap(`fix-${cardId}`);
        Object.assign(s, { fixing: cardId, fixText: '' });
        g.draw();
        await g.type(text, (v) => { s.fixText = v; }, 60);
        await g.tap(`fixadd-${cardId}`);
        s.cards.find((c) => c.id === cardId).fixes.push({ id: fixId, text, ok: false });
        s.fixing = null;
        g.draw();
    }

    const scenes = [
        {
            step: 'Find it', title: 'Open My Editing',
            from: blank,
            async play() {
                g.say('Open the menu <b>☰</b> and tap <b>My Editing</b> — the film icon.');
                await g.wait(2600);
                g.hideCover();
                await g.wait(700);
                await g.openFromMenu('[data-side-editing]');
                await g.wait(1400);
            },
        },
        {
            step: 'Add a video', title: 'Pick from Up next',
            from: blank,
            async play(s) {
                g.say('Tap the box. <b>Up next for you</b> shows what the Reel Planner has for you. Tap one to add it.');
                await g.wait(1400);
                await g.tap('input');
                Object.assign(s, { focus: true, drop: MINE });
                g.draw();
                await g.wait(1600);
                s.hi = 0;
                g.draw();
                await g.tap('opt-0');
                Object.assign(s, { drop: null, hi: -1, focus: false, bump: 'todo' });
                s.cards.push(A());
                fresh.add(1);
                g.draw();
                await g.toast('Added: SVA - Chettinad Cotton Frock', 1800);
                await g.wait(900);
            },
        },
        {
            step: 'Add a video', title: 'Search as you type',
            from: () => ({ ...blank(), cards: [A()] }),
            async play(s) {
                g.say('Not in your list? Start typing a client or a word. Matches from the <b>whole planner</b> show up.');
                await g.wait(1200);
                await g.tap('input');
                s.focus = true;
                g.draw();
                await g.type('jan', (v) => {
                    s.input = v;
                    s.drop = v.length < 2 ? null : [
                        { type: 'c', title: 'Janet - …', sub: 'Start a title for this client' },
                        { type: 'p', title: 'Janet - 10 Foods For New Moms', sub: 'Publishes Sat 11 Oct · To Be Edited · Gokul’s' },
                        { type: 't', title: 'Janet - Baby Food Myths', sub: 'You did one like this — tap to edit the name' },
                    ];
                }, 160);
                await g.wait(1500);
                s.hi = 1;
                g.draw();
                await g.tap('opt-1');
                Object.assign(s, { drop: null, hi: -1, focus: false, input: '', bump: 'todo' });
                s.cards.push(B());
                fresh.add(2);
                g.draw();
                await g.toast('Added: Janet - 10 Foods For New Moms', 1700);
                await g.wait(700);
            },
        },
        {
            step: 'Add a video', title: 'Something new',
            from: () => ({ ...blank(), cards: [A(), B()] }),
            async play(s) {
                g.say('A video that isn’t in the planner? Tap the <b>client chip</b>, type the name, tap <b>Add</b>.');
                await g.wait(1300);
                await g.tap('chip-Suryas');
                Object.assign(s, { focus: true, input: 'Suryas - ' });
                g.draw();
                await g.wait(500);
                await g.type('Suryas - Cream Bun Montage', (v) => { s.input = v; }, 70);
                await g.wait(500);
                await g.tap('add');
                Object.assign(s, { focus: false, input: '', bump: 'todo' });
                s.cards.push(C());
                fresh.add(3);
                g.draw();
                await g.toast('Added.', 1500);
                await g.wait(800);
            },
        },
        {
            step: 'Work on it', title: 'Start working',
            from: () => ({ ...blank(), cards: [A(), B(), C()] }),
            async play(s) {
                g.say('Opening the project? Tap <b>▶ Start working</b>. The timer runs by itself — no time to log.');
                await g.wait(1500);
                await g.tap('start-1');
                const c = s.cards[0];
                Object.assign(c, { lane: 'working', minutes: 0 });
                Object.assign(s, { tab: 'working', bump: 'working' });
                fresh.add(1);
                g.draw();
                await g.wait(900);
                g.say('It moves to <b>Working</b>. The tabs show how many are in each stage.');
                for (let m = 1; m <= 24; m += 1) {
                    c.minutes = m;
                    g.draw();
                    await g.wait(60);
                }
                await g.wait(1800);
            },
        },
        {
            step: 'Work on it', title: 'Corrections',
            from: () => ({ ...blank(), tab: 'working', cards: [{ ...A(), lane: 'working', minutes: 24 }, B(), C()] }),
            async play(s) {
                g.say('Got feedback? Tap <b>+ Correction</b> and write it. It hangs on the video like a branch.');
                await g.wait(1300);
                await addFix(s, 1, 'Logo cut at the end', 11);
                await g.wait(500);
                await addFix(s, 1, 'Music too loud at 0:12', 12);
                await g.wait(600);
                g.say('<b>Done</b> stays locked until every correction is ticked. Fixed one? Tick it.');
                await g.wait(1600);
                await g.tap('box-11');
                s.cards[0].fixes[0].ok = true;
                g.draw();
                await g.tap('box-12');
                s.cards[0].fixes[1].ok = true;
                g.draw();
                await g.wait(1400);
            },
        },
        {
            step: 'Finish', title: 'Done',
            from: () => ({ ...blank(), tab: 'working', cards: [{ ...A(), lane: 'working', minutes: 41, fixes: fixedA() }, B(), C()] }),
            async play(s) {
                g.say('Finished the edit? Tap <b>✓ Done</b>.');
                await g.wait(1200);
                await g.tap('done-1');
                s.cards[0].lane = 'done';
                Object.assign(s, { tab: 'done', bump: 'done' });
                fresh.add(1);
                g.draw();
                await g.wait(2200);
            },
        },
        {
            step: 'Finish', title: 'Where does it go?',
            from: () => ({ ...blank(), tab: 'done', cards: [{ ...A(), lane: 'done', minutes: 41, fixes: fixedA() }, B(), C()] }),
            async play(s) {
                g.say('Pick where it goes: <b>Reel, Post, Story</b> — or <b>Not posting</b> if it is only for the client.');
                await g.wait(1500);
                await g.tap('kind-1-reel');
                s.cards[0].kind = 'reel';
                g.draw();
                await g.wait(900);
                g.say('Once it is live, paste the link (optional) and tap <b>Mark posted</b>.');
                await g.wait(1200);
                await g.tap('link-1');
                s.cards[0].typingLink = true;
                await g.type('instagram.com/reel/DK8h2', (v) => { s.cards[0].link = v; }, 55);
                s.cards[0].typingLink = false;
                await g.tap('posted-1');
                s.cards[0].posted = '6:42 PM';
                Object.assign(s, { tab: 'posted', bump: 'posted' });
                fresh.add(1);
                g.draw();
                await g.toast('Posted ✓', 1400);
                await g.wait(1200);
            },
        },
        {
            step: 'Changes later', title: 'Sent back after Done',
            from: () => ({ ...blank(), tab: 'done', cards: [{ ...A(), posted: '6:42 PM', kind: 'reel', lane: 'done' }, { ...B(), lane: 'done', minutes: 35 }, C()] }),
            async play(s) {
                g.say('Client wants a change after it is done? Add a correction — the video goes back to <b>Working</b> on its own.');
                await g.wait(1600);
                await addFix(s, 2, 'Change thumbnail text', 21);
                Object.assign(s.cards[1], { lane: 'working', kind: null });
                Object.assign(s, { tab: 'working', bump: 'working' });
                fresh.add(2);
                g.draw();
                await g.wait(2600);
            },
        },
        {
            step: 'Tip', title: 'Fresh from Notion',
            from: () => ({ ...blank(), tab: 'working', cards: [{ ...B(), lane: 'working', minutes: 35, fixes: [{ id: 21, text: 'Change thumbnail text', ok: false }] }, C()] }),
            async play(s) {
                g.say('Planner changed in Notion? The small <b>↻</b> at the top pulls the latest. Tomorrow’s and next week’s videos are already there — <b>edit ahead</b>.');
                s.ring = 'refresh';
                g.draw();
                await g.wait(1600);
                await g.tap('refresh');
                s.spin = true;
                g.draw();
                await g.wait(1300);
                Object.assign(s, { spin: false, ring: null });
                g.draw();
                await g.toast('Reel Planner refreshed', 1500);
                await g.wait(1600);
            },
        },
        {
            step: 'Recap', title: 'That’s it',
            from: blank,
            async play() {
                g.say('Questions? Ask your admin. Happy editing!');
                g.showEnd();
                await g.wait(6500);
            },
        },
    ];

    return { blank, render, scenes };
});
