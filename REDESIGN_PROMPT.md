# Chakra-Portal — UI Redesign Brief

> A prompt for an AI coding agent. You will be implementing this directly in the
> repository, not producing mockups. Read all of it before touching a file.

---

## 1. Mission

Chakra-Portal grew feature-first — invoicing, then recurring billing, then EMI,
Salaries and Bills. Each module was styled as it landed, so the visual language
has drifted. Your job is to make it **one coherent system** without changing what
anything does.

This is a redesign, not a rewrite. Every route, controller, model and test stays.
If you find yourself changing business logic, you have gone too far — with the one
deliberate exception in §6.

---

## 2. Who uses it, and on what

A handful of studio staff. There are no customer logins; everyone who can sign in
is trusted. Public registration is closed.

**The primary device is a phone at roughly 420px wide**, on the office WiFi. Desktop
is secondary — real, but secondary. A redesign that only reads well at 1440px has
failed the brief. Design mobile-first and check 420px before you check anything else.

Every module is used *while doing something else* — paying a bill, checking whether
an invoice went out. Prioritise scanning and one-tap actions over density.

---

## 3. Hard constraints

**Stack.** Laravel 12, Blade, Alpine.js 3, Tailwind 3.4, Vite.
Do **not** introduce React, Vue, Livewire, Inertia, a component library, an icon
package, or anything from a CDN. Assets are Vite-built and fonts are local `.ttf`
files in `public/fonts/`. Adding a dependency is a failure condition.

**Brand palette.** Fixed. Defined in `tailwind.config.js`, do not alter the values:

| Token | Hex | | Token | Hex |
|---|---|---|---|---|
| `brand-50` | `#F2F9FC` | | `brand-500` | `#4FA9C4` |
| `brand-100` | `#E4F2F7` | | `brand-600` | `#3D8CA6` |
| `brand-200` | `#ABDAE7` | | `brand-700` | `#2F6E84` |
| `brand-300` | `#8ACCE0` | | `brand-800` | `#284250` |
| `brand-400` | `#67BCD4` | | `brand-900` | `#132A38` |

Navy (`800`/`900`) is the sidebar and dark surfaces. Teal (`400`/`500`) is the
primary action colour. You may introduce neutral greys and semantic colours
(green paid, red overdue, amber warning) — those already exist informally and
should be formalised, not replaced.

**Typography.** Poppins everywhere (400/600/700/800). Caveat is used for exactly
one thing: the signature on the printed invoice. Do not use it anywhere else.

**Touch targets.** Minimum 44px on anything tappable. This is already a convention
across every form and button (`min-h-[44px]`); keep it.

---

## 4. What already exists — reuse it

`resources/views/components/` — grown well past the original 18 since this brief
was first written. Structural/base components:

`application-logo` · `auth-session-status` · `auth-button` · `auth-field` ·
`avatar` · `badge` · `btn` · `card` · `danger-button` · `day-nav` · `empty-state` ·
`icon` · `input-error` · `input-label` · `loader` · `manager-picker` · `modal` ·
`month-nav` · `nav-section` · `page-header` · `permission-matrix` ·
`primary-button` · `secondary-button` · `section-heading` · `section-label` ·
`select` · `sidebar-link` · `stat-card` · `tab-nav` · `text-input` · `textarea` ·
`charts/*` (8 CSS-bar chart partials — bar-list, busiest-days, cashflow-bars,
daily-bars, horizontal-bars, metric-trend, status-pills, work-heatmap — no JS
charting library, and none needed)

Several are in good shape and should set the standard:

- **`avatar`** — deterministic colour from a name hash, initials, three sizes.
- **`badge`** — status → colour/label maps covering invoice and content states,
  with normalisation so `"Video Ready"` and `video_ready` both resolve.
- **`btn`** — the unified button/link this brief originally implied was missing.
  Renders `<a>` when given `href`, `<button>` otherwise; `variant`
  (primary/secondary/danger/ghost/dark), `icon`, `size`. Built because anchors
  styled as buttons were hand-pasted ~30 times before it existed. **Still not
  universally adopted** — some screens (e.g. `portfolio/index.blade.php`) still
  hand-paste the old class strings instead of reaching for it. Adopting `btn`
  everywhere it applies is part of the module-by-module work in §9.
- **`month-nav` / `day-nav`** — the "three duplicated month navigators" this
  brief used to call out are gone; `expenses`, `salaries`, `bills` and friends
  already share `month-nav`. The **pay row** (name, amount input, Pay button,
  paid/unpaid state) mentioned in the same original complaint is *not* extracted
  yet — still duplicated across those same screens. Still open, see §5/§9.

**`card`'s padding problem is fixed; its dark-mode problem isn't.** It already
has real variants — `padding` (none/sm/md/lg) and `tone` (default/brand/muted,
plus now `dark`) — pushed into the component rather than re-specified per caller.
Some older call sites still bypass the prop with raw `p-*` classes (a genuine,
smaller cleanup item, not the structural problem it used to be). The tone that
was actually missing until this pass was `dark` — see "the dark-surface gap"
below, now the more important of the two remaining `card` issues.

### The dark-surface gap

`dashboard.blade.php` is the *only* screen in the app that opts into
`<x-app-layout dark>`, and until this pass it got there by reimplementing
everything locally instead of using shared components: its own
`$cardClass = 'rounded-xl bg-white/5 ring-1 ring-white/10'` instead of
`<x-card tone="dark">`, its own bespoke header block instead of
`<x-page-header dark>`, its own section-label classes at `tracking-[0.16em]`
instead of `<x-section-label dark>` (which standardises on `tracking-wider`,
same as the light-mode eyebrow). `card`, `stat-card`, `page-header` and the new
`section-label` all now carry a `dark` prop/tone matching Dashboard's own
existing look exactly — but **Dashboard itself has not been migrated to use
them yet**. That migration is the highest-value item at the top of §9's
module-by-module list: it is the one screen already paying the cost of this gap,
and the only current consumer any of these dark variants would serve.

Layouts live in `resources/views/layouts/` — `app` (sidebar shell), `guest` (auth
pages), `sidebar` (nav, shared by the desktop rail and the mobile drawer, itself
driven by the `App\Support\Permission::MODULES` registry — extend that registry
rather than hand-editing nav markup when a module's presence in the sidebar needs
to change).

---

## 5. Screen inventory and known weaknesses

Originally 57 Blade views; the app has grown well past that since. Grouped by
area, with what specifically needs fixing. The first block (Landing through
Admin) is the original inventory, mostly unchanged. Everything under "Built
since this brief" (§5b) is new and has never been audited against it.

### Landing and auth
`landing.blade.php` · `auth/login` · `auth/forgot-password` · `auth/reset-password` ·
`auth/confirm-password` · `auth/verify-email` · `layouts/guest`

The landing page is standalone HTML that does not use `layouts/guest`, so its header
and footer duplicate markup. The auth pages are still close to stock Breeze and do
not look like the rest of the product.

### Dashboard
`dashboard.blade.php` — uses `x-stat-card` six times. This is the *only* place
`stat-card` is used; every expense module hand-rolls its own stat tile out of
`x-card`. Those should be the same component.

### Invoices
`invoices/index` · `show` · `create` · `edit` · `_form` · `document`

`index` uses the good responsive pattern: a mobile card list plus a
`hidden md:block` table over the same data. Adopt this pattern elsewhere.
`_form` is the most complex screen in the app — an Alpine line-item editor with
live totals. `document` is the printed invoice and has its own section below.

### Clients
`clients/index` · `show` · `create` · `edit` · `_form`

Same dual mobile/desktop pattern as invoices. `show` combines a contact card with
invoice history.

### Recurring
`recurring/index` · `create` · `edit` · `_form`

`_form` duplicates most of the invoice line-item editor, including its Alpine
component. Consider extracting the shared editor.

### Expenses — the worst offender
`expenses/index` (combined month overview) · `emi/index` + `_form` ·
`salaries/index` + `show` + `_form` · `bills/index`

Three separate month navigators (`expenses`, `salaries`, `bills`) with **identical
duplicated markup** — prev / month label / next. Extract one component.
The pay row — name, amount input, Pay button, paid/unpaid state — is duplicated
three times with small drifts. Same treatment.
`emi/index` carries the richest UI: progress bars, per-bank breakdown, and a CSS-bar
payoff timeline. Keep the timeline; it is genuinely useful and needs no library.

### Admin
`users/index` · `users/create` · `settings/edit` · `profile/edit` + three partials

The plainest screens in the app; `users/index` is an unstyled table.

---

## 5b. Built since this brief — not yet audited

None of the below existed when this brief was written. Bring each under the same
system in §9's module-by-module pass; nothing here has been deliberately
redesigned yet except where noted.

### Portfolio (the website's public work)
`portfolio/index` (+`_form`, `_case-study-fields`) · `portfolio-detail` ·
`portfolio-categories/*` · public `portfolio.blade.php`/landing grid partial

**Already had several redesign-adjacent passes** — thumbnails render at the
piece's real aspect ratio (9:16 for a Reel, not force-cropped to 16:9), new
pieces default to 9:16/Instagram Reels, the admin screen has a "Worth adding"
strip surfacing high-performing un-added Instagram posts, Instagram-linked
pieces auto-fill description/platform/format from the synced post, and the
admin form's Instagram-picker loading state already uses `<x-loader>`. Audit
against the rest of the system rather than treating as untouched — likely
needs less work than the other modules in this section.

### Shoots and production
`shoots/index` · `show` · `create/edit` · `call-sheet` · crew/kit sub-views ·
`equipment/index` · `scripts/index` + editor + sections

Kit check-out/check-in and the crew list are the least visually settled parts;
`call-sheet` is a distinct printed-adjacent screen (not dompdf, but should be
scannable at a glance on a phone at the actual shoot).

### Work tracking
`todos/index` (team) · `my/todos` · `my/timesheet` · `my/calendar` ·
`timesheets/index` + `show`

Todos already use `<x-stat-card>` and a `.stagger` entrance — one of the more
current-feeling screens; timesheets/admin review is plainer.

### Studio
`announcements/index` · `enquiries/index` + `show` · `taxonomy/index`
(master data, many list types behind one screen)

`taxonomy/index` is a generic CRUD-list-of-lists screen and is a good early
candidate for whatever the "one list screen" pattern ends up being, since
several other modules (categories, taxonomy terms) are structurally identical.

### Setup (admin-only integrations)
`settings/edit` · `whatsapp/edit` · `instagram-settings/edit` · `notion/edit` ·
`push/edit` · `content-accounts/edit` · `brief-questions/index` ·
`invoice-template/edit`

Eight screens, one repeated shape (connection status, a form, a "send test"
action) that has never been factored into a shared pattern — each was built
independently as its integration landed.

### Client portal and employee "My work"
`client/dashboard` · `client/invoices` · `client/work` · `client/shoots` ·
`client/brief` · employee `my/dashboard`

Two audiences this brief's original "who uses it" section didn't cover: clients
(no module permissions, a narrower nav) and the day-to-day employee view (not
the admin `dashboard.blade.php` covered in §5's Dashboard section — a separate,
simpler screen).

---

## 6. The printed invoice — different rules entirely

`resources/views/invoices/document.blade.php` is rendered by **dompdf, not a
browser**. It is also the only thing in this product a client ever sees. The
following are not style preferences; ignoring them produces broken PDFs.

### dompdf will silently betray you

- **No flexbox.** Use tables for layout.
- **`box-sizing: border-box` is unreliable.** Do not depend on it.
- **`position: absolute` ignores `right`.** Use `left` plus an explicit `width`.
- **A `height` or `min-height` on the page element emits a blank second page.**
  It rounds over the A4 box. Never set one.
- **Fonts and images must be base64 data URIs**, via `App\Support\Fonts::dataUri()`
  and `App\Support\Assets::image()`. A URL or filesystem path will not render.

### The current bottom-strip arithmetic

`.page-content` is `padding: 16mm 14mm 40mm`. That 40mm is not arbitrary — it
reserves the strip occupied by two `position: fixed` elements:

- the footer bar at `bottom: 0`, about 17.7mm tall
- the signature block at `bottom: 23mm`

Both are fixed so they sit at the foot of the page regardless of how many line items
there are. Change the bottom padding and you will push a normal invoice onto a second
page — an earlier version did exactly that with a five-line invoice.

### The one functional change you must make

The items table currently prints **two columns: `Items` and `Rate`** — and that
"Rate" column actually renders `line_total`, not the unit rate. `quantity` and
`unit_price` are captured in the form and stored on `invoice_items`, but never
printed. So a line of 2 × 2,500 shows only `5,000`, under a heading that says Rate.

Redesign the table to print **Item · Qty · Rate · Amount**, reading `quantity` and
`unit_price` off each item. Keep it legible on A4 at 210mm wide — the description
column carries most of the weight. Use judgement on single-quantity lines (showing
`1` is fine; suppressing it is also fine — pick one and be consistent).

The discount row and the total box both hang off the bottom of this table; keep them
visually joined to it.

---

## 7. Engineering guardrails

**1,079+ tests currently pass (this number climbs with every feature — check
`php artisan test`'s own summary line rather than trusting a number written down
here). They must still pass.**

`tests/Feature/InvoicePdfLayoutTest.php` decodes the generated PDF binary and
asserts page count, that the footer sits flush at y=0, that the page is white edge
to edge, that the total box is joined to the items table, and that the signature
stays anchored regardless of item count. If your redesign breaks the print layout,
these will fail — that is the safety net working, not an obstacle to route around.
Update the assertions only when you have deliberately and correctly changed the
design, never to make a failure disappear.

**Running the tests.** `tests/TestCase.php` refuses to run against anything but
sqlite `:memory:`. A cached config silently overrides `phpunit.xml`, which would
otherwise point `RefreshDatabase` at the live MySQL database and wipe it. So:

```bash
php artisan optimize:clear
php artisan test
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Tailwind JIT only sees literal class strings in scanned files.** Two traps:

- Classes assembled inside an Alpine expression are not extracted. An
  `!important`-prefixed class built this way (`'!w-12'`) was silently never
  generated. For dynamic sizing, bind an inline `:style` instead.
- Any new class combination needs `npm run build` before it exists in the CSS.

**`[x-cloak]`** is defined in `resources/css/app.css`. Put it on anything Alpine
hides, or it renders for a frame before Alpine boots.

---

## 8. Definition of done

- One visual system across every view in §5 and §5b — consistent spacing scale, one card
  treatment, one stat tile, one month navigator, one pay row.
- Verified at **420px first**, then desktop.
- `x-card`, `x-stat-card` and the new shared components carry the styling decisions;
  callers stop re-specifying padding.
- The printed invoice shows Qty and unit Rate, still renders as a single A4 page,
  and still passes the PDF layout tests.
- Full suite green (see §7 for why not to hardcode the count). No new
  dependencies. No changed business logic.

## 9. Suggested order

1. ~~Settle the design tokens and fix `x-card` / `x-stat-card` first~~ — done.
   `card` has `padding`/`tone` (including `dark`) variants, `stat-card` has
   dark-aware accents, `page-header` has a `dark` prop, and a new
   `section-label` component standardises the uppercase-tracked micro-label
   idiom. All additive so far — see step 2, the first thing that actually
   spends them.
2. **Migrate `dashboard.blade.php` to the shared dark variants** — the one
   screen currently paying for the dark-surface gap described in §4, and the
   only current consumer any of step 1's dark variants would serve. Do this
   before anything else below; it is what makes step 1 real rather than
   unused capability.
3. Extract the pay row (§5, still open — the month navigator half of this step
   is already done via `month-nav`/`day-nav`).
4. Work module by module: invoices → clients → recurring → expenses/salaries
   (adopting `btn` consistently as you go, not just `card`) → §5b's modules
   (Shoots, work tracking, Studio, Setup's eight screens, client portal and
   employee "My work") — audit Portfolio rather than rebuilding it, per its
   note in §5b.
5. Do the printed invoice last, on its own, and run the PDF tests after every change.
6. Landing and auth pages last.
