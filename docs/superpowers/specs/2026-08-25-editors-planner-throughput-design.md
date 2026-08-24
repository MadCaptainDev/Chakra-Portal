# Editor Output — Planner-Separated Throughput

**Date:** 2026-08-25  
**Status:** Approved (user: “Dont Ask me, go with your Recommendations”; Approach 1)  
**Scope:** Admin `/editors` (`EditorOutputController`, `EditorThroughput`, `resources/views/editors/index.blade.php`). Suspect-timesheet section stays; only its relationship to rates changes (no blended per-item rate to taint).

## Goal

Show editor output as **counts by Notion planner** (Reel, Post, Story) beside **editing hours from the timesheet**, with the **current month first and highlighted**. Stop treating every planner row as one interchangeable “item,” and stop using a single minutes-per-item rate.

## Locked decisions

| Topic | Choice |
|--------|--------|
| Video planners | **Reel only** on this screen. YouTube rows are excluded (same videos as Reel). |
| Planners shown | `reel`, `post`, `story` only |
| Current month | Keep multi-month window (`?months=3|6|12`); **current month first + highlighted**; older months below |
| Hours vs counts | One **Editing time** total per editor / month beside the three counts — do **not** invent hours-per-planner |
| Flat “items” | Removed as the headline metric. No `items`, `minutesPerItem`, `itemsPerDay`, or studio `totalItems` as a single blended figure |
| Tier mix | Keep as secondary context under the counts (effort mix ≠ type mix) |
| Co-edited / blank editor | Unchanged: shared (comma) names excluded from per-person rates; blank editors counted only in studio-level planner totals if shown |
| Name join | Unchanged: first-word, case-insensitive Notion ↔ portal |
| Permissions | Admin-only `/editors` unchanged |
| Stack | Blade, Alpine (existing), Tailwind, brand tokens; no React/new CDN |

## Approach

**Planner columns on the same cards** (Approach 1): reshape `EditorThroughput` + `editors/index` rather than a two-pane or month-first board rebuild.

## Architecture

| Piece | Responsibility |
|--------|----------------|
| `EditorThroughput` | Load published `ContentItem`s in range; **exclude `source = youtube`**; group per editor and per month with `byPlanner: { reel, post, story }` counts + editing minutes; expose current-month key for UI highlighting |
| `EditorOutputController` | Unchanged period math (`months` query); pass throughput as today |
| `editors/index.blade.php` | Per-editor: Reel / Post / Story counts + Editing time + optional tier bar; Month table: current month first/highlighted, planner columns, editing time; drop per-item headline |
| `EditorOutputTest` | Assert YouTube excluded; planner counts; no reliance on blended `items` as primary API; months ordered with current month first in the returned collection **or** UI sorts — prefer data ordered newest-first with current month at index 0 when window includes today |

### Throughput shape (contract)

`EditorThroughput::between($from, $to)` returns (evolved):

```php
[
  'from' => Carbon,
  'to' => Carbon,
  'currentMonthKey' => 'Y-m', // today()->format('Y-m')
  'rows' => Collection of [
    'key', 'label', 'user',
    'byPlanner' => ['reel' => int, 'post' => int, 'story' => int],
    'tiers' => [...],           // still over included items only
    'minutes' => int,
    'days' => int,
    'hoursPerDay' => ?float,
    // REMOVED: items, minutesPerItem, itemsPerDay, hardShare (optional: hardShare may remain if useful; prefer drop if UI no longer shows it)
  ],
  'months' => Collection newest-first of [
    'key', 'label', 'short',
    'isCurrent' => bool,
    'byPlanner' => ['reel' => int, 'post' => int, 'story' => int],
    'minutes' => int,
    'tiers' => [...],
    'editors' => [... same byPlanner + minutes ...],
    // REMOVED: items as sole published column
  ],
  'shared' => int,              // co-edited rows still excluded from people
  'unattributedItems' => int,   // blank editor among included sources
  'byPlannerTotals' => ['reel' => int, 'post' => int, 'story' => int],
  'lastSynced' => ...,
  'sources' => list of sources actually present after filter (no youtube),
]
```

### Counting rules

1. Include only `ContentItem` with non-null `published_date` in range (unchanged).  
2. Exclude `source === ContentItem::SOURCE_YOUTUBE`.  
3. Per planner count = rows with that `source` attributed to the editor (non-blank, non-shared).  
4. Studio / month `byPlanner` includes unattributed and shared in **studio** totals only if we show a studio strip; per-editor never gets shared. Simplest: studio strip sums all included sources by planner; footnote still explains unattributed/shared.  
5. Editing minutes: `TimesheetEntry::TASK_EDITING` + `counted()` only (unchanged).

## UI / frontend design

Preserve existing admin shell (`x-app-layout`, `x-card`, brand tokens). Light plane; current month row: stronger left border or `bg-brand-50` + “This month” chip. Per-editor card grid: three equal planner stats + editing time as fourth. No purple/cream AI clichés; no new card chrome beyond existing patterns. Mobile: stacked stats OK (~420px).

Copy: “Published” → planner labels (“Reels”, “Posts”, “Stories”). Subtitle: compare timesheet editing hours to planner counts. Period picker unchanged.

## Error handling / edge cases

- Editor with hours and zero planner counts: still listed (Notion miss / unsynced).  
- Editor with counts and no hours: still listed.  
- Only YouTube published in range: those people/months show zero planner counts from YT; hours may still appear.  
- Empty period: existing empty state.

## Testing

Extend `tests/Feature/EditorOutputTest.php`:

- YouTube item does not increment `byPlanner['reel']` or totals; Reel does.  
- Same editor: 2 reel + 1 post + 1 story → matching `byPlanner`.  
- Co-edited still excluded from per-person `byPlanner`.  
- Months collection: when range includes today, first month has `isCurrent === true` (if we reverse to newest-first).  
- Page renders with planner labels; does not show a single “per item” headline.  
- Existing tier typo + name-join tests updated to assert `byPlanner` instead of `items` where needed.

## Out of scope

- Changing Notion sync or YouTube database sync.  
- Attributing timesheet minutes to Reel vs Post vs Story.  
- Content dashboard targets.  
- Non-admin access to `/editors`.

## Success criteria

1. `/editors?months=3` leads with the current month, visually distinct.  
2. Output is Reel / Post / Story — never YouTube, never one blended item count as the main number.  
3. Editing hours sit beside those counts for compare-at-a-glance.  
4. Tests above pass on PHP 8.2 with config clear/cache discipline used on this host.
