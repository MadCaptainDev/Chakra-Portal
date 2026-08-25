# Routines — Human Redesign

## Goal

Make routines behave the way a person expects: a duty is a duty, whether or not
it is about a client account, and checking whether the studio did its duties
answers "who hasn't done what" rather than "what fell on the 14th".

## The problem

Routines shipped account-first. The only thing a routine can be *about* is a
Client Instagram or Venture account, and the admin form leads with two large
account pickers. But three of the four real studio duties are about nothing at
all:

| Duty | Schedule | Subject |
| --- | --- | --- |
| Venture Direct Messages and Comments | daily | per account |
| Move final output to hard disk | daily | none |
| Verify training | every 10 days | none |
| Clean the office | every 2 days | none |

The engine already supports subject-less routines. The damage is in three places.

### 1. A routine can be silently dead

`routines.subject_type` is *inferred* at save time from whether any account
checkbox happened to be ticked (`RoutineController::validated()`). A routine
marked `accounts` with no accounts attached generates nothing —
`RoutineOccurrenceGenerator::subjectTuples()` returns `[]` — and nothing in the
UI says so.

`RoutineDutyPlansSeeder` ships exactly this state: the DM/Comments routine is
seeded with `subject_type = accounts` and zero subjects. After seeding, that
duty is invisible until somebody opens the edit form and ticks an account. The
same silence swallows a routine whose accounts are later deleted or revoked.

### 2. Overdue duties flood the list

The generator creates one row per due date whether or not the previous one is
still open. Miss four days of "Clean the office" and the employee opens My
Routines to four identical cards, each with its own form and its own submit.
The record is correct; the presentation is not.

### 3. Checking is a calendar

`routines/calendar.blade.php` is a seven-column month grid: three chips per
day cell, an unexpandable "+N more", 10px type, a 640px minimum width on a
mobile-first app, and a skip-reason typed into a calendar square. It cannot
answer the only question a manager actually asks.

This project already reached that conclusion once. The
[timesheets redesign](2026-08-25-timesheets-redesign-design.md) replaced a
calendar with a queue-first workboard and a per-person page, for these reasons.
Routines then shipped a calendar as its primary checking surface.

## Approach

Four changes. Deliberately *not* changing how occurrences are generated — one
row per due date is correct, because the DM routine captures per-day reply
counts and collapsing rows would destroy that record. The pile-up is fixed in
presentation, where it belongs.

### A. Subject scope becomes an explicit choice

The form leads with one question instead of two pickers:

> **What is this routine about?**
> - Just a duty — nothing to pick *(default)*
> - Each client Instagram account
> - Each venture account

`subject_type` is stored from that answer rather than inferred from checkbox
state. Choosing an account scope and attaching no accounts is a **validation
error**, not a silent no-op. The account pickers are hidden until an account
scope is chosen.

This makes "Clean the office" the default shape of a routine, which is what it
should have been.

### B. A dead routine says so

`Routine::generationWarning()` returns a human sentence when a routine is
active but cannot generate:

- account-scoped with no accounts attached
- account-scoped where every attached account is deleted or revoked

Surfaced as an amber row on the routines index and on the checking board, so a
routine that has quietly stopped working is visible without reading the
database.

### C. My Routines becomes one list

One card per *duty* — `(routine, checkpoint, subject)` — not one per occurrence.
A duty that is four days behind reads "4 outstanding · oldest Mon 24 Aug"
instead of appearing four times.

The card carries a checkbox, not a form. Capture fields appear inline only for
duties that have them, and only when ticked. One **Save** at the bottom posts
every tick at once, so a person with twelve duties reloads the page once rather
than twelve times.

Ticking a duty that is behind completes its **oldest** open occurrence, so
catch-up is chronological and the audit stays honest. A "catch up all N" action
on the card closes the whole backlog in one go, recording the same actor and
timestamp on each.

"Coming up" is computed from `RoutineScheduler` for display only — no future
rows are materialised, so future dates cannot pollute the overdue query. That
section is currently always empty because generation stops at today.

### D. Checking becomes a per-person board

`routines.checking` becomes the primary admin surface and the module's default
route. It shows, for a chosen day (today by default):

- a row per person who has duties, with done / outstanding counts
- their duties beneath, each tickable or skippable in place
- an unassigned "shared — anyone" group at the top
- overdue duties from previous days folded in, marked with their age

The calendar stays, demoted to a secondary tab for looking back over a month.
It is not deleted — it answers a real if less frequent question.

## Architecture

| Concern | Where |
| --- | --- |
| Explicit subject scope | `RoutineController::validated()`, `routines/_form.blade.php` |
| Generation warning | `Routine::generationWarning()`, `routines/index.blade.php` |
| Duty grouping | `App\Support\RoutineDutyList` (new) |
| Bulk complete | `My\RoutineController::completeMany()`, `RoutineCompleter::completeOldest()` |
| Lookahead | `RoutineScheduler::nextDatesAfter()` |
| Checking board | `RoutineCheckingController` (new), `routines/checking.blade.php` |

`RoutineDutyList` is a small support class that takes a collection of open
occurrences and groups them into duty rows. It is pure — no queries — so it can
be unit-tested against a hand-built collection and reused by both the employee
list and the checking board. Keeping it out of the controllers is what stops
this logic being written twice and drifting.

### Duty row shape

```
[
  'key'         => 'routine:12|cp:3|subject:social_account:8',
  'routine'     => Routine,
  'checkpoint'  => ?RoutineCheckpoint,
  'subject'     => ?Model,
  'occurrences' => Collection<RoutineOccurrence>,  // open, oldest first
  'oldest'      => RoutineOccurrence,
  'outstanding' => int,
  'is_overdue'  => bool,
]
```

## Performance

`My\RoutineController::index()` and `RoutineCalendarController::index()` both
call `$generator->run()` on every request — a `firstOrCreate` per routine × date
× checkpoint × subject × user, on a page load. The `EnsureRoutinesGenerated`
middleware already does this at most once per day via an atomic `Cache::add`.

Both unconditional calls are removed and the middleware is applied to the `my/`
routes instead, so generation happens once a day regardless of which surface is
opened first.

## Error and empty states

- Account scope with no accounts → validation error on the form.
- Active routine that cannot generate → amber warning row, index and board.
- No duties today → "Nothing due today." rather than three empty headings.
- Ticking an occurrence someone else already closed → existing first-doer-wins
  message, unchanged.
- Bulk save where some duties were closed concurrently → saves the rest, reports
  how many were already done.

## Testing

Existing routine tests must keep passing unchanged — generation semantics are
not being altered.

New:

- `RoutineDutyListTest` (unit) — grouping, outstanding counts, oldest-first
  ordering, subject and checkpoint separation.
- account scope with zero accounts is rejected by the form.
- `generationWarning()` fires for the seeded DM routine and clears once an
  account is attached.
- bulk complete closes several duties in one request.
- ticking a duty four days behind closes the oldest occurrence, not the newest.
- checking board groups by person and includes shared duties.
- neither `my/routines` nor the calendar runs generation on request.

## Out of scope

- Changing generation semantics (one row per due date stays).
- New subject kinds (equipment, rooms). The morph map supports them; nothing
  currently needs them.
- Notifications or reminders for overdue duties.
- Employee-side skip. Skipping stays an admin action with a reason.

## Success criteria

- A routine can be created without ever seeing an account picker.
- Choosing an account scope with no accounts is impossible to save.
- The seeded DM routine visibly reports that it is not generating.
- Four days behind on cleaning shows one card, not four.
- A manager can answer "who hasn't done their duties today" on one screen, on a
  phone.
