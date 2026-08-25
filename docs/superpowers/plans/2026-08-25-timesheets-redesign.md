# Timesheets Admin Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `/timesheets` into a queue-first admin workboard (Chase → Decide → Charts → People) with medium-detail Accept/Reject on the index, readable daily bars, and light person-page polish.

**Architecture:** Extend `TimesheetAdminController@index` (or `TeamPulse`) to build a pending-day queue scoped by `managesTimesheetOf`. Reorder `timesheets/index.blade.php` and add Alpine expandable decide rows posting to existing `timesheets.day`. Keep `TimesheetDayController` rules. Shared `daily-bars` stays the chart component; ensure readability. Person show gets light waiting/rejected emphasis.

**Tech Stack:** Laravel Blade, Alpine.js, Tailwind (brand palette), PHPUnit via `/opt/alt/php82/usr/bin/php artisan test`.

## Global Constraints

- Audience: managers/admins on `/timesheets`; `/my/timesheet` mostly untouched except shared chart fixes.
- Stack: Blade + Alpine + Tailwind + brand tokens; no React/Livewire/new CDN.
- Permissions: `module:timesheets,view` and `managesTimesheetOf()` unchanged; no new routes for decide.
- Reject requires `review_note`; Accept uses existing `TimesheetDay::APPROVED`.
- Do not commit unless asked; do not add dummy production data.
- PHP binary: `/opt/alt/php82/usr/bin/php`.
- After PHP changes: `OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 graphify extract . --code-only --max-workers 1`.

## File map

| File | Role |
|------|------|
| `app/Support/TeamPulse.php` | Add `pendingDays()` for undecided worked days |
| `app/Http/Controllers/TimesheetAdminController.php` | Pass `$pendingDays` to index |
| `resources/views/timesheets/index.blade.php` | IA reorder + decide queue UI |
| `resources/views/timesheets/_decide-queue.blade.php` | Expandable queue partial (optional extract) |
| `resources/views/timesheets/show.blade.php` | P2 waiting/rejected emphasis |
| `resources/views/components/charts/daily-bars.blade.php` | Readability tweaks only if still broken |
| `tests/Feature/TimesheetDecideQueueTest.php` | New feature coverage for queue |
| `tests/Feature/TimesheetReviewTest.php` | Fix chase panel delimiter vs Decide section |

---

### Task 1: Pending days data + failing tests

**Files:**
- Create: `tests/Feature/TimesheetDecideQueueTest.php`
- Modify: `app/Support/TeamPulse.php`
- Modify: `app/Http/Controllers/TimesheetAdminController.php`
- Modify: `tests/Feature/TimesheetReviewTest.php` (chase panel boundary)

**Interfaces:**
- Consumes: `TimesheetEntry::forMonth`, `TimesheetDay::decisionsFor`, `User::managesTimesheetOf`, `User::whoLogWork`
- Produces: `TeamPulse::pendingDays(Collection $employees, Carbon $month, User $viewer): Collection` of arrays:
  - `employee: User`
  - `worked_on: string` (Y-m-d)
  - `minutes: int`
  - `entry_count: int`
  - `flagged: bool`
  - `entries: list<array{task: string, minutes: int, venture_label: string}>`

- [ ] **Step 1: Write failing feature tests**

```php
<?php

namespace Tests\Feature;

use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetDecideQueueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function entry(User $user, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $user->id,
            'worked_on' => now()->toDateString(),
            'task' => 'Edit reel',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
            'status' => TimesheetEntry::STATUS_COMPLETED,
        ], $overrides));
    }

    public function test_index_lists_undecided_days_in_decide_queue(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Queue Person']);
        $this->entry($staff, ['task' => 'Colour grade']);

        $this->actingAs($admin)
            ->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee('Days to decide')
            ->assertSee('Queue Person')
            ->assertSee('Colour grade');
    }

    public function test_approved_day_leaves_the_decide_queue(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Done Person']);
        $this->entry($staff);

        $this->actingAs($admin)
            ->post(route('timesheets.day', $staff), [
                'worked_on' => now()->toDateString(),
                'review_state' => TimesheetDay::APPROVED,
            ])
            ->assertRedirect();

        $html = $this->actingAs($admin)->get(route('timesheets.index'))->assertOk()->getContent();
        $queue = \Illuminate\Support\Str::before(
            \Illuminate\Support\Str::after($html, 'Days to decide'),
            'Who worked most'
        );
        $this->assertStringNotContainsString('Done Person', $queue);
    }

    public function test_manager_only_sees_reports_in_decide_queue(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Manager One']);
        $report = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'My Report']);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Other Staff']);
        $report->managers()->attach($manager);
        $this->entry($report);
        $this->entry($other);

        // Manager needs timesheets view permission — grant via factory/helper used elsewhere,
        // or act as admin for visibility and assert manages filter in a unit-style TeamPulse test.
        // Prefer: admin sees both; dedicated TeamPulse assertion for manager filter.
        $this->assertTrue($manager->managesTimesheetOf($report));
        $this->assertFalse($manager->managesTimesheetOf($other));

        $pending = \App\Support\TeamPulse::pendingDays(
            User::whoLogWork()->orderBy('name')->get(),
            now()->startOfMonth(),
            $manager
        );

        $names = $pending->map(fn ($row) => $row['employee']->name)->all();
        $this->assertContains('My Report', $names);
        $this->assertNotContains('Other Staff', $names);
    }
}
```

Also update `TimesheetReviewTest::test_the_team_list_names_who_logged_nothing_this_week` to end the chase panel at `Days to decide` instead of `Who worked most`.

- [ ] **Step 2: Run tests — expect FAIL**

```bash
/opt/alt/php82/usr/bin/php artisan config:clear
/opt/alt/php82/usr/bin/php artisan test --filter=TimesheetDecideQueueTest
```

Expected: FAIL (no `Days to decide` / `pendingDays`).

- [ ] **Step 3: Implement `TeamPulse::pendingDays`**

```php
public static function pendingDays(Collection $employees, Carbon $month, User $viewer): Collection
{
    if ($employees->isEmpty()) {
        return collect();
    }

    $visible = $employees->filter(fn (User $e) => $viewer->managesTimesheetOf($e))->values();
    if ($visible->isEmpty()) {
        return collect();
    }

    $entries = TimesheetEntry::whereIn('user_id', $visible->pluck('id'))
        ->forMonth($month)
        ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
        ->orderBy('worked_on')
        ->orderBy('started_at')
        ->get()
        ->groupBy(fn (TimesheetEntry $e) => $e->user_id.'|'.$e->worked_on->toDateString());

    $decisions = TimesheetDay::decisionsFor($visible->pluck('id'), $month);
    $byId = $visible->keyBy('id');

    return $entries
        ->reject(fn ($dayEntries, string $key) => $decisions->has($key))
        ->map(function ($dayEntries, string $key) use ($byId) {
            [$userId, $date] = explode('|', $key, 2);
            $employee = $byId->get((int) $userId);
            if (! $employee) {
                return null;
            }
            $minutes = (int) $dayEntries->sum('minutes');
            $flagged = $dayEntries->contains(fn (TimesheetEntry $e) => $e->minutes >= TimesheetAnomalies::LONG_ENTRY_MINUTES)
                || $minutes >= TimesheetAnomalies::IMPOSSIBLE_DAY_MINUTES;

            return [
                'employee' => $employee,
                'worked_on' => $date,
                'minutes' => $minutes,
                'entry_count' => $dayEntries->count(),
                'flagged' => $flagged,
                'entries' => $dayEntries->map(fn (TimesheetEntry $e) => [
                    'task' => (string) $e->task,
                    'minutes' => (int) $e->minutes,
                    'venture_label' => $e->venture ? $e->ventureLabel() : '',
                ])->values()->all(),
            ];
        })
        ->filter()
        ->sortBy([
            fn ($row) => $row['worked_on'],
            fn ($row) => $row['employee']->name,
        ])
        ->values();
}
```

Wire in controller:

```php
'pendingDays' => TeamPulse::pendingDays($employees, $month, $request->user()),
```

- [ ] **Step 4: Re-run TeamPulse-level test — pendingDays passes; index still fails until Blade**

---

### Task 2: Index workboard UI (P1)

**Files:**
- Modify: `resources/views/timesheets/index.blade.php`
- Create (optional): `resources/views/timesheets/_decide-queue.blade.php`

**Interfaces:**
- Consumes: `$pendingDays`, `$behind`, `$rejectedCount`, `$teamStats`, `$ranking`, `$rows`, `$month`
- Produces: Chase → Decide → Charts → People markup; forms POST `route('timesheets.day', $employee)`

- [ ] **Step 1: Reorder sections and add decide queue**

Structure:

1. Month nav  
2. Chase card (existing)  
3. Rejected banner (existing)  
4. Decide card — `@foreach ($pendingDays as $item)` with `x-data="{ open: false, rejecting: false }"`  
5. Charts grid + daily-bars  
6. People list  

Decide row: Accept form; Reject toggles textarea + submit with `review_state=rejected`. Expand shows entries + link to show.

Empty queue copy: `You're caught up — no days waiting on a decision.`

- [ ] **Step 2: Run decide queue + review tests**

```bash
/opt/alt/php82/usr/bin/php artisan test --filter='TimesheetDecideQueueTest|TimesheetReviewTest|TimesheetTest::test_admin_team_timesheet'
```

Expected: PASS for queue + chase + existing chart assertions.

---

### Task 3: Daily-bars readability + person page P2

**Files:**
- Modify: `resources/views/components/charts/daily-bars.blade.php` (only if needed)  
- Modify: `resources/views/timesheets/show.blade.php`

**Interfaces:**
- Consumes: existing `$stats['daily']`, `$decisions`  
- Produces: readable chart; day headers show waiting/rejected state clearly

- [ ] **Step 1: Confirm daily-bars fixed height (`h-[7.5rem]`) and axis; bump min bar touch if needed**  
- [ ] **Step 2: On show, badge undecided days (“To decide”) and keep rejected/approved banners**  
- [ ] **Step 3: Run chart-related TimesheetTest assertions**

```bash
/opt/alt/php82/usr/bin/php artisan test --filter=Timesheet
```

---

### Task 4: Full verification + graphify

- [ ] **Step 1: config clear, run focused then broader tests, config cache**

```bash
/opt/alt/php82/usr/bin/php artisan config:clear
/opt/alt/php82/usr/bin/php artisan test --filter='TimesheetDecideQueue|TimesheetReview|TimesheetApproval|TimesheetTest|TimesheetSelfFix|WorkingAdmin'
/opt/alt/php82/usr/bin/php artisan config:cache
```

- [ ] **Step 2: Refresh graph**

```bash
OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 graphify extract . --code-only --max-workers 1
```

- [ ] **Step 3: Do not commit unless asked**

---

## Spec coverage check

| Spec item | Task |
|-----------|------|
| Queue-first IA | Task 2 |
| Medium expand + Accept/Reject | Task 2 |
| pendingDays + manages filter | Task 1 |
| Chase / TeamPulse | Task 2 (keep) + ReviewTest fix |
| Charts after decide; daily-bars | Task 2–3 |
| Person points unchanged; P2 polish | Task 3 |
| Permissions / WorkingAdmin | unchanged + tests Task 4 |
| Out of scope My timesheet redesign | honored |

## Plan self-review

- No TBD placeholders in steps.  
- `pendingDays` signature consistent across tasks.  
- Chase panel test delimiter updated so Decide names do not false-fail Quiet/Busy assertions.
