<?php

namespace App\Support;

use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who entered their timesheet on a day, and who did not -- the 9 PM WhatsApp
 * report (whatsapp:timesheet-report).
 *
 * "Who should have" is User::whoLogWork(), the same rule the morning brief's
 * "did not log" line and the timesheet screens use, so the three can never
 * disagree about who was missing. Cancelled entries do not count as having
 * logged, the same as everywhere hours are added up.
 */
class TimesheetDayReport
{
    /** Meta's template name -- see SeedTimesheetReportTemplate. */
    public const TEMPLATE = 'timesheet_daily_v1';

    /**
     * @param  list<array{name: string, minutes: int, label: string}>  $entered
     * @param  list<string>  $missing
     */
    private function __construct(
        public readonly Carbon $day,
        public readonly array $entered,
        public readonly array $missing,
    ) {}

    public static function for(Carbon $day): self
    {
        $minutes = TimesheetEntry::query()
            ->counted()
            ->whereDate('worked_on', $day->toDateString())
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(minutes) as total')
            ->pluck('total', 'user_id');

        $people = User::query()->whoLogWork()->orderBy('name')->get(['id', 'name']);

        $entered = [];
        $missing = [];

        foreach ($people as $person) {
            if ($minutes->has($person->id)) {
                $total = (int) $minutes[$person->id];
                $entered[] = ['name' => $person->name, 'minutes' => $total, 'label' => self::hm($total)];
            } else {
                $missing[] = $person->name;
            }
        }

        // Most hours first: the list an owner reads top-down at night.
        usort($entered, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes'] ?: strcmp($a['name'], $b['name']));

        return new self($day->copy()->startOfDay(), $entered, $missing);
    }

    public function total(): int
    {
        return count($this->entered) + count($this->missing);
    }

    /**
     * The full message, for a number inside WhatsApp's 24-hour window.
     */
    public function text(): string
    {
        $lines = [
            '*Timesheet · '.$this->day->format('D j M').'*',
            count($this->entered).' of '.$this->total().' entered',
            '',
        ];

        if ($this->missing !== []) {
            $lines[] = '❌ *Not entered ('.count($this->missing).')*';
            foreach ($this->missing as $name) {
                $lines[] = '• '.$name;
            }
            $lines[] = '';
        }

        if ($this->entered !== []) {
            $lines[] = '✅ *Entered ('.count($this->entered).')*';
            foreach ($this->entered as $row) {
                $lines[] = '• '.$row['name'].' — '.$row['label'];
            }

            $lines[] = '';
            $lines[] = 'Team total: '.self::hm(array_sum(array_column($this->entered, 'minutes')));
        } else {
            $lines[] = 'Nobody has entered anything yet.';
        }

        if ($this->missing === [] && $this->entered !== []) {
            $lines[] = 'Everyone is in. 👏';
        }

        return implode("\n", $lines);
    }

    /**
     * {{1}}..{{5}} for the template, for a number outside the window. Meta
     * refuses newlines inside a parameter, so the lists are comma-separated.
     *
     * @return list<string>
     */
    public function templateParameters(): array
    {
        return [
            $this->day->format('D j M'),
            (string) count($this->entered),
            (string) $this->total(),
            $this->entered === []
                ? 'nobody yet'
                : implode(', ', array_map(fn (array $r) => $r['name'].' ('.$r['label'].')', $this->entered)),
            $this->missing === [] ? 'nobody, everyone is in' : implode(', ', $this->missing),
        ];
    }

    private static function hm(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }
}
