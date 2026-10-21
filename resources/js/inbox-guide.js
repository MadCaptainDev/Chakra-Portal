/*
 * "How the Comment & Message Checker works" (inbox-desk/guide.blade.php):
 * the scenes and the mock screen. The player -- finger, timing, chapters,
 * Save as video -- is resources/js/guide-player.js.
 */
import { startGuide } from './guide-player.js';

const root = document.querySelector('[data-guide]');
const esc = (t) => String(t).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
const CIRC = 2 * Math.PI * 26;
const ORDER = ['dm', 'cm'];
const NAMES = { dm: 'DMs', cm: 'Comments' };

if (root) startGuide(root, (g) => {
    let shownScroll = 0;
    let shownOffset = CIRC;

    const open = (hint = null, late = 0) => ({ state: 'open', hint, late });
    const done = (count, at) => ({ state: 'done', count, by: 'Sanjai', at });

    const blank = () => ({
        accounts: [
            { id: 'a', handle: '@riyamakeover_artistry', name: 'Riya Makeover', ini: 'RM', tiles: { dm: open('3 new chats'), cm: open() } },
            { id: 'b', handle: '@squad_architects', name: 'Squad Architects', ini: 'SA', tiles: { dm: open(), cm: open() } },
            { id: 'c', handle: '@thillaipetsclinic', name: 'Thillai Pets Clinic', ini: 'TP', tiles: { dm: open(null, 1), cm: open() } },
        ],
        expanded: null,
        count: 0,
        scroll: 0,
        flash: null,
        hl: null,
        feed: [],
        showFeed: false,
    });

    const tiles = (s) => s.accounts.flatMap((a) => ORDER.map((k) => a.tiles[k]));

    function tileHtml(s, a, k) {
        const t = a.tiles[k];
        const key = `${a.id}-${k}`;
        const cls = [t.state === 'done' ? 'done' : '', s.expanded === key ? 'open-panel' : '', s.flash === key ? 'flash' : ''].join(' ');
        let body;
        if (t.state === 'done') {
            body = `<div class="ic-ts">${t.count !== null ? `<b style="color:#a7f3d0">${t.count}</b> replied · ` : ''}${t.by} ${t.at}</div>`;
        } else {
            body = `<div class="ic-ts ${t.hint ? 'hint' : ''}">${t.late ? `<span class="ic-tag">${t.late === 1 ? 'Missed yesterday' : `${t.late} days late`}</span><br>` : ''}${t.hint ?? 'Tap when checked'}</div>`;
        }
        return `<div class="ic-tile ${cls}" data-t="tile-${key}"><div class="ic-tt"><span>${k === 'dm' ? '✈' : '💬'}&nbsp;${NAMES[k]}</span><span class="ic-mark">${t.state === 'done' ? '✓' : ''}</span></div>${body}</div>`;
    }

    function panelHtml(s, a, k) {
        const t = a.tiles[k];
        if (t.state === 'done') {
            return `<div class="ic-panel"><div class="ic-by"><span>Checked by <b>${t.by}</b> at ${t.at}${t.count !== null ? ` · ${t.count} replied` : ''}</span><u data-t="undo">Undo</u></div></div>`;
        }
        const sub = t.late ? `Closes ${t.late + 1} days in one go` : (t.hint ? `Instagram: ${t.hint} since the last check` : '');
        return `<div class="ic-panel">
            <div class="ic-prow"><div><div class="ic-pl">Replied to</div><div class="ic-ps">${sub}</div></div>
              <div class="ic-step"><span data-t="minus">−</span><b>${s.count}</b><span data-t="plus">+</span></div></div>
            <div class="ic-btns"><div class="ic-b" data-t="nothing">Nothing new</div><div class="ic-b go" data-t="done">${s.count > 0 ? `Done · ${s.count} replied` : 'Done'}</div></div></div>`;
    }

    function accountHtml(s, a) {
        const left = ORDER.filter((k) => a.tiles[k].state !== 'done').length;
        const late = ORDER.some((k) => a.tiles[k].state !== 'done' && a.tiles[k].late);
        const pill = left === 0 ? '<span class="ic-pill done">Done</span>' : (late ? '<span class="ic-pill late">Late</span>' : `<span class="ic-pill">${left} left</span>`);
        const exp = s.expanded && s.expanded.startsWith(`${a.id}-`) ? panelHtml(s, a, s.expanded.split('-')[1]) : '';
        return `<div class="ic-acc ${left === 0 ? 'clear' : (late ? 'late' : '')} ${s.hl === a.id ? 'eg-ring' : ''}" data-acc="${a.id}">
            <div class="ic-who"><span class="ic-av">${a.ini}${left === 0 ? '<b>✓</b>' : ''}</span>
              <div style="min-width:0;flex:1"><div class="ic-hn">${esc(a.handle)}</div><div class="ic-cn">${esc(a.name)}</div></div>
              ${pill}<span class="ic-ig" data-t="ig-${a.id}"><svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg></span></div>
            <div class="ic-tiles">${ORDER.map((k) => tileHtml(s, a, k)).join('')}</div>
            ${exp}
            ${left > 1 ? `<div class="ic-all" data-t="all-${a.id}">Nothing new on any — mark all checked</div>` : ''}
          </div>`;
    }

    function render(s) {
        const all = tiles(s);
        const total = all.length;
        const doneN = all.filter((t) => t.state === 'done').length;
        const left = total - doneN;
        const accountsLeft = s.accounts.filter((a) => ORDER.some((k) => a.tiles[k].state !== 'done')).length;
        const late = all.filter((t) => t.state !== 'done' && t.late).length;
        const clear = left === 0;
        const offset = CIRC * (1 - doneN / total);

        const text = clear
            ? `<div class="ic-big">All inboxes clear</div><div class="ic-small">Every DM and comment check is done for today — last by Sanjai at ${s.feed[0]?.at ?? ''}.</div>`
            : `<div class="ic-big">${left} ${left === 1 ? 'check' : 'checks'} left</div><div class="ic-small">on <b>${accountsLeft}</b> ${accountsLeft === 1 ? 'account' : 'accounts'}${late ? ` · <span class="late">${late} late from earlier</span>` : ''}</div>`;

        const feed = s.showFeed ? `<div class="ic-feed" data-feed><div class="lbl">Today’s activity</div>${s.feed.map((f) =>
            `<div class="ic-fi"><i>S</i><span><b>Sanjai</b> checked ${f.what} on ${esc(f.handle)}${f.count !== null ? ` · ${f.count} replied` : ''}</span><span>${f.at}</span></div>`).join('')}</div>` : '';

        g.screen.innerHTML = `<div class="ic-scroll" data-scroll style="position:relative">
            <div class="ic-eyebrow">Daily · Instagram</div>
            <div class="ic-h1">Comment &amp; Message Checker</div>
            <div class="ic-sum ${clear ? 'clear' : ''} ${s.hl === 'sum' ? 'eg-ring' : ''}">
              <div class="ic-ring"><svg viewBox="0 0 64 64"><circle class="bg" cx="32" cy="32" r="26"/><circle class="fg" cx="32" cy="32" r="26" stroke-dasharray="${CIRC}" stroke-dashoffset="${shownOffset}"/></svg>
                <div class="num">${clear ? '<span style="color:#6ee7b7;font-size:24px">✓</span>' : `${doneN}<small>/${total}</small>`}</div></div>
              <div style="min-width:0">${text}<div class="ic-live"><i></i> Live · updates on its own</div></div>
            </div>
            <div class="ic-list">${s.accounts.map((a) => accountHtml(s, a)).join('')}</div>
            ${feed}
          </div>`;

        // Start from what was on screen, then move: the ring fills and the
        // page scrolls instead of jumping.
        const wrap = g.screen.querySelector('[data-scroll]');
        const fg = g.screen.querySelector('.fg');
        wrap.style.transform = `translateY(${-shownScroll}px)`;
        void wrap.offsetWidth;
        wrap.style.transform = `translateY(${-s.scroll}px)`;
        fg.style.strokeDashoffset = offset;
        shownScroll = s.scroll;
        shownOffset = offset;
    }

    // ——— Helpers for the story ———
    const clock = ['10:42 AM', '10:44 AM', '10:47 AM', '10:47 AM', '10:51 AM', '10:53 AM', '10:58 AM'];
    let tick = 0;
    const now = () => clock[Math.min(tick++, clock.length - 1)];

    function settle(s, accountId, k, count) {
        const a = s.accounts.find((x) => x.id === accountId);
        const at = now();
        a.tiles[k] = done(count, at);
        s.feed.unshift({ what: NAMES[k], handle: a.handle, count, at });
        s.flash = `${accountId}-${k}`;
        s.expanded = null;
    }

    async function flashOff(s) {
        await g.wait(900);
        s.flash = null;
        g.draw();
    }

    /** Scroll the phone so an account (or the feed) sits near the top. */
    async function scrollTo(s, sel) {
        const el = g.screen.querySelector(sel);
        const wrap = g.screen.querySelector('[data-scroll]');
        if (!el) return;
        const max = Math.max(0, wrap.scrollHeight - g.screen.clientHeight + 20);
        s.scroll = Math.max(0, Math.min(max, el.offsetTop - 40));
        g.draw();
        await g.wait(750);
    }

    async function openTile(s, key, count = 0) {
        await g.tap(`tile-${key}`);
        Object.assign(s, { expanded: key, count });
        g.draw();
        await g.wait(500);
    }

    const scenes = [
        {
            step: 'Find it', title: 'Open the checker',
            from: () => { tick = 0; return blank(); },
            async play() {
                g.say('Open the menu <b>☰</b> and tap <b>Comment &amp; Message Checker</b>.');
                await g.wait(2600);
                g.hideCover();
                await g.wait(700);
                await g.openFromMenu('[data-side-checker]');
                await g.wait(1000);
            },
        },
        {
            step: 'Read it', title: 'Today at a glance',
            from: () => { tick = 0; return blank(); },
            async play(s) {
                g.say('The ring counts <b>today’s checks</b> — how many are done, how many are left.');
                s.hl = 'sum';
                g.draw();
                await g.wait(2800);
                g.say('Every client account has two tiles: <b>DMs</b> and <b>Comments</b>. Each one is a check for today.');
                s.hl = 'a';
                g.draw();
                await g.wait(3000);
                s.hl = null;
                g.draw();
                await g.wait(400);
            },
        },
        {
            step: 'Do it', title: 'Reply on Instagram',
            from: () => { tick = 0; return blank(); },
            async play() {
                g.say('See <b>“3 new chats”</b>? Tap the <b>camera icon</b> to open that account on Instagram, and reply to them there.');
                await g.wait(1800);
                await g.tap('ig-a');
                await g.toast('Opens @riyamakeover_artistry on Instagram', 2200);
                await g.wait(600);
            },
        },
        {
            step: 'Do it', title: 'Tick the DMs',
            from: () => { tick = 0; return blank(); },
            async play(s) {
                g.say('Back here, tap the <b>DMs</b> tile. Set how many you replied to with <b>− / +</b>, then tap <b>Done</b>.');
                await g.wait(1500);
                await openTile(s, 'a-dm', 3);
                await g.wait(900);
                await g.tap('plus');
                s.count = 4;
                g.draw();
                await g.wait(700);
                await g.tap('done');
                settle(s, 'a', 'dm', 4);
                g.draw();
                await g.toast('Checked.', 1200);
                g.say('Green means done — with <b>your name and the time</b>. Admins see it change live, no message needed.');
                await flashOff(s);
                await g.wait(2600);
            },
        },
        {
            step: 'Do it', title: 'Nothing new',
            from: () => { tick = 1; const s = blank(); s.accounts[0].tiles.dm = done(4, '10:42 AM'); s.feed = [{ what: 'DMs', handle: s.accounts[0].handle, count: 4, at: '10:42 AM' }]; return s; },
            async play(s) {
                g.say('No new comments? Tap the tile and tap <b>Nothing new</b>. That still counts — you checked.');
                await g.wait(1600);
                await openTile(s, 'a-cm', 0);
                await g.wait(700);
                await g.tap('nothing');
                settle(s, 'a', 'cm', 0);
                g.draw();
                await flashOff(s);
                g.say('Both tiles green — the account shows a <b>✓</b> and moves off your list.');
                await g.wait(2600);
            },
        },
        {
            step: 'Faster', title: 'A quiet account',
            from: () => { tick = 2; const s = blank(); s.accounts[0].tiles = { dm: done(4, '10:42 AM'), cm: done(0, '10:44 AM') }; s.feed = [{ what: 'Comments', handle: s.accounts[0].handle, count: 0, at: '10:44 AM' }, { what: 'DMs', handle: s.accounts[0].handle, count: 4, at: '10:42 AM' }]; return s; },
            async play(s) {
                await scrollTo(s, '[data-acc="b"]');
                g.say('An account with nothing new at all? One tap: <b>Nothing new on any — mark all checked</b>.');
                await g.wait(1800);
                await g.tap('all-b');
                settle(s, 'b', 'dm', 0);
                settle(s, 'b', 'cm', 0);
                s.flash = null;
                g.draw();
                await g.toast('Both checked.', 1300);
                await g.wait(1200);
            },
        },
        {
            step: 'Missed days', title: 'Late checks',
            from: () => {
                tick = 4;
                const s = blank();
                s.accounts[0].tiles = { dm: done(4, '10:42 AM'), cm: done(0, '10:44 AM') };
                s.accounts[1].tiles = { dm: done(0, '10:47 AM'), cm: done(0, '10:47 AM') };
                s.feed = [{ what: 'Comments', handle: s.accounts[1].handle, count: 0, at: '10:47 AM' }, { what: 'DMs', handle: s.accounts[1].handle, count: 0, at: '10:47 AM' }, { what: 'Comments', handle: s.accounts[0].handle, count: 0, at: '10:44 AM' }, { what: 'DMs', handle: s.accounts[0].handle, count: 4, at: '10:42 AM' }];
                return s;
            },
            async play(s) {
                await scrollTo(s, '[data-acc="c"]');
                g.say('Missed a day? The tile waits for you, marked <b>late</b>. One <b>Done</b> closes all the missed days together.');
                await g.wait(2200);
                await openTile(s, 'c-dm', 0);
                await g.wait(800);
                await g.tap('plus');
                s.count = 1;
                g.draw();
                await g.tap('plus', { pause: 250 });
                s.count = 2;
                g.draw();
                await g.wait(500);
                await g.tap('done');
                settle(s, 'c', 'dm', 2);
                g.draw();
                await flashOff(s);
                await g.wait(1400);
            },
        },
        {
            step: 'Mistakes', title: 'Undo',
            from: () => {
                tick = 5;
                const s = blank();
                s.accounts[0].tiles = { dm: done(4, '10:42 AM'), cm: done(0, '10:44 AM') };
                s.accounts[1].tiles = { dm: done(0, '10:47 AM'), cm: done(0, '10:47 AM') };
                s.accounts[2].tiles.dm = done(2, '10:51 AM');
                s.feed = [{ what: 'DMs', handle: s.accounts[2].handle, count: 2, at: '10:51 AM' }];
                return s;
            },
            async play(s) {
                await scrollTo(s, '[data-acc="c"]');
                g.say('Ticked the wrong one? Tap the green tile and tap <b>Undo</b>. It is open again.');
                await g.wait(1800);
                await g.tap('tile-c-dm');
                s.expanded = 'c-dm';
                g.draw();
                await g.wait(1000);
                await g.tap('undo');
                s.accounts[2].tiles.dm = open(null, 1);
                s.feed.shift();
                s.count = 2;
                g.draw();
                await g.toast('Undone.', 1200);
                g.say('Fix the number and tap <b>Done</b> again.');
                await g.wait(900);
                await g.tap('plus');
                s.count = 3;
                g.draw();
                await g.tap('done');
                settle(s, 'c', 'dm', 3);
                g.draw();
                await flashOff(s);
                await g.wait(1000);
            },
        },
        {
            step: 'Finish', title: 'All inboxes clear',
            from: () => {
                tick = 6;
                const s = blank();
                s.accounts[0].tiles = { dm: done(4, '10:42 AM'), cm: done(0, '10:44 AM') };
                s.accounts[1].tiles = { dm: done(0, '10:47 AM'), cm: done(0, '10:47 AM') };
                s.accounts[2].tiles.dm = done(3, '10:53 AM');
                s.feed = [
                    { what: 'DMs', handle: s.accounts[2].handle, count: 3, at: '10:53 AM' },
                    { what: 'Comments', handle: s.accounts[1].handle, count: 0, at: '10:47 AM' },
                    { what: 'DMs', handle: s.accounts[1].handle, count: 0, at: '10:47 AM' },
                    { what: 'Comments', handle: s.accounts[0].handle, count: 0, at: '10:44 AM' },
                    { what: 'DMs', handle: s.accounts[0].handle, count: 4, at: '10:42 AM' },
                ];
                return s;
            },
            async play(s) {
                await scrollTo(s, '[data-acc="c"]');
                g.say('Last one. Tap it, <b>Nothing new</b>…');
                await g.wait(1200);
                await openTile(s, 'c-cm', 0);
                await g.tap('nothing');
                settle(s, 'c', 'cm', 0);
                s.flash = null;
                s.scroll = 0;
                g.draw();
                await g.wait(800);
                g.say('…and the ring turns green: <b>All inboxes clear</b>. Do this every day — morning and evening is best.');
                await g.wait(3200);
                s.showFeed = true;
                g.draw();
                await scrollTo(s, '[data-feed]');
                g.say('<b>Today’s activity</b> shows who checked what, and when.');
                await g.wait(3000);
            },
        },
        {
            step: 'Recap', title: 'That’s it',
            from: () => { tick = 0; return blank(); },
            async play() {
                g.say('Questions? Ask your admin. Thank you for keeping every client’s inbox answered!');
                g.showEnd();
                await g.wait(6500);
            },
        },
    ];

    return { blank, render, scenes };
});
