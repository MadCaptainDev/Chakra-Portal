/*
 * The public website's own numbers (SiteTrackController): one hit per page
 * view, and the taps that say what interests people -- WhatsApp, plays,
 * case studies, homepage sections reached, scroll depth, categories.
 *
 * Anonymous: a random id in this browser, nothing typed, no name. Runs only
 * on pages that carry <meta name="site-track"> (the public layout). A browser
 * that has ever been signed in to the portal is remembered as staff and not
 * counted, so the team's own visits do not swamp the visitors'.
 */
const rand = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36]).join('');

function store(kind, key, make) {
    try {
        let v = kind.getItem(key);
        const fresh = !v;
        if (!v) { v = make(); kind.setItem(key, v); }
        return [v, fresh];
    } catch {
        return [make(), true]; // private mode: counted, just not remembered
    }
}

export function startSiteTracking() {
    const meta = document.querySelector('meta[name="site-track"]');
    if (!meta) return;

    const base = meta.content.replace(/\/$/, '');
    try { if (meta.dataset.staff === '1') localStorage.setItem('chakra-staff', '1'); } catch { /* */ }
    let staff = false;
    try { staff = localStorage.getItem('chakra-staff') === '1'; } catch { /* */ }

    const [visitor, firstVisit] = store(localStorage, 'chakra-v', rand);
    const [session] = store(sessionStorage, 'chakra-s', rand);
    const page = location.pathname;

    const send = (path, params) => {
        const q = new URLSearchParams({ v: visitor, ss: session, p: page, ...(staff ? { s: '1' } : {}), ...params });
        try { fetch(`${base}/t/${path}?${q}`, { keepalive: true, credentials: 'same-origin' }).catch(() => {}); } catch { /* */ }
    };

    const url = new URL(location.href);
    send('hit', {
        r: document.referrer || '',
        us: url.searchParams.get('utm_source') || '',
        uc: url.searchParams.get('utm_campaign') || '',
        n: firstVisit ? '1' : '0',
    });

    const once = new Set();
    const track = (type, target = '', label = '', unique = false) => {
        const k = `${type}|${target}`;
        if (unique && once.has(k)) return;
        once.add(k);
        send('event', { t: type, g: String(target || '').slice(0, 160), l: String(label || '').slice(0, 200) });
    };
    window.siteTrack = track;

    // Taps anywhere on the page.
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-track], a[href]');
        if (!el) return;
        if (el.dataset.track) { track(el.dataset.track, el.dataset.trackTarget || '', el.dataset.trackLabel || el.textContent.trim()); return; }
        const href = el.getAttribute('href') || '';
        if (/^https:\/\/(wa\.me|api\.whatsapp\.com)/.test(href)) track('whatsapp', 'link', el.textContent.trim());
        else if (href.startsWith('tel:')) track('call', href.slice(4));
        else {
            const m = href.match(/\/portfolio\/(\d+)(?:$|[?#])/);
            if (m) track('case_study', m[1], el.closest('[data-pf-card]')?.querySelector('h3, p.font-semibold')?.textContent.trim() || el.textContent.trim());
        }
        if (el.getAttribute('role') === 'tab') track('category', el.textContent.replace(/\s+\d+\s*$/, '').trim());
    }, { capture: true });

    // A reel played (portfolio-grid dispatches pf-watched with how it played).
    window.addEventListener('pf-watched', (e) => track('play', e.detail?.id, `${e.detail?.title || ''}${e.detail?.how === 'instagram' ? ' (sent to Instagram)' : ''}`));

    // Which homepage sections people actually reach -- what holds them.
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => entries.forEach((en) => {
            if (en.isIntersecting) { track('section', en.target.id, en.target.dataset.trackLabel || en.target.id, true); io.unobserve(en.target); }
        }), { threshold: 0.35 });
        document.querySelectorAll('section[id]').forEach((s) => io.observe(s));
    }

    // How far down they read.
    let marks = [50, 90];
    addEventListener('scroll', () => {
        const pct = ((scrollY + innerHeight) / document.documentElement.scrollHeight) * 100;
        marks = marks.filter((m) => { if (pct >= m) { track('scroll', m, `${m}%`, true); return false; } return true; });
    }, { passive: true });
}

/**
 * The WhatsApp button: a few ready messages, one of them about the reel the
 * visitor was just looking at, plus their own words. Each message ends with
 * "via chakragroups.in" so the chat in the portal's WhatsApp inbox says it
 * came from the website (and which reel).
 */
export function waChat({ number, context = null }) {
    return {
        open: false,
        own: '',
        context, // { title, client } of the piece on this page, if any
        get liked() {
            if (this.context?.title) return this.context;
            try { return JSON.parse(sessionStorage.getItem('chakra-last-reel') || 'null'); } catch { return null; }
        },
        get options() {
            const o = [
                { key: 'start', text: "Hi Chakra Productions! 👋 I saw your website and I'd like reels for my business." },
                { key: 'price', text: 'Hi! Can you share your packages and pricing for reels and shoots?' },
            ];
            const l = this.liked;
            if (l?.title) o.unshift({ key: 'like_this', text: `Hi! I loved your "${l.title}" reel${l.client ? ` for ${l.client}` : ''}. Can you make something like this for my brand?` });
            o.push({ key: 'shoot', text: "Hi! I'd like to book a shoot. When are you free?" });
            return o;
        },
        link(text) {
            return `https://wa.me/${number}?text=${encodeURIComponent(`${text}\n\n— via chakragroups.in`)}`;
        },
        go(option) {
            window.siteTrack?.('whatsapp', option.key, option.key === 'like_this' ? this.liked?.title : option.text.slice(0, 120));
            window.open(this.link(option.text), '_blank', 'noopener');
            this.open = false;
        },
        sendOwn() {
            const t = this.own.trim();
            if (!t) return;
            window.siteTrack?.('whatsapp', 'own', t.slice(0, 120));
            window.open(this.link(t), '_blank', 'noopener');
            this.own = '';
            this.open = false;
        },
    };
}
