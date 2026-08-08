# Chakra-Portal

Internal business portal for **Chakra Productions** — invoicing, recurring billing,
client records, and everything the studio pays out each month.

Built with Laravel 12, Blade, Alpine.js and Tailwind CSS. Runs on PHP 8.2+ and MySQL.

## Modules

| Module | What it does |
|---|---|
| **Invoices** | Create, approve and download PDF invoices. Status tracking (unpaid / paid / overdue), part-payments, and one-click duplication. |
| **Recurring** | Billing schedules that generate invoices on their own. Generated invoices always land as *pending approval* with no invoice number — nothing goes out without a human approving it. |
| **Clients** | Contact records with full invoice history. |
| **Expenses** | Combined month view of total outflow. |
| **EMI** | Asset finance: outstanding liability, per-bank exposure, progress against each schedule, and a month-by-month payoff timeline. |
| **Salaries** | Monthly payroll run plus employee records — role, joining date, contact, and payment history. |
| **Bills** | Recurring costs tracked as budget vs what was actually paid. |
| **Users** | Staff logins. Public registration is closed; accounts are created from inside the portal. |

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Point `DB_*` in `.env` at a MySQL database, then:

```bash
php artisan migrate
php artisan db:seed --class=ExpenseSeeder
npm run build
php artisan serve
```

## Invoice PDFs

PDFs are rendered with dompdf from the active **PDF Template** (sidebar → PDF Template).

You can design the layout in two ways:

1. **Drag blocks** — reorder / toggle sections (header, client, items, total, signature, …)
2. **HTML editor** — edit the page body with placeholders like `{{client_name}}`, `{{items_table}}`, `{{total}}`

Live preview uses a real invoice when one exists. Company branding (logo, signature, footer text) still comes from **Settings**.

dompdf is far stricter than a browser. If you touch CSS in the template editor, be aware:

- No flexbox, and `box-sizing: border-box` is unreliable.
- `position: absolute` ignores `right` — use `left` plus an explicit `width`.
- A `height` or `min-height` on the page element silently emits a blank second page.
- The bottom padding on `.page-content` reserves the strip used by the fixed footer
  and signature. Raising it pushes a normal invoice onto a second page.

`tests/Feature/InvoicePdfLayoutTest.php` guards all of this by decoding the generated
PDF itself — page count, footer position, and the total box sitting flush against the
items table — because none of it is visible from the HTML.

The classic Blade file `resources/views/invoices/document.blade.php` is kept as a
reference; live downloads use `App\Services\InvoiceDocumentRenderer` and the block
partials under `resources/views/invoices/blocks/`.


## Testing

```bash
php artisan test
```

The suite runs against in-memory SQLite. `tests/TestCase.php` refuses to run against
anything else, which stops `RefreshDatabase` from wiping a real database when a cached
config overrides `phpunit.xml`. If it aborts, run `php artisan config:clear` first (and
`php artisan config:cache` again afterwards).

## Deployment

Pushing to `main` deploys to Hostinger automatically: GitHub Actions builds the
assets and runs the tests, triggers Hostinger's git pull, then finishes the deploy
over SSH (`composer install --no-dev`, migrations, cache rebuild). The required
secrets and the one-time hPanel setup are in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Scheduled work

Recurring invoice generation runs from the scheduler:

```bash
php artisan schedule:run
```

Run that every minute via cron, or Windows Task Scheduler. A catch-up middleware
regenerates anything missed on the first request of the day, so a machine that was
switched off does not silently skip a billing run.

## Notion content sync (disabled)

An earlier module pulled content-planner data from Notion. It is switched off — the
service, the `notion:sync-content` command and the synced rows are all still present;
only the schedule entry and the UI are disabled.
