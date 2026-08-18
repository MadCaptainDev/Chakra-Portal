# Monthly SMM report

A per-client Instagram monthly report: an in-app studio screen with a
Studio/Client on-screen preview, and a downloadable 3-page A4 PDF built
from the same cached data. Studio-only — there is no client-facing link;
delivery is the PDF, handed over however the studio already sends things.

Reachable from a connected client's **Social Media** card ("Monthly
Report") or from their **Instagram Analytics** screen ("Monthly report").

## 1. Where the numbers come from

Everything on the report is read from the same local caches the Instagram
Insights screen already uses (`social_insights`, `social_media_items`) —
nothing here calls Instagram on a page load. `InstagramInsightsController`'s
data-computation methods (overview, engagement breakdown, growth trend, top
posts) were extracted verbatim into `App\Services\Instagram\InstagramReportData`
so the Insights screen and the report always agree; `App\Services\MonthlyReportData`
adds the report-specific pieces (audience, publishing-format counts,
shoots) on top.

**Format counts** use the same type label the Content Performance table
already shows (Reel/Carousel/Video/Photo) — no separate categorisation.
There is no "Stories" count: Instagram's `/media` edge (what `syncMedia()`
reads) does not return stories, so there is no honest number to show.

**"Sync now"** on the report screen is the *same* button/route as the
Instagram Insights screen — not a second sync entry point, same throttle.

## 2. Audience demographics (age, gender, city)

New sync, new storage — this did not exist before this feature. Confirmed
empirically against both live connected accounts before writing any code:

```
GET {ig-user-id}/insights
  ?metric=follower_demographics&period=lifetime&metric_type=total_value
  &breakdown=age,gender        (one call, a joint distribution)
```
```
  &breakdown=city               (a SEPARATE call — Meta does not accept
                                  all three dimensions in one request)
```

Both answer as `data[0].total_value.breakdowns[0]`, i.e.
`{dimension_keys: [...], results: [{dimension_values: [...], value: int}]}`.
Gender codes are `M`/`F`/`U`. Meta's own `title`/`description` fields on
the response came back **in Russian** on one account during testing
(evidently locale-dependent on something outside our control) — the app
never renders them, only `dimension_values`/`value`.

Stored in `social_audience_snapshots`, one row per account per dimension
(`age_gender` | `city`), the raw `results` array as JSON. A **current
snapshot**, not a time series — refreshed (overwritten) on every sync,
same as the rest of the account. `App\Models\SocialAudienceSnapshot`
collapses the joint age×gender distribution into the two separate
percentage lists the report shows (`ageBreakdown()`/`genderBreakdown()`),
and ranks `city` by raw follower count (`topCities()`).

If an account has never been synced since this feature shipped, the
report shows an empty state rather than a stale or fabricated breakdown.

## 3. The note

The "month in one paragraph" text is authored by staff, not derived from
Instagram — `MonthlyReportNote`, one row per client per calendar month
(`monthly_report_notes`), so an old month's report is never silently
overwritten by whatever the current month's textarea holds. Saved via its
own route (`instagram.report.note`, `module:clients,edit`); the client
only ever sees it inside the PDF.

## 4. PDF generation

Uses the same mechanism as invoice PDFs — `barryvdh/laravel-dompdf`,
already installed and already proven to work reliably on this host (no
queue, no cron, `symlink()` disabled): `App\Services\MonthlyReportDocumentRenderer`
builds one standalone HTML document (own `<style>`, no Tailwind, no app
layout), with the studio logo and Poppins embedded as base64 data URIs
(`App\Support\Assets`/`App\Support\Fonts`) so nothing depends on a
`public/storage` symlink. Three fixed `.page` divs (`210mm` × `297mm`,
`page-break-after: always`) reproduce the report's exact 3-page layout —
hero + KPIs + growth chart on page 1, engagement breakdown + top posts on
page 2, audience + publishing + shoots on page 3.
`MonthlyReportController::pdf()` calls `Pdf::loadHTML($html)->setPaper('a4')->download(...)`,
the exact one-liner `InvoiceController::pdf()` already uses.

## 5. Production notes

- `php artisan instagram:sync` (or "Sync now") refreshes the audience
  snapshot alongside the account/media sync it already did — no separate
  command or entry point.
- A skipped audience call (an account under whatever threshold Meta
  applies, or a permissions issue) is surfaced in the same "Not available
  for this account: …" message the sync already shows for any other
  unsupported metric, not a silent failure.
- See `docs/INSTAGRAM.md` for the OAuth/connection side of the Instagram
  integration this report is built on top of.
