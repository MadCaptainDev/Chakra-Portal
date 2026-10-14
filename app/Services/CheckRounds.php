<?php

namespace App\Services;

use App\Models\CheckRound;
use App\Models\Routine;
use App\Models\RoutineCheckpoint;
use App\Models\RoutineOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How long a round of the Comment & Message Checker takes, without a
 * stopwatch. A round is the morning or evening pass over every account
 * (checks named "Morning …" / "Evening …"; a checker without them has one
 * daily round). It starts with one tap -- or, if nobody tapped, at the first
 * tick -- and ends by itself when that round's last tile for today is ticked.
 *
 * Shown to the person doing it as a record of their round ("done in 18 min ·
 * 23 replies"), and to admins as a week of rounds. Never per account, never
 * a ranking: it is for planning time, not for watching anyone.
 */
class CheckRounds
{
    public const ROUNDS = ['morning' => 'Morning round', 'evening' => 'Evening round', 'daily' => 'Round'];

    public static function roundOf(?string $checkpointName): string
    {
        $name = mb_strtolower((string) $checkpointName);

        return match (true) {
            str_contains($name, 'morning') => 'morning',
            str_contains($name, 'evening') => 'evening',
            default => 'daily',
        };
    }

    /** @return list<string> the rounds this checker has, in order */
    public function roundsOf(Routine $routine): array
    {
        $rounds = $routine->checkpoints->map(fn (RoutineCheckpoint $cp) => self::roundOf($cp->name))->unique()->values()->all();

        return $rounds ?: ['daily'];
    }

    public function start(User $user, Routine $routine, string $round): CheckRound
    {
        return $this->todays($routine->id, $round, $user, false);
    }

    /**
     * After a tick: start the round if nobody did (from this tick), and end
     * it when none of its tiles is still open for today.
     */
    public function ticked(User $user, RoutineOccurrence $row): void
    {
        $routine = $row->routine()->with('checkpoints')->first();
        $round = self::roundOf($row->checkpoint?->name);
        $current = $this->todays($routine->id, $round, $user, true);

        $checkpoints = $this->checkpointIds($routine, $round);
        $scope = fn ($q) => $checkpoints === [] ? $q->whereNull('checkpoint_id') : $q->whereIn('checkpoint_id', $checkpoints);
        $left = RoutineOccurrence::query()->where('routine_id', $routine->id)
            ->where($scope)
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->whereDate('due_on', '<=', today()->toDateString())
            ->exists();

        if (! $left && ! $current->ended_at) {
            $done = RoutineOccurrence::query()->where('routine_id', $routine->id)
                ->where($scope)
                ->where('status', RoutineOccurrence::STATUS_DONE)
                ->whereDate('completed_at', today()->toDateString())
                ->get(['values']);
            $current->update([
                'ended_at' => now(),
                'checks' => $done->count(),
                'replies' => $done->sum(fn (RoutineOccurrence $o) => collect($o->values ?? [])->filter(fn ($v) => is_numeric($v))->sum()),
            ]);
        }
    }

    /** A tick taken back: a round that had ended today is running again. */
    public function unticked(RoutineOccurrence $row): void
    {
        CheckRound::query()
            ->where('routine_id', $row->routine_id)
            ->where('round', self::roundOf($row->checkpoint?->name))
            ->whereDate('on_date', today()->toDateString())
            ->whereNotNull('ended_at')
            ->update(['ended_at' => null]);
    }

    /**
     * Today's rounds for the screen, per checker.
     *
     * @param  Collection<int, Routine>  $routines
     * @return array<int, list<array<string, mixed>>> by routine id
     */
    public function today(Collection $routines): array
    {
        $rows = CheckRound::query()->whereIn('routine_id', $routines->modelKeys())
            ->whereDate('on_date', today()->toDateString())->with('starter:id,name')->get();

        return $routines->mapWithKeys(fn (Routine $routine) => [$routine->id => collect($this->roundsOf($routine))
            ->map(function (string $round) use ($rows, $routine) {
                $row = $rows->first(fn (CheckRound $r) => $r->routine_id === $routine->id && $r->round === $round);

                return [
                    'round' => $round,
                    'label' => self::ROUNDS[$round],
                    'state' => ! $row ? 'idle' : ($row->ended_at ? 'done' : 'running'),
                    'started_ms' => $row?->started_at->getTimestampMs(),
                    'started_label' => $row?->started_at->format('g:i A'),
                    'minutes' => $row?->minutes(),
                    'checks' => $row?->checks,
                    'replies' => $row?->replies,
                    'by' => $row?->starter?->name,
                    'auto' => (bool) $row?->auto_started,
                    // Evening opens at 3 PM, like its tiles.
                    'available' => $round !== 'evening' || (int) now()->format('G') >= InboxDesk::EVENING_FROM,
                ];
            })->values()->all()])->all();
    }

    /**
     * The last seven days of rounds, for admins: how long rounds take, so time
     * can be planned. Days, not people.
     *
     * @param  Collection<int, Routine>  $routines
     * @return array<string, mixed>
     */
    public function week(Collection $routines): array
    {
        $from = today()->subDays(6);
        $rows = CheckRound::query()->whereIn('routine_id', $routines->modelKeys())
            ->whereDate('on_date', '>=', $from->toDateString())->with('starter:id,name')->get();
        $rounds = $routines->flatMap(fn (Routine $r) => $this->roundsOf($r))->unique()->values()->all() ?: ['daily'];

        $days = collect(range(0, 6))->map(fn (int $i) => $from->copy()->addDays($i))->reverse()->map(fn (Carbon $day) => [
            'label' => $day->isToday() ? 'Today' : ($day->isYesterday() ? 'Yesterday' : $day->format('D j M')),
            'rounds' => collect($rounds)->mapWithKeys(function (string $round) use ($rows, $day) {
                $row = $rows->first(fn (CheckRound $r) => $r->round === $round && $r->on_date->isSameDay($day));

                return [$round => $row ? [
                    'minutes' => $row->minutes(),
                    'running' => $row->ended_at === null,
                    'replies' => $row->replies,
                    'by' => $row->starter?->name,
                    'auto' => $row->auto_started,
                ] : null];
            })->all(),
        ])->values()->all();

        $ended = $rows->whereNotNull('ended_at');
        $average = collect($rounds)->mapWithKeys(fn (string $round) => [$round => ($m = $ended->where('round', $round)->map->minutes()->filter(fn ($v) => $v !== null))->isNotEmpty() ? (int) round($m->avg()) : null])->all();

        return [
            'rounds' => array_map(fn (string $r) => ['key' => $r, 'label' => self::ROUNDS[$r]], $rounds),
            'days' => $days,
            'average' => $average,
            'replies' => (int) $ended->sum('replies'),
        ];
    }

    /**
     * Today's round, made if there is none. Matched on the date alone: a DATE
     * column compared to a string misses on SQLite, which keeps a time on it.
     */
    private function todays(int $routineId, string $round, User $user, bool $auto): CheckRound
    {
        $find = fn () => CheckRound::query()->where('routine_id', $routineId)->where('round', $round)
            ->whereDate('on_date', today()->toDateString())->first();

        return $find() ?? rescue(fn () => CheckRound::query()->create([
            'routine_id' => $routineId, 'round' => $round, 'on_date' => today()->toDateString(),
            'started_by' => $user->id, 'started_at' => now(), 'auto_started' => $auto,
        ]), fn () => $find(), false); // two taps at once: the other one made it
    }

    /** @return list<int> */
    private function checkpointIds(Routine $routine, string $round): array
    {
        return $routine->checkpoints->filter(fn (RoutineCheckpoint $cp) => self::roundOf($cp->name) === $round)->modelKeys();
    }
}
