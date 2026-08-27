<?php

namespace App\Support;

use App\Models\RoutineOccurrence;
use Illuminate\Support\Collection;

/**
 * Groups open occurrences into duties.
 *
 * The generator writes one row per due date, which is right -- the DM routine
 * captures a reply count per day, and collapsing rows would throw that away.
 * But a person four days behind on cleaning the office does not have four
 * duties, they have one duty they are four days late on. That distinction is
 * presentation, so it lives here rather than in the generator.
 *
 * Pure: hand it a collection, get rows back. No queries, so both the employee
 * list and the admin checking board can use it without either one growing its
 * own copy of the grouping rules.
 */
class RoutineDutyList
{
    /**
     * One row per (routine, checkpoint, subject, assignee), oldest occurrence
     * first within each.
     *
     * assigned_user_id is part of the key so that an individual-mode duty does
     * not merge two people's work into one row on the checking board. On the
     * employee's own list it is a no-op -- everything there is already theirs.
     *
     * @param  Collection<int, RoutineOccurrence>  $occurrences
     * @return Collection<int, array<string, mixed>>
     */
    public static function group(Collection $occurrences): Collection
    {
        $today = today();

        return $occurrences
            ->groupBy(fn (RoutineOccurrence $o) => self::keyFor($o))
            ->map(function (Collection $rows, string $key) use ($today) {
                $sorted = $rows->sortBy([
                    fn (RoutineOccurrence $a, RoutineOccurrence $b) => $a->due_on <=> $b->due_on,
                    fn (RoutineOccurrence $a, RoutineOccurrence $b) => $a->id <=> $b->id,
                ])->values();

                /** @var RoutineOccurrence $oldest */
                $oldest = $sorted->first();

                return [
                    'key' => $key,
                    'routine' => $oldest->routine,
                    'checkpoint' => $oldest->checkpoint,
                    'subject' => $oldest->subject,
                    'subject_label' => $oldest->subjectLabel(),
                    'assigned_user' => $oldest->assignedUser,
                    'occurrences' => $sorted,
                    'oldest' => $oldest,
                    'outstanding' => $sorted->count(),
                    'is_overdue' => $oldest->due_on->lt($today),
                    // Carbon 3 returns a float here; a duty is late by whole days.
                    'days_late' => $oldest->due_on->lt($today)
                        ? (int) $oldest->due_on->diffInDays($today)
                        : 0,
                ];
            })
            // Late first, then longest-late, then by title so the order is
            // stable between requests rather than hash order.
            ->sortBy([
                fn (array $a, array $b) => ($b['is_overdue'] <=> $a['is_overdue']),
                fn (array $a, array $b) => ($b['days_late'] <=> $a['days_late']),
                fn (array $a, array $b) => strcmp(
                    (string) ($a['routine']?->title ?? ''),
                    (string) ($b['routine']?->title ?? ''),
                ),
            ])
            ->values();
    }

    /**
     * Nest group()'s per-subject duties under one task per (routine,
     * checkpoint, assignee) -- what a person actually reads as "one thing to
     * do", the way a Teams/Planner task holds its checklist items under one
     * card instead of one card per item. Fifteen venture accounts should not
     * read as fifteen identical "Checking Venture Messages" cards; they are
     * one task, "which accounts", with the accounts as its subtasks.
     *
     * group() is right to keep subjects apart -- it is the unit completion
     * acts on, and a duty four days behind on one account is not the same
     * row as the same duty on another. This nests on top of that grouping
     * rather than replacing it, the same way group() sits on top of the raw
     * occurrence rows without changing what they are.
     *
     * A task with a single subtask -- a plain, non-account-scoped routine,
     * still the overwhelming majority -- comes back in the same shape with a
     * one-item 'subtasks' list, so callers can render every task the same
     * way and only branch on count() when they want the compact single-item
     * form instead of a checklist.
     *
     * @param  Collection<int, array<string, mixed>>  $duties  Rows from group().
     * @return Collection<int, array<string, mixed>>
     */
    public static function nest(Collection $duties): Collection
    {
        return $duties
            ->groupBy(fn (array $d) => implode('|', [
                'routine:'.$d['routine']?->id,
                'cp:'.($d['checkpoint']?->id ?? 0),
                'user:'.($d['assigned_user']?->id ?? 0),
            ]))
            ->map(function (Collection $rows, string $key) {
                $sorted = $rows->sortBy([
                    fn (array $a, array $b) => ($b['is_overdue'] <=> $a['is_overdue']),
                    fn (array $a, array $b) => ($b['days_late'] <=> $a['days_late']),
                    fn (array $a, array $b) => strcmp((string) $a['subject_label'], (string) $b['subject_label']),
                ])->values();

                $first = $sorted->first();

                return [
                    'key' => $key,
                    'routine' => $first['routine'],
                    'checkpoint' => $first['checkpoint'],
                    'assigned_user' => $first['assigned_user'],
                    'subtasks' => $sorted,
                    'total' => $sorted->count(),
                    'late_count' => $sorted->where('is_overdue', true)->count(),
                    'is_overdue' => $sorted->contains('is_overdue', true),
                    'days_late' => (int) $sorted->max('days_late'),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => ($b['is_overdue'] <=> $a['is_overdue']),
                fn (array $a, array $b) => ($b['days_late'] <=> $a['days_late']),
                fn (array $a, array $b) => strcmp(
                    (string) ($a['routine']?->title ?? ''),
                    (string) ($b['routine']?->title ?? ''),
                ),
            ])
            ->values();
    }

    /**
     * Stable grouping key. Mirrors the fingerprint dimensions minus the date --
     * the date is exactly what we are collapsing.
     */
    public static function keyFor(RoutineOccurrence $occurrence): string
    {
        return implode('|', [
            'routine:'.$occurrence->routine_id,
            'cp:'.($occurrence->checkpoint_id ?? 0),
            'subject:'.($occurrence->subject_type ?? '').':'.($occurrence->subject_id ?? 0),
            'user:'.($occurrence->assigned_user_id ?? 0),
        ]);
    }
}
