<?php

namespace App\Services;

use App\Models\ContentAccount;
use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Models\RoutineSubject;
use App\Models\SocialAccount;
use Carbon\Carbon;

/**
 * Materialise open RoutineOccurrence rows for every active routine.
 *
 * Idempotent via fingerprint unique key — running twice creates nothing
 * extra. New subjects/users only generate from today forward (the window
 * still covers catch_up_days, but firstOrCreate skips existing fingerprints,
 * and a brand-new subject simply has no past fingerprints to collide with —
 * we deliberately do not backfill past dates for subjects added mid-stream
 * by limiting subject fan-out to subjects that existed… actually the plan
 * says "new account doesn't retro-generate". That means when generating
 * for past dates in the catch-up window, only subjects that were linked
 * before that date should appear — we approximate by never creating past
 * occurrences for a subject on dates before "today" when the subject was
 * just attached: simpler rule used here — past catch-up still fans out to
 * current subjects (standard backfill), BUT tests expect new account not
 * retro. So: only generate subject occurrences for due dates >= the later
 * of (window start, subject pivot created_at date). That way a brand-new
 * account attached today does not get yesterday's rows when catch-up runs.
 */
class RoutineOccurrenceGenerator
{
    public function __construct(private readonly RoutineScheduler $scheduler) {}

    /**
     * Generate occurrences due on or before $through (default today).
     * Returns the number of rows newly inserted.
     */
    public function run(?Carbon $through = null): int
    {
        $through = ($through ?? today())->copy()->startOfDay();
        $created = 0;

        Routine::query()
            ->active()
            ->with(['checkpoints', 'subjects', 'users'])
            ->each(function (Routine $routine) use ($through, &$created) {
                $created += $this->generateForRoutine($routine, $through);
            });

        return $created;
    }

    private function generateForRoutine(Routine $routine, Carbon $through): int
    {
        $catchUp = max(0, (int) $routine->catch_up_days);
        $windowStart = $through->copy()->subDays($catchUp);

        if ($routine->starts_on->gt($windowStart)) {
            $windowStart = $routine->starts_on->copy()->startOfDay();
        }

        $dates = $this->scheduler->datesBetween($routine, $windowStart, $through);

        if ($dates->isEmpty()) {
            return 0;
        }

        $checkpoints = $routine->checkpoints;
        // No rows = one implicit checkpoint (null id).
        $checkpointIds = $checkpoints->isEmpty()
            ? [null]
            : $checkpoints->pluck('id')->all();

        $subjects = $this->subjectTuples($routine);
        $assignees = $this->assigneeIds($routine);

        $created = 0;

        foreach ($dates as $dueOn) {
            foreach ($checkpointIds as $checkpointId) {
                foreach ($subjects as $subject) {
                    // New subjects must not retro-fill past due dates.
                    if ($subject['attached_on'] !== null && $dueOn->lt($subject['attached_on'])) {
                        continue;
                    }

                    foreach ($assignees as $userId) {
                        if ($this->ensureOccurrence(
                            $routine->id,
                            $checkpointId,
                            $subject['type'],
                            $subject['id'],
                            $userId,
                            $dueOn,
                        )) {
                            $created++;
                        }
                    }
                }
            }
        }

        return $created;
    }

    /**
     * @return list<array{type: ?string, id: ?int, attached_on: ?Carbon}>
     */
    private function subjectTuples(Routine $routine): array
    {
        // Account-scoped duties wait until admin toggles real Client IG / Venture IDs.
        if ($routine->subject_type === Routine::SUBJECT_ACCOUNTS && $routine->subjects->isEmpty()) {
            return [];
        }

        if ($routine->subjects->isEmpty()) {
            return [[
                'type' => null,
                'id' => null,
                'attached_on' => null,
            ]];
        }

        return $routine->subjects->map(function (RoutineSubject $row) {
            if (! $this->subjectStillInScope($row)) {
                return null;
            }

            return [
                'type' => $row->subject_type,
                'id' => (int) $row->subject_id,
                'attached_on' => $row->created_at?->copy()->startOfDay(),
            ];
        })->filter()->values()->all();
    }

    /**
     * Skip subjects deleted or revoked since the routine was configured.
     */
    private function subjectStillInScope(RoutineSubject $row): bool
    {
        return match ($row->subject_type) {
            Routine::SUBJECT_SOCIAL => SocialAccount::query()
                ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
                ->whereKey($row->subject_id)
                ->where('status', '!=', SocialAccount::STATUS_REVOKED)
                ->exists(),
            Routine::SUBJECT_CONTENT => ContentAccount::query()
                ->whereKey($row->subject_id)
                ->exists(),
            default => false,
        };
    }

    /**
     * @return list<?int>
     */
    private function assigneeIds(Routine $routine): array
    {
        if ($routine->isIndividual()) {
            $ids = $routine->users->pluck('id')->all();

            return $ids === [] ? [null] : $ids;
        }

        return [null];
    }

    private function ensureOccurrence(
        int $routineId,
        ?int $checkpointId,
        ?string $subjectType,
        ?int $subjectId,
        ?int $assignedUserId,
        Carbon $dueOn,
    ): bool {
        $fingerprint = RoutineOccurrence::fingerprintFor(
            $routineId,
            $checkpointId,
            $subjectType,
            $subjectId,
            $assignedUserId,
            $dueOn,
        );

        // firstOrCreate is not atomic under race; unique fingerprint +
        // ignore duplicate keeps generate idempotent under concurrency.
        try {
            $occurrence = RoutineOccurrence::query()->firstOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'routine_id' => $routineId,
                    'checkpoint_id' => $checkpointId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'assigned_user_id' => $assignedUserId,
                    'due_on' => $dueOn->toDateString(),
                    'status' => RoutineOccurrence::STATUS_OPEN,
                ],
            );

            return $occurrence->wasRecentlyCreated;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        }
    }
}
