<?php

namespace App\Support;

use App\Models\Shoot;
use App\Models\TimesheetEntry;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The work report for a day, a week or a month: hours from the timesheets
 * (by person, by client, by kind of work, and who did not fill it in),
 * videos from the editing board (sent for review, posted, delivered,
 * corrections, cancelled -- with why) and shoots. Read-only; every number
 * comes from rows that already exist. See WorkReportController.
 */
class WorkReport
{
    public const PERIODS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];

    public function __construct(
        public readonly string $period,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function for(string $period, ?string $date): self
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'week';
        $day = rescue(fn () => Carbon::parse($date ?: 'today'), today(), false)->startOfDay();

        [$from, $to] = match ($period) {
            'day' => [$day->copy(), $day->copy()],
            'week' => [$day->copy()->startOfWeek(Carbon::MONDAY), $day->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            'month' => [$day->copy()->startOfMonth(), $day->copy()->endOfMonth()->startOfDay()],
        };

        return new self($period, $from, $to);
    }

    public function label(): string
    {
        return match ($this->period) {
            'day' => $this->from->format('l, j F Y'),
            'week' => $this->from->format('j M').' – '.$this->to->format('j M Y'),
            'month' => $this->from->format('F Y'),
        };
    }

    public function previous(): string
    {
        return match ($this->period) {
            'day' => $this->from->copy()->subDay()->toDateString(),
            'week' => $this->from->copy()->subWeek()->toDateString(),
            'month' => $this->from->copy()->subMonthNoOverflow()->toDateString(),
        };
    }

    public function next(): ?string
    {
        $next = match ($this->period) {
            'day' => $this->from->copy()->addDay(),
            'week' => $this->from->copy()->addWeek(),
            'month' => $this->from->copy()->addMonthNoOverflow(),
        };

        return $next->gt(today()) ? null : $next->toDateString();
    }

    /** Working days in the range, up to today: every day but Sunday. */
    public function workingDays(): Collection
    {
        $days = collect();
        for ($d = $this->from->copy(); $d->lte($this->to) && $d->lte(today()); $d->addDay()) {
            if (! $d->isSunday()) {
                $days->push($d->toDateString());
            }
        }

        return $days;
    }

    private function entries(): Collection
    {
        return once(fn () => TimesheetEntry::query()
            ->whereBetween('worked_on', [$this->from->toDateString(), $this->to->toDateString()])
            ->get(['id', 'user_id', 'worked_on', 'task', 'task_type', 'venture', 'ventures', 'minutes']));
    }

    /** @return array{minutes: int, people: int, entries: int, videos_done: int, posted: int, cancelled: int, shoots: int} */
    public function totals(): array
    {
        $v = $this->videos();

        return [
            'minutes' => (int) $this->entries()->sum('minutes'),
            'people' => $this->entries()->pluck('user_id')->unique()->count(),
            'entries' => $this->entries()->count(),
            'videos_done' => $v['done']->count(),
            'posted' => $v['posted']->count(),
            'cancelled' => $v['cancelled']->count(),
            'shoots' => $this->shoots()->count(),
        ];
    }

    /**
     * Everyone who logs work: hours, days they logged, days they did not,
     * and how the hours split by kind of work.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function people(): Collection
    {
        $byUser = $this->entries()->groupBy('user_id');
        $working = $this->workingDays();
        $v = $this->videos();

        return User::query()->whoLogWork()->orderBy('name')->get(['id', 'name'])
            ->map(function (User $u) use ($byUser, $working, $v) {
                $rows = $byUser->get($u->id, collect());
                $logged = $rows->pluck('worked_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique();

                return [
                    'user' => $u,
                    'minutes' => (int) $rows->sum('minutes'),
                    'by_type' => $rows->groupBy('task_type')->map(fn ($g) => (int) $g->sum('minutes'))->all(),
                    'days_logged' => $logged->count(),
                    'missing' => $working->diff($logged)->values(),
                    'videos_done' => $v['done']->where('user_id', $u->id)->count(),
                    'posted' => $v['posted']->where('user_id', $u->id)->count(),
                    'cancelled' => $v['cancelled']->where('user_id', $u->id)->count(),
                ];
            })
            ->filter(fn ($p) => $p['minutes'] > 0 || $p['videos_done'] > 0 || $p['missing']->isNotEmpty())
            ->sortByDesc('minutes')->values();
    }

    /**
     * Hours per client. An entry for several clients splits evenly.
     *
     * @return Collection<string, int> venture => minutes, most first
     */
    public function clients(): Collection
    {
        $out = [];
        foreach ($this->entries() as $e) {
            $names = collect($e->ventures ?: [$e->venture])->filter()->values();
            if ($names->isEmpty()) {
                $names = collect(['Not set']);
            }
            foreach ($names as $n) {
                $out[$n] = ($out[$n] ?? 0) + intdiv((int) $e->minutes, $names->count());
            }
        }

        return collect($out)->sortDesc();
    }

    /** @return Collection<string, int> task type label => minutes */
    public function types(): Collection
    {
        return $this->entries()->groupBy('task_type')
            ->mapWithKeys(fn ($g, $t) => [TimesheetEntry::TASK_TYPES[$t] ?? ucfirst((string) $t ?: 'Other') => (int) $g->sum('minutes')])
            ->sortDesc();
    }

    /**
     * Hours per day across the range (a week or a month), for the bars.
     *
     * @return Collection<string, int> Y-m-d => minutes
     */
    public function daily(): Collection
    {
        $by = $this->entries()->groupBy(fn ($e) => Carbon::parse($e->worked_on)->toDateString())->map(fn ($g) => (int) $g->sum('minutes'));
        $out = collect();
        for ($d = $this->from->copy(); $d->lte($this->to); $d->addDay()) {
            $out[$d->toDateString()] = (int) ($by[$d->toDateString()] ?? 0);
        }

        return $out;
    }

    /**
     * Videos (top-level cards) by what happened to them in the range:
     * sent for review (done), posted, delivered, cancelled (with why and how
     * far it got), and corrections asked.
     *
     * @return array{done: Collection, posted: Collection, delivered: Collection, cancelled: Collection, corrections: Collection}
     */
    public function videos(): array
    {
        return once(function () {
            [$from, $to] = [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()];

            $moves = TodoUpdate::query()->whereBetween('created_at', [$from, $to])
                ->whereHas('todo', fn ($q) => $q->whereNull('parent_id'))
                ->whereIn('to_status', [Todo::STATUS_COMPLETED, Todo::STATUS_CANCELLED])
                ->with('todo:id,user_id,title,venture,kind,status,posted_at,ready_at', 'todo.user:id,name', 'user:id,name')
                ->orderBy('created_at')->get();

            $done = $moves->where('to_status', Todo::STATUS_COMPLETED)->unique('todo_id')->map(fn ($m) => $m->todo)->filter()->values();
            $cancelled = $moves->where('to_status', Todo::STATUS_CANCELLED)
                ->filter(fn ($m) => $m->todo && $m->todo->status === Todo::STATUS_CANCELLED)
                ->unique('todo_id')
                ->map(fn ($m) => [
                    'todo' => $m->todo,
                    'user_id' => $m->todo->user_id,
                    'at' => $m->created_at,
                    'by' => $m->user?->name,
                    'stage' => match ($m->from_status) {
                        Todo::STATUS_COMPLETED => 'after editing',
                        Todo::STATUS_STARTED => 'while editing',
                        Todo::STATUS_BLOCKED => 'on hold',
                        default => 'before editing',
                    },
                    'edited' => $m->from_status === Todo::STATUS_COMPLETED || str_starts_with((string) $m->note, 'Cancelled after editing'),
                    'reason' => preg_replace('/^Cancelled after editing:\s*/', '', (string) $m->note),
                ])->values();

            $posted = Todo::query()->whereNull('parent_id')->whereBetween('posted_at', [$from, $to])
                ->with('user:id,name')->get(['id', 'user_id', 'title', 'venture', 'kind', 'posted_at']);
            $delivered = $posted->where('kind', Todo::KIND_DELIVERED)->values();

            $corrections = Todo::query()->whereNotNull('parent_id')->whereBetween('created_at', [$from, $to])
                ->with('parent:id,user_id,title')->get(['id', 'parent_id', 'user_id', 'title', 'created_at']);

            return [
                'done' => $done,
                'posted' => $posted->where('kind', '!=', Todo::KIND_DELIVERED)->values(),
                'delivered' => $delivered,
                'cancelled' => $cancelled,
                'corrections' => $corrections,
            ];
        });
    }

    /**
     * The YouTube Short duty per person: reels they posted on Instagram in
     * the range, and whether the Short went up on time, late, or not at all.
     *
     * @return Collection<int, array{user: User, on_time: int, late: int, missing: int}>
     */
    public function shorts(): Collection
    {
        $rec = \App\Support\YoutubeShorts::record($this->from, $this->to);
        $names = User::query()->whereIn('id', $rec->keys())->get(['id', 'name'])->keyBy('id');

        return $rec->map(fn ($r, $id) => $r + ['user' => $names[$id] ?? null])
            ->filter(fn ($r) => $r['user'])
            ->sortByDesc(fn ($r) => [$r['missing'], $r['late']])->values();
    }

    /** Shoots that started in the range, with how long they ran and how many videos. */
    public function shoots(): Collection
    {
        return once(fn () => Shoot::query()
            ->whereBetween('started_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->withCount('videos')->with('client')->orderBy('started_at')->get()
            ->map(fn (Shoot $s) => [
                'shoot' => $s,
                'minutes' => $s->finished_at ? (int) round($s->started_at->diffInMinutes($s->finished_at, true)) : null,
            ]));
    }

    public static function hm(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0h';
        }

        return trim(($minutes >= 60 ? intdiv($minutes, 60).'h' : '').($minutes % 60 ? ' '.($minutes % 60).'m' : ''));
    }
}
