# Editor Output Planner Throughput — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reshape `/editors` so output is Reel / Post / Story counts beside timesheet editing hours, YouTube excluded, current month first and highlighted, no blended per-item rate.

**Architecture:** Evolve `EditorThroughput::between()` to filter sources and return `byPlanner` + minutes; update Blade; update `EditorOutputTest`. Controller period math unchanged.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, PHPUnit, existing `ContentItem` / `TimesheetEntry`.

**Spec:** `docs/superpowers/specs/2026-08-25-editors-planner-throughput-design.md`

## Global Constraints

- Exclude `ContentItem::SOURCE_YOUTUBE` from all throughput counts on this screen.
- Planners on screen: `reel`, `post`, `story` only.
- No inventing hours-per-planner; one editing-minutes total beside counts.
- Remove headline use of `items` / `minutesPerItem` / `itemsPerDay` / `hardShare`.
- Months collection: newest-first; `isCurrent` true for `today()->format('Y-m')`.
- PHPUnit via `/opt/alt/php82/usr/bin/php`; `config:clear` before tests, `config:cache` after.
- After PHP changes: `OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 graphify extract . --code-only --max-workers 1`

## File map

| File | Role |
|------|------|
| `app/Support/EditorThroughput.php` | Filter, `byPlanner`, month order, drop blended fields |
| `resources/views/editors/index.blade.php` | Planner columns, current-month highlight, no per-item headline |
| `tests/Feature/EditorOutputTest.php` | Contract tests |

---

### Task 1: EditorThroughput contract + tests

**Files:**
- Modify: `app/Support/EditorThroughput.php`
- Modify: `tests/Feature/EditorOutputTest.php`

**Interfaces:**
- Produces: `between()` with `byPlanner`, `byPlannerTotals`, `currentMonthKey`, months newest-first with `isCurrent`; rows without `items`/`minutesPerItem`/`itemsPerDay`/`hardShare`

- [ ] **Step 1: Write failing tests** for YouTube exclusion, planner split, months newest-first + `isCurrent`, update assertions that used `items`/`minutesPerItem`/`hardShare`
- [ ] **Step 2: Run** `/opt/alt/php82/usr/bin/php artisan config:clear && /opt/alt/php82/usr/bin/php artisan test --filter=EditorOutputTest` — expect FAIL on new assertions
- [ ] **Step 3: Implement** `EditorThroughput` per spec
- [ ] **Step 4: Re-run tests** — expect PASS for throughput tests
- [ ] **Step 5: Commit** `feat: split editor throughput by planner, drop blended item rate`

### Task 2: Editors Blade UI

**Files:**
- Modify: `resources/views/editors/index.blade.php`
- Test: assertSee Reels/Posts/Stories; assertDontSee 'per item' as headline (case-sensitive as used)

- [ ] **Step 1: Failing page assertion** for planner labels / no “per item” headline
- [ ] **Step 2: Update Blade** per-editor + month table
- [ ] **Step 3: Tests pass**
- [ ] **Step 4: Commit** `feat: show reel/post/story counts on editors screen`
- [ ] **Step 5: Refresh graphify**

---

## Execution

User directed auto-continue without choosing execution mode → implement inline in this session.
