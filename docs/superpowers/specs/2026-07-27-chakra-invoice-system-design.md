# Chakra Productions Invoice System — Design

Date: 2026-07-27

## Background

The user provided `Chakra_Invoice_A4.html`, a print-ready A4 invoice mockup for **Chakra Productions** billing a client ("Thor Gym"). Investigation of the file found the entire invoice body is a single inline SVG (~139KB) where **all text has been converted to vector outline paths** — a search for the literal string "text" (case-insensitive) across the whole file returns zero matches, and there are no `<text>`/`<tspan>` elements at all, only 88 `<path>` elements. This is typical of an export from a design tool (e.g. Canva/Illustrator/Figma) with "convert text to outlines" applied to preserve exact fonts without embedding font files.

Rendering the file (headless Chrome screenshot) shows the actual design:

- Top-left: "CHAKRA PRODUCTIONS" logo (stylized wordmark + film camera icon), teal brand color
- Top-right: large light-teal "INVOICE" heading with a faint tricolor camera-icon watermark behind it, and the invoice date
- "Quotation to:" block — client name + address
- "Dear Client" + a short intro paragraph (varies per invoice)
- Items table: teal header row ("Items" / "Rate"), each row is a description + flat amount (no quantity column in the sample)
- An optional italic discount row (e.g. "First Month Discount" / "-6000")
- A boxed "TOTAL: X/-" line
- Signature block: script-font signature image, "Name & Signature", title ("CEO")
- Full-width teal footer bar: "ThankYou For Your Buisness With Us !"

**Implication:** because there is no live text in the source file, it cannot be wired up to dynamic data directly. The system will rebuild the same visual design as a real Blade/CSS template with live HTML text bound to the database, reusing the logo and watermark graphic as cropped PNG image assets (recreating the stylized logo font in CSS is impractical).

Goal for this phase: a working Laravel CRUD system for creating, editing, and PDF-exporting invoices with editable prices/line items/client details, laying groundwork the user can later extend toward "automatic" invoice generation. Automating invoice creation itself (e.g. recurring/scheduled invoices, external triggers) is explicitly out of scope for this phase.

## Tech stack

- Laravel (latest stable, installed via Composer), PHP 8.2
- Laravel Breeze (Blade + Tailwind stack) for authentication scaffolding
- MySQL via the existing local XAMPP install
- barryvdh/laravel-dompdf for PDF export
- Tailwind CSS for all views (auth, dashboard, CRUD forms, and the invoice template itself)
- Vanilla JS (no frontend framework) for live add/remove line-item rows and live total calculation on the invoice form

## Access model

Any authenticated user (staff) can create/edit/delete clients and invoices, and edit company settings. There are no role tiers (admin vs staff) in this phase — all logged-in accounts are equal. Registration uses Breeze's default self-service register page; there is no invite-only flow in this phase.

## Data model

### `users` (Breeze default)
Standard `id, name, email, password, timestamps`.

### `company_settings`
Single-row table (always id=1) holding the identity fields the user wants to be able to change without touching code:
- `company_name` (string, default "Chakra Productions")
- `logo_path` (string, nullable — path under storage/public)
- `address` (string, nullable)
- `signature_name` (string, default "Annamalai Sivakumar")
- `signature_title` (string, default "CEO")
- `invoice_prefix` (string, default "CP-")
- `footer_text` (string, default "ThankYou For Your Buisness With Us !")

Seeded with the defaults above on first migrate, editable via a Settings page.

### `clients`
- `id`
- `name` (string, required)
- `address` (string, nullable)
- `email` (string, nullable)
- `phone` (string, nullable)
- timestamps

### `invoices`
- `id`
- `invoice_number` (string, unique, auto-generated as `{invoice_prefix}{zero-padded sequence}`, e.g. `CP-0001`)
- `client_id` (FK → clients)
- `invoice_date` (date, defaults to today, editable)
- `intro_text` (text, nullable — the "Dear Client, ..." paragraph, defaults to a generic sentence, editable per invoice)
- `discount_label` (string, nullable)
- `discount_amount` (decimal, nullable)
- `subtotal` (decimal — sum of line item totals, stored redundantly for fast listing)
- `total` (decimal — subtotal minus discount, stored redundantly)
- `created_by` (FK → users)
- timestamps

Subtotal/total are recalculated server-side from line items whenever an invoice is saved (not trusted from client input).

### `invoice_items`
- `id`
- `invoice_id` (FK → invoices, cascade delete)
- `description` (string, required)
- `quantity` (decimal, default 1)
- `unit_price` (decimal, required)
- `line_total` (decimal, computed = quantity × unit_price, stored)
- `sort_order` (integer, for display ordering)

## Invoice numbering

Auto-generated on create: take the current max numeric suffix among existing `invoice_number`s (or 0 if none), increment, zero-pad to 4 digits, prefix with `company_settings.invoice_prefix`. Generated at save time inside a DB transaction to avoid race duplicates; not user-editable after creation.

## Pages / routes

All routes below require authentication (`auth` middleware) except login/register.

- `GET /` → redirect to dashboard if logged in, else login
- `GET /dashboard` → list of invoices (client name, date, total, status of paid/unpaid not tracked in this phase — just list + search by client name/invoice number), links to create/view
- **Clients**: `GET/POST /clients`, `GET/PUT /clients/{id}/edit`, `DELETE /clients/{id}` — simple list + form (name/address/email/phone)
- **Invoices**:
  - `GET /invoices/create` — form: client picker (select existing or "+ add new client" inline), invoice date, intro text, dynamic line-item rows (add/remove via JS, each with description/qty/unit price, auto-computed line total and running subtotal/discount/total shown live), optional discount label+amount
  - `POST /invoices` — validates and stores invoice + items, computing totals server-side, generates invoice_number
  - `GET /invoices/{id}` — the actual invoice view, rendered in the Chakra Productions visual design
  - `GET /invoices/{id}/edit`, `PUT /invoices/{id}` — same form as create, pre-filled
  - `DELETE /invoices/{id}`
  - `GET /invoices/{id}/pdf` — streams a PDF download of the same design via dompdf
- **Settings**: `GET /settings`, `PUT /settings` — edit company_settings fields incl. logo upload

## Invoice template rendering

A single Blade partial (`resources/views/invoices/_document.blade.php`) renders the invoice visual design from an `$invoice` (with eager-loaded `client` and `items`) and the `company_settings` singleton. Both the web "view" page and the PDF export reuse this same partial so they never drift out of sync — the PDF route just wraps it in dompdf instead of the app layout.

Visual elements:
- Logo: `<img>` tag pointing at a cropped PNG extracted from the original artwork, stored in `public/images/`
- Watermark: same approach — cropped background camera-icon PNG, absolutely positioned behind the "INVOICE" heading
- All text (client name/address, date, intro paragraph, item rows, discount, total, signature name/title, footer) is live Blade-rendered HTML/CSS, styled with Tailwind utility classes (or a small scoped `<style>` block for the print-specific layout) to match the teal color scheme and A4 proportions of the original
- Print/PDF CSS carries over the original's `@page { size: A4; margin: 0; }` approach

## Validation & error handling

- Standard Laravel form request validation (required fields, numeric/min:0 on prices/quantities, at least one line item required)
- Deleting a client that has invoices is blocked with a friendly validation error (foreign key restrict, not cascade) — invoices are financial records and shouldn't disappear silently
- No soft-deletes in this phase (plain deletes) since there's no requirement for an audit trail yet

## Testing plan

- Feature tests (Laravel's default Pest/PHPUnit, whichever Breeze scaffolds) covering:
  - Invoice creation computes correct subtotal/total from line items and discount
  - Invoice number auto-increments correctly and is unique
  - Client deletion is blocked when invoices reference it
  - Unauthenticated requests to any invoice/client/settings route redirect to login
  - PDF route returns a PDF response (content-type check) for an existing invoice
- Manual verification: create a real invoice through the browser end-to-end (matching the original sample's Thor Gym data) and visually compare the rendered view and downloaded PDF against the original `Chakra_Invoice_A4.html` screenshot for fidelity

## Out of scope (this phase)

- GST/tax calculation
- Multiple/repeating discount or surcharge lines (only one optional discount line)
- Invoice status tracking (paid/unpaid/overdue)
- Emailing invoices to clients
- Role-based permissions
- Any automatic/recurring invoice generation (explicitly deferred to a later phase per the user's request)
