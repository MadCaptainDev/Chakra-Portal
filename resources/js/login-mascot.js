/*
 * The sign-in page's mascot (auth/login.blade.php): the studio's drawn
 * character, from the two page-mascot sheets in public/images/mascot.
 *
 * Unlike the page-mascot React component -- which only follows a mouse,
 * and not at all on a phone -- this one is driven by the form: it looks at
 * the text cursor as you type your email, on any device; it shuts its eyes
 * (and blushes) while you type a password; it looks surprised when a
 * sign-in fails; it dozes off when left alone; and it reacts to a poke.
 * Between all that, on a desk, it follows the mouse.
 *
 * Same sheet layout as page-mascot: 3x3 cells, background-size 300%.
 */

const DIRECTIONS = ['up-left', 'up', 'up-right', 'left', 'center', 'right', 'down-left', 'down', 'down-right'];
const REACTIONS = ['blink', 'heart', 'sparkle', 'surprised', 'wink', 'bashful', 'sleepy', 'dizzy', 'delighted'];
// Clockwise from the right, matching atan2 with y pointing down.
const CLOCKWISE = ['right', 'down-right', 'down', 'down-left', 'left', 'up-left', 'up', 'up-right'];
const SECTOR = (Math.PI * 2) / CLOCKWISE.length;
const DEAD_ZONE = 50;
const PAYOFFS = ['heart', 'sparkle', 'delighted'];
const DOZE_AFTER = 25000;

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const cell = (index) => `${(index % 3) * 50}% ${Math.floor(index / 3) * 50}%`;

// Where the text cursor is inside an <input>, in viewport pixels.
function caretPoint(input) {
    const style = getComputedStyle(input);
    const canvas = caretPoint.canvas ??= document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;

    const upTo = input.selectionStart ?? input.value.length;
    const text = input.type === 'password' ? '•'.repeat(upTo) : input.value.slice(0, upTo);
    const box = input.getBoundingClientRect();
    const left = box.left + parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth);

    return {
        x: Math.min(box.right - 8, left + ctx.measureText(text).width - input.scrollLeft),
        y: box.top + box.height / 2,
    };
}

export function loginMascot({ failed = false } = {}) {
    return {
        direction: 'center',
        reaction: null,
        typingPassword: false,
        timers: [],
        boops: { count: 0, at: 0 },
        dozeTimer: null,

        init() {
            const form = this.$el.closest('[data-mascot-scope]') ?? document;

            // Typing: look at the caret; a password closes the eyes.
            form.querySelectorAll('input[type="email"], input[type="text"], input[type="password"]').forEach((input) => {
                const follow = () => {
                    this.wake();
                    this.typingPassword = input.type === 'password' && input.value.length > 0;
                    this.lookAt(caretPoint(input));
                };
                ['focus', 'input', 'keyup', 'click', 'select'].forEach((event) => input.addEventListener(event, follow));
                input.addEventListener('blur', () => {
                    this.typingPassword = false;
                });
            });

            // On a desk, the mouse when nothing is being typed.
            if (window.matchMedia?.('(hover: hover) and (pointer: fine)').matches) {
                window.addEventListener('pointermove', (event) => {
                    if (document.activeElement?.matches?.('input')) return;
                    this.wake();
                    this.lookAt({ x: event.clientX, y: event.clientY });
                }, { passive: true });
            }

            if (failed) this.react('surprised', 1600);
            this.wake();
        },

        // The cell to show: a reaction wins, then eyes-shut for a password.
        get face() {
            if (this.reaction) return { sheet: 'reactions', at: cell(REACTIONS.indexOf(this.reaction)) };
            if (this.typingPassword) return { sheet: 'reactions', at: cell(REACTIONS.indexOf('bashful')) };
            return { sheet: 'directions', at: cell(DIRECTIONS.indexOf(this.direction)) };
        },

        lookAt(point) {
            const box = this.$refs.face.getBoundingClientRect();
            const dx = point.x - (box.left + box.width / 2);
            const dy = point.y - (box.top + box.height / 2);

            if (Math.hypot(dx, dy) < DEAD_ZONE) {
                this.direction = 'center';
                return;
            }

            const sector = (Math.round(Math.atan2(dy, dx) / SECTOR) + CLOCKWISE.length) % CLOCKWISE.length;
            this.direction = CLOCKWISE[sector];
        },

        // A reaction for a while, then back to looking.
        react(name, ms) {
            this.timers.forEach(clearTimeout);
            this.timers = [];
            this.reaction = name;
            if (ms) this.timers.push(setTimeout(() => (this.reaction = null), ms));
        },

        // Any activity: awake, and the doze clock restarts.
        wake() {
            if (this.reaction === 'sleepy') this.reaction = null;
            clearTimeout(this.dozeTimer);
            this.dozeTimer = setTimeout(() => this.react('sleepy'), DOZE_AFTER);
        },

        boop() {
            this.wake();
            const now = Date.now();
            this.boops.count = now - this.boops.at < 1600 ? this.boops.count + 1 : 1;
            this.boops.at = now;

            if (this.boops.count >= 4) {
                this.boops.count = 0;
                this.react('dizzy', 1100);
            } else {
                this.react('blink');
                this.timers.push(setTimeout(() => (this.reaction = PAYOFFS[(this.boops.count - 1) % PAYOFFS.length]), 120));
                this.timers.push(setTimeout(() => (this.reaction = null), 700));
            }

            if (!reduceMotion()) {
                this.$refs.face.animate([
                    { transform: 'scale(1, 1)', easing: 'ease-in' },
                    { transform: 'scale(1.10, 0.86)', offset: 0.18, easing: 'ease-out' },
                    { transform: 'scale(0.95, 1.08)', offset: 0.45, easing: 'ease-in-out' },
                    { transform: 'scale(1.03, 0.97)', offset: 0.72, easing: 'ease-in-out' },
                    { transform: 'scale(1, 1)' },
                ], { duration: 420, easing: 'linear' });
            }
        },
    };
}
