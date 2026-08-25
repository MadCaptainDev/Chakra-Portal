# Content Dashboard — Pipeline Tracking Redesign

**Date:** 2026-08-24  
**Status:** Approved for implementation  
**Scope:** Content Dashboard index and show views — data layer and UI

## Problem

The current Content Dashboard only counts items with `status = Published`. Planned content (To Be Edited, Video Ready, Scheduled, Under Review) with a future `published_date` in the month is invisible. Users cannot see what's coming — only what already went live.

Example: On Aug 24, the dashboard shows 199 published items. But 21 more items are dated Aug 25–31 in statuses like "To Be Edited" — these are invisible, making the month look lighter than it actually is.

## Solution

Show ALL items with `published_date` in the selected month, grouped by pipeline stage. The dashboard becomes a tracking view: what's done, what's in progress, what's scheduled.

## Data Layer Changes

### `ContentDashboard.php`

**Current behavior:**
```php
->where('status', 'Published')
->whereNotNull('published_date')
->whereBetween('published_date', [$since, $until])
```

**New behavior:**
```php
->whereNotNull('published_date')
->whereBetween('published_date', [$since, $until])
->where('status', '!=', 'Canceled') // for totals; canceled counted separately
```

### New Methods

1. `pipelineForMonth(Carbon $month): array` — returns counts by status group:
   - `published`: status = Published
   - `scheduled`: status = Scheduled
   - `in_progress`: status in (Video Ready, Under Review, Edit in Progress, To Be Edited, To Be Shooted)
   - `idea`: status = Idea
   - `canceled`: status = Canceled
   - `planned`: total non-canceled (published + scheduled + in_progress + idea)

2. `countsByAccountIncludingPipeline(Carbon $month): array` — same as `countsByAccount` but without the Published filter, returns status breakdown per account.

3. Update `itemsFor()` to accept optional `$statuses` filter array.

### Status Groups (from config/notion.php boards)

```php
const STATUS_GROUPS = [
    'published' => ['Published'],
    'scheduled' => ['Scheduled'],
    'in_progress' => ['Video Ready', 'Under Review', 'Edit in Progress', 'To Be Edited', 'To Be Shooted'],
    'idea' => ['Idea'],
    'canceled' => ['Canceled'],
];
```

## UI Changes

### Index View (`content-dashboard/index.blade.php`)

#### 1. Pipeline Stat Cards (replace type totals)

New top section with 4 primary cards + 1 secondary:

| Card | Color | Value | Subtext |
|------|-------|-------|---------|
| Published | green | count | "Live this month" |
| In Progress | amber | count | "Being edited/reviewed" |
| Scheduled | gray | count | "Ready to post" |
| Planned Total | brand-500 | count | "Excl. canceled" |
| Canceled | red (smaller) | count | — |

Grid: `grid-cols-2 lg:grid-cols-5`

#### 2. Filter Bar

Below stat cards, a horizontal chip bar:

```blade
<div class="flex flex-wrap gap-2">
    <x-filter-chip active>Published</x-filter-chip>
    <x-filter-chip active>In Progress</x-filter-chip>
    <x-filter-chip active>Scheduled</x-filter-chip>
    <x-filter-chip>Canceled</x-filter-chip>
</div>
```

Alpine.js toggles; filters client-side or reloads with query params.

#### 3. Table Row Changes

Each account row gains:
- **Status breakdown column** (replaces or augments "Total"): shows `published / planned` e.g. "12 / 18"
- **Progress bar** now compares published vs planned (not target)
- Existing target comparison stays but moves to tooltip or secondary line

#### 4. Per-Type Columns

Keep Reel / Post / YouTube / Story columns. Each cell shows:
- `published / planned` for that type (e.g., "3 / 5")
- Color: green if published = planned, amber if in progress, red if behind

### Show View (`content-dashboard/show.blade.php`)

#### 1. Pipeline Cards for Account

Same 4-card layout but scoped to this account's items.

#### 2. Status Badge per Item

Table gains status column with `<x-badge>` colored by status group:
- Published → green
- Scheduled → gray
- Video Ready / Under Review → purple
- Edit in Progress / To Be Edited → amber
- To Be Shooted → yellow
- Canceled → red

#### 3. Filter Chips

Same as index — toggle which statuses appear in the table.

### New Component: `x-filter-chip`

```blade
@props(['active' => false])
<button {{ $attributes->merge([
    'class' => 'px-3 py-1.5 text-xs font-semibold rounded-full transition ' .
        ($active
            ? 'bg-brand-500 text-white'
            : 'bg-gray-100 text-gray-600 hover:bg-gray-200')
]) }}>
    {{ $slot }}
</button>
```

## Routes

No new routes. Existing routes gain optional `?status=` query param (comma-separated).

## Migration

None required — `status` column already exists and is populated by Notion sync.

## Mobile Considerations

- Stat cards: 2×2 grid on mobile
- Filter chips: horizontal scroll with `-mx-4 px-4` bleed
- Table: existing card collapse pattern continues to work
- Touch targets: chips are 44px tall

## Out of Scope

- Board/Kanban view (future enhancement)
- Calendar view (future enhancement)  
- Drag-and-drop status changes
- Target editing from dashboard
- Changing what Notion sync pulls

## Test Plan

1. Verify index shows items with future `published_date` in current month
2. Verify pipeline counts match raw DB query
3. Verify filter chips toggle rows correctly
4. Verify show view displays correct status badges
5. Verify canceled items excluded from planned total but visible when filter enabled
6. Mobile: verify 2×2 stat cards and horizontal chip scroll
7. Existing tests in `ContentDashboardTest.php` still pass (may need adjustment for new counting)

## Files to Modify

1. `app/Support/ContentDashboard.php` — data layer
2. `resources/views/content-dashboard/index.blade.php` — index UI
3. `resources/views/content-dashboard/show.blade.php` — show UI
4. `resources/views/components/filter-chip.blade.php` — new component
5. `resources/views/components/badge.blade.php` — add status color variants if missing
6. `tests/Feature/ContentDashboardTest.php` — update/add tests
