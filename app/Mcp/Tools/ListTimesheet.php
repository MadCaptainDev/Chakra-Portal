<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Mcp\Tool;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

class ListTimesheet extends Tool
{
    public function name(): string
    {
        return 'list_timesheet';
    }

    public function description(): string
    {
        return 'Hours logged over a date range, day by day, with each day\'s manager decision '
            .'(accepted, sent back, or still under review). Defaults to the caller\'s own '
            .'timesheet and the last 7 days. Managers and admins may pass person to read '
            .'somebody else\'s. Use this for "what did I work on", "how many hours last week", '
            .'or "has my Tuesday been approved".';
    }

    public function schema(): array
    {
        return $this->object([
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First day, YYYY-MM-DD. Defaults to 7 days ago.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last day, YYYY-MM-DD. Defaults to today.'],
            'person' => ['type' => 'string', 'description' => 'Name or email of someone whose timesheet to read. Only permitted for their manager, or an admin. Omit for your own.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $subject = People::resolve($arguments['person'] ?? null, $user);
        [$from, $to] = $this->range($arguments);

        $entries = TimesheetEntry::where('user_id', $subject->id)
            ->counted()
            ->where('worked_on', '>=', $from->toDateString())
            ->where('worked_on', '<', $to->copy()->addDay()->toDateString())
            ->orderBy('worked_on')
            ->orderByRaw('started_at IS NULL')
            ->orderBy('started_at')
            ->get();

        $decisions = TimesheetDay::where('user_id', $subject->id)
            ->where('worked_on', '>=', $from->toDateString())
            ->where('worked_on', '<', $to->copy()->addDay()->toDateString())
            ->get()
            ->keyBy(fn (TimesheetDay $day) => $day->worked_on->toDateString());

        $days = $entries
            ->groupBy(fn (TimesheetEntry $entry) => $entry->worked_on->toDateString())
            ->map(function ($rows, string $date) use ($decisions) {
                $decision = $decisions->get($date);

                return [
                    'date' => $date,
                    'total' => TimesheetEntry::formatMinutes((int) $rows->sum('minutes')),
                    'minutes' => (int) $rows->sum('minutes'),
                    'review' => $decision?->stateLabel() ?? 'Under review',
                    'review_note' => $decision?->review_note,
                    'entries' => $rows->map(fn (TimesheetEntry $entry) => array_filter([
                        'task' => $entry->task,
                        'type' => $entry->taskTypeLabel(),
                        'client' => $entry->venture,
                        'from' => $entry->started_at ? substr($entry->started_at, 0, 5) : null,
                        'to' => $entry->ended_at ? substr($entry->ended_at, 0, 5) : null,
                        'duration' => $entry->durationLabel(),
                        'notes' => $entry->notes,
                        'filed_late' => $entry->was_backdated ?: null,
                    ], fn ($value) => $value !== null))->values()->all(),
                ];
            })
            ->values();

        return [
            'person' => $subject->name,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => TimesheetEntry::formatMinutes((int) $entries->sum('minutes')),
            'days_worked' => $days->count(),
            'days' => $days->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(array $arguments): array
    {
        try {
            $to = isset($arguments['to']) ? Carbon::parse($arguments['to'])->startOfDay() : today();
            $from = isset($arguments['from']) ? Carbon::parse($arguments['from'])->startOfDay() : $to->copy()->subDays(6);
        } catch (Throwable) {
            throw new McpToolException('Those dates could not be read. Use YYYY-MM-DD.');
        }

        if ($from->gt($to)) {
            throw new McpToolException('The "from" date is after the "to" date.');
        }

        // A model asked for "everything" will happily request ten years and get
        // a reply too big to be useful to anybody.
        if ($from->diffInDays($to) > 366) {
            throw new McpToolException('That range is longer than a year. Ask for a shorter one.');
        }

        return [$from, $to];
    }
}
