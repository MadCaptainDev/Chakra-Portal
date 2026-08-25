# Timesheets Admin Redesign — Queue-First Workboard

**Date:** 2026-08-25  
**Status:** Approved (approach #2; user: “yes, continue auto”)  
**Scope:** Manager/admin `/timesheets` (index + person show). `/my/timesheet` stays mostly as-is except shared chart component fixes.

## Goal

Make `/timesheets` a coherent admin workboard: chase quiet people, decide pending days from the index, read team charts, then drill into people — without a new stack or permission model.

## Locked decisions

| Topic | Choice |
|--------|--------|
| Audience | Managers/admins on `/timesheets` |
| Experience | Overview + decide + chase (+ charts), phased OK |
| Decide location | Index queue for most days; person page for deep review |
| Queue detail | Medium: expandable row with entry title, minutes, client/venture; Accept/Reject on index |
| Reject | Reason required (`review_note`) — existing `TimesheetDayController` rule |
| Stack | Blade, Alpine, Tailwind, brand tokens; no React/Livewire/new CDN |
| Visual | Calm light admin plane; navy `brand-900` + teal/cyan accents; mobile ~420px primary; no purple/cream AI clichés |
| Points | Stay on person show only |
| WorkingAdmin | `User::logsWork()` / `whoLogWork()` unchanged |
| Permissions | Existing `module:timesheets` + `managesTimesheetOf()` unchanged |

## Approach

**Queue-first workboard on existing `/timesheets`** (not a separate inbox route, not progressive-only polish).

Index section order (one job each):

1. Month nav + team total hours  
2. **Chase** — Team Pulse “logged nothing this week”  
3. **Decide** — pending days queue with medium expand + Accept/Reject  
4. **Charts** — Who worked most, By client, Team hours by day, By type  
5. **People** — ranked roster linking to show  

Person page remains deep review (day entries, decide parity, points).

## Architecture

| Piece | Responsibility |
|--------|----------------|
| `TimesheetAdminController@index` | Month rows, TeamPulse behind, teamStats, **pending day queue** filtered to days the viewer may decide |
| `TimesheetDayController@store` | Unchanged decide endpoint; forms on index and show POST here |
| `TeamPulse` | Behind-this-week semantics unchanged |
| `TimesheetStats` / `x-charts.*` | Team and person analytics; daily-bars must stay readable |
| `timesheets/index.blade.php` | Workboard layout + Alpine expand/reject |
| `timesheets/show.blade.php` | Deep review + points; light P2 polish aligned with shell |
| Optional partial | e.g. `timesheets/_decide-queue.blade.php` if index grows unwieldy |

### Pending queue shape

Each item represents one undecided worked day (non-cancelled entries exist; no `TimesheetDay` decision for that user|date):

- `employee` (User)  
- `worked_on` (Y-m-d)  
- `minutes` (int)  
- `entry_count` (int)  
- `flagged` (bool) — true if any entry that day would surface as a timesheet anomaly (optional soft hint; may use `TimesheetAnomalies` or a cheap local check)  
- `entries` — list of `{ task, minutes, venture_label }` for the expand panel  

Sort: oldest `worked_on` first, then employee name.  
Visibility: only include employees where `$viewer->managesTimesheetOf($employee)`. Admins see all who log work.

## Index UX

- **Chase:** Amber card when `$behind` non-empty; mailto nudge + link to show; week semantics stay “current week,” not the selected month.  
- **Decide:** Primary card “Days to decide.” Collapsed row: avatar, name, weekday date, hours, entry count, optional flagged hint, Accept + Reject. Expand reveals entry lines + “Open full timesheet.” Reject toggles inline reason textarea then POST. Empty: calm “You’re caught up.”  
- **Charts:** Same metrics as today; placed after ops sections. Daily bars: fixed plot height (~7.5rem+), readable axis, no squashed month strip on ~420px.  
- **People:** Sorted by hours; show waiting count and points as today.  
- Rejected-days month banner remains near Chase/Decide when count &gt; 0.

## Person page

- Month nav, hours summary, personal charts.  
- Per-day Accept/Reject parity with queue.  
- Points award form unchanged.  
- P2: emphasize waiting/rejected days; prefer shared `x-btn` / card patterns where cheap.

## Phasing

| Phase | Deliverable |
|-------|-------------|
| **P1** | Index IA reorder; pending queue data + expandable Accept/Reject; daily-bars readability acceptance |
| **P2** | Person-page polish (waiting/rejected emphasis, shell consistency) |
| **P3** | Optional denser mobile / queue sort toggles only if needed — no new product surface |

## Out of scope

- Redesigning `/my/timesheet` / Second Look employee copy  
- Import, MCP, new email nudge pipeline  
- New routes or permission keys  
- React / Livewire / Chart.js CDN  
- Dummy production data  

## Testing

- Feature: pending days appear in queue for admin/manager who may decide; unauthorized cannot decide; Accept removes day from queue; Reject without reason fails; Reject with reason succeeds; chase panel still names only quiet people; WorkingAdmin still on people list; month nav preserves charts.  
- Layout/regression: Team hours by day / Who worked most still assertable; My timesheet still loads.  
- PHP for tests: `/opt/alt/php82/usr/bin/php artisan test`.

## Success criteria

Managers can clear most approvals without opening each person page; chase and decide are above charts; Team hours by day is readable on phone and desktop; existing decide and points rules still hold.
