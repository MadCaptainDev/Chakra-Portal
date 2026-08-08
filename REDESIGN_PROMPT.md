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

`resources/views/components/` holds 18 components:

`application-logo` · `auth-session-status` · `avatar` · `badge` · `card` ·
`danger-button` · `empty-state` · `input-error` · `input-label` · `modal` ·
`page-header` · `primary-button` · `secondary-button` · `select` · `sidebar-link` ·
`stat-card` · `text-input` · `textarea`

Two are in good shape and should set the standard:

- **`avatar`** — deterministic colour from a name hash, initials, three sizes.
- **`badge`** — status → colour/label maps covering invoice and content states,
  with normalisation so `"Video Ready"` and `video_ready` both resolve.

**`card` is the problem.** It is a bare `<div>` with one merged class, so every
caller re-specifies its own padding. Grep for `x-card` and you will find `p-4`,
`p-4 sm:p-6`, `p-3 sm:p-4` and `divide-y divide-gray-200` all in use. Give it real
variants and push the padding decision into the component.

Layouts live in `resources/views/layouts/` — `app` (sidebar shell), `guest` (auth
pages), `sidebar` (nav, shared by the desktop rail and the mobile drawer).

---

## 5. Screen inventory and known weaknesses

57 Blade views. Grouped by area, with what specifically needs fixing.

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

**126 tests currently pass. They must still pass.**

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

- One visual system across all 57 views — consistent spacing scale, one card
  treatment, one stat tile, one month navigator, one pay row.
- Verified at **420px first**, then desktop.
- `x-card`, `x-stat-card` and the new shared components carry the styling decisions;
  callers stop re-specifying padding.
- The printed invoice shows Qty and unit Rate, still renders as a single A4 page,
  and still passes the PDF layout tests.
- 126 tests green. No new dependencies. No changed business logic.

## 9. Suggested order

1. Settle the design tokens and fix `x-card` / `x-stat-card` first — everything else
   inherits from them.
2. Extract the duplicated month navigator and pay row.
3. Work module by module: dashboard → invoices → clients → recurring → expenses.
4. Do the printed invoice last, on its own, and run the PDF tests after every change.
5. Landing and auth pages last.
