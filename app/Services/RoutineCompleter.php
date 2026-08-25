<?php

namespace App\Services;

use App\Models\RoutineField;
use App\Models\RoutineOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Complete or skip an occurrence. Shared mode uses a row lock + status
 * check so only the first doer wins; the loser learns who completed it.
 */
class RoutineCompleter
{
    /**
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, occurrence: RoutineOccurrence, winner: ?User}
     */
    public function complete(RoutineOccurrence $occurrence, User $actor, array $values = [], ?string $note = null): array
    {
        $occurrence->loadMissing(['routine.fields', 'routine.users', 'completedByUser']);

        $this->assertMayAct($occurrence, $actor);

        $values = $this->normaliseValues($occurrence, $values);

        return DB::transaction(function () use ($occurrence, $actor, $values, $note) {
            /** @var RoutineOccurrence $locked */
            $locked = RoutineOccurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                $locked->load('completedByUser');

                return [
                    'ok' => false,
                    'occurrence' => $locked,
                    'winner' => $locked->completedByUser,
                ];
            }

            $locked->forceFill([
                'status' => RoutineOccurrence::STATUS_DONE,
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'values' => $values,
                'note' => $note,
            ])->save();

            $locked->load('completedByUser');

            return [
                'ok' => true,
                'occurrence' => $locked,
                'winner' => $actor,
            ];
        });
    }

    /**
     * Close several occurrences for one actor, carrying on past any that
     * somebody else already took.
     *
     * Used for two things: ticking a duty that is days behind (its whole
     * backlog closes at once) and saving a page of ticks in one request. A
     * concurrent completion is not an error here -- the work is done either
     * way, so the count is reported rather than thrown.
     *
     * @param  iterable<RoutineOccurrence>  $occurrences
     * @param  array<string, mixed>  $values
     * @return array{done: int, already: int}
     */
    public function completeMany(iterable $occurrences, User $actor, array $values = [], ?string $note = null): array
    {
        $done = 0;
        $already = 0;

        foreach ($occurrences as $occurrence) {
            $result = $this->complete($occurrence, $actor, $values, $note);

            $result['ok'] ? $done++ : $already++;
        }

        return ['done' => $done, 'already' => $already];
    }

    public function skip(RoutineOccurrence $occurrence, User $actor, string $reason): RoutineOccurrence
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'note' => 'A reason is required to skip.',
            ]);
        }

        return DB::transaction(function () use ($occurrence, $actor, $reason) {
            /** @var RoutineOccurrence $locked */
            $locked = RoutineOccurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => RoutineOccurrence::STATUS_SKIPPED,
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'note' => $reason,
            ])->save();

            return $locked;
        });
    }

    private function assertMayAct(RoutineOccurrence $occurrence, User $actor): void
    {
        $routine = $occurrence->routine;

        if (! $routine) {
            abort(404);
        }

        if ($actor->isAdmin()) {
            return;
        }

        $permitted = $routine->users()->where('users.id', $actor->id)->exists();

        abort_unless($permitted, 404);

        if ($routine->isIndividual()) {
            abort_unless((int) $occurrence->assigned_user_id === (int) $actor->id, 404);
        }
    }

    /**
     * @param  array<string, mixed>  $posted
     * @return array<string, mixed>
     */
    private function normaliseValues(RoutineOccurrence $occurrence, array $posted): array
    {
        $out = [];

        foreach ($occurrence->applicableFields() as $field) {
            /** @var RoutineField $field */
            $raw = $posted[$field->key] ?? null;

            if ($raw === null || $raw === '') {
                $out[$field->key] = $field->resolvedDefault();

                continue;
            }

            $out[$field->key] = match ($field->type) {
                RoutineField::TYPE_NUMBER => (float) $raw,
                RoutineField::TYPE_BOOLEAN => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                default => (string) $raw,
            };
        }

        return $out;
    }
}
