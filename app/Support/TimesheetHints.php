<?php

namespace App\Support;

use App\Models\Shoot;
use App\Models\TimesheetEntry;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What someone did on a day, read from what they already pressed -- My
 * Editing (when each video was being worked on), the shoots they crewed and
 * the videos they posted -- worded as timesheet rows they can add in one tap
 * (My Timesheet) or one call (the timesheet_hint MCP tool).
 *
 * A hint, never an entry: nothing is written here, and a row that looks
 * already logged that day says so instead of being offered twice.
 */
class TimesheetHints
{
    /** @return list<array{task: string, type: string, client: ?string, date: string, start: ?string, end: ?string, minutes: ?int, why: string, logged: bool}> */
    public static function for(User $user, Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->isToday() ? now() : $day->copy()->endOfDay();
        $logged = TimesheetEntry::where('user_id', $user->id)->whereDate('worked_on', $day)->get();

        $hints = array_merge(self::editing($user, $from, $to), self::shoots($user, $from, $to), self::posting($user, $from, $to));

        foreach ($hints as &$h) {
            $h['date'] = $day->toDateString();
            $h['logged'] = $logged->contains(fn (TimesheetEntry $e) => self::sameWork($e, $h));
        }
        unset($h);

        usort($hints, fn ($a, $b) => strcmp($a['start'] ?? '99', $b['start'] ?? '99'));

        return $hints;
    }

    /** Working time on each video, from Start / Resume to Done / Hold / Next day, corrections counted on their video. */
    private static function editing(User $user, Carbon $from, Carbon $to): array
    {
        $todos = Todo::query()->where('user_id', $user->id)
            ->whereHas('updates', fn ($q) => $q->whereBetween('created_at', [$from->copy()->subDays(3), $to]))
            ->with(['updates', 'parent'])->get();

        $videos = [];
        foreach ($todos as $t) {
            $video = $t->parent ?? $t;
            $open = null;
            $spans = [];
            foreach ($t->updates->sortBy('id') as $u) {
                if ($u->action !== TodoUpdate::STATUS && $u->action !== TodoUpdate::CREATED) {
                    continue;
                }
                if ($open !== null) {
                    $spans[] = [$open, $u->created_at];
                    $open = null;
                }
                if ($u->to_status === Todo::STATUS_STARTED) {
                    $open = $u->created_at;
                }
            }
            if ($open !== null) {
                $spans[] = [$open, $to];
            }
            foreach ($spans as [$a, $b]) {
                $a = $a->max($from);
                $b = $b->min($to);
                if ($b->lte($a)) {
                    continue;
                }
                $v = &$videos[$video->id];
                $v ??= ['title' => $video->title, 'client' => $video->venture, 'start' => $a, 'end' => $b, 'minutes' => 0, 'fix' => false];
                $v['start'] = $v['start']->min($a);
                $v['end'] = $v['end']->max($b);
                $v['minutes'] += (int) $a->diffInMinutes($b);
                $v['fix'] = $v['fix'] || $t->parent_id !== null;
                unset($v);
            }
        }

        return array_values(array_map(fn ($v) => [
            'task' => 'Edit — '.$v['title'].($v['fix'] ? ' (with corrections)' : ''),
            'type' => TimesheetEntry::TASK_EDITING,
            'client' => $v['client'],
            'start' => $v['start']->format('H:i'),
            'end' => $v['end']->format('H:i'),
            'minutes' => max(1, $v['minutes']),
            'why' => 'From My Editing: worked on it '.self::span($v['minutes']).' between '.$v['start']->format('g:i A').' and '.$v['end']->format('g:i A').'.',
        ], array_filter($videos, fn ($v) => $v['minutes'] >= 5)));
    }

    /** Shoots they were on that rolled that day, from Start to Wrap. */
    private static function shoots(User $user, Carbon $from, Carbon $to): array
    {
        return Shoot::query()
            ->whereHas('crew', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('started_at')->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('finished_at')->orWhere('finished_at', '>=', $from))
            ->with('client')->get()
            ->map(function (Shoot $s) use ($from, $to) {
                $a = $s->started_at->max($from);
                $b = ($s->finished_at ?? $to)->min($to);

                return [
                    'task' => 'Shoot — '.trim($s->title),
                    'type' => TimesheetEntry::TASK_SHOOTING,
                    'client' => $s->client ? TimesheetVenture::canonicalForClient($s->client) : null,
                    'start' => $a->format('H:i'),
                    'end' => $b->format('H:i'),
                    'minutes' => (int) $a->diffInMinutes($b),
                    'why' => 'From My Shoots: rolled '.$a->format('g:i A').' to '.($s->finished_at ? $b->format('g:i A') : 'now').'.',
                ];
            })->all();
    }

    /** Videos they marked posted or delivered that day. */
    private static function posting(User $user, Carbon $from, Carbon $to): array
    {
        return TodoUpdate::query()->where('user_id', $user->id)
            ->whereIn('action', [TodoUpdate::POSTED, TodoUpdate::DELIVERED])
            ->whereBetween('created_at', [$from, $to])->with('todo')->get()
            ->filter(fn (TodoUpdate $u) => $u->todo)
            ->map(fn (TodoUpdate $u) => [
                'task' => ($u->action === TodoUpdate::POSTED ? 'Post — ' : 'Deliver — ').$u->todo->title,
                'type' => TimesheetEntry::TASK_POSTING,
                'client' => $u->todo->venture,
                'start' => $u->created_at->format('H:i'),
                'end' => null,
                'minutes' => null,
                'why' => ($u->action === TodoUpdate::POSTED ? 'Marked posted' : 'Marked delivered').' at '.$u->created_at->format('g:i A').'. Add how long it took.',
            ])->values()->all();
    }

    /** Already on the timesheet: the same video named in the task, or the same kind of work over the same time. */
    private static function sameWork(TimesheetEntry $e, array $h): bool
    {
        $title = trim(preg_replace('/^(Edit|Shoot|Post|Deliver) — /u', '', preg_replace('/ \(with corrections\)$/u', '', $h['task'])));
        if ($title !== '' && str_contains(mb_strtolower((string) $e->task), mb_strtolower($title))) {
            return true;
        }
        if ($e->task_type !== $h['type'] || ! $e->started_at || ! $e->ended_at || ! $h['start'] || ! $h['end']) {
            return false;
        }

        return substr($e->started_at, 0, 5) < $h['end'] && substr($e->ended_at, 0, 5) > $h['start'];
    }

    private static function span(int $m): string
    {
        return $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    }
}
