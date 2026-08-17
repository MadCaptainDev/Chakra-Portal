<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Mcp\Tool;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Writing an entry, under exactly the rules the web form uses.
 *
 * Nobody logs work for anybody else, here or anywhere in the portal: a
 * timesheet is a personal statement about what you did. There is deliberately
 * no `person` argument.
 */
class LogTimesheetEntry extends Tool
{
    public function name(): string
    {
        return 'log_timesheet_entry';
    }

    public function description(): string
    {
        return 'Log a piece of work on the caller\'s own timesheet. Give either start and end '
            .'times or a duration in minutes. Entries for an earlier day are marked as filed '
            .'late. Adding to a day a manager has already decided puts that day back under '
            .'review. You cannot log work for anybody else.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function schema(): array
    {
        return $this->object([
            'task' => ['type' => 'string', 'description' => 'What was done, e.g. "Edit — SVA reel v2".'],
            'date' => ['type' => 'string', 'format' => 'date', 'description' => 'The day it was worked, YYYY-MM-DD. Defaults to today.'],
            'type' => [
                'type' => 'string',
                'enum' => array_keys(TimesheetEntry::taskTypes()),
                'description' => 'Kind of work. Guessed from the task name if omitted.',
            ],
            'client' => ['type' => 'string', 'description' => 'Which client it was for. Use the exact client name; there is a catch-all for work spanning several.'],
            'start' => ['type' => 'string', 'description' => '24-hour start time, HH:MM.'],
            'end' => ['type' => 'string', 'description' => '24-hour finish time, HH:MM. May be earlier than start, meaning it ran past midnight.'],
            'minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440, 'description' => 'Duration, when times are not known. Ignored if both start and end are given.'],
            'notes' => ['type' => 'string', 'description' => 'Anything worth saying about it.'],
        ], ['task']);
    }

    public function handle(array $arguments, User $user): array
    {
        $workedOn = $this->day($arguments['date'] ?? null);

        $minutes = TimesheetEntry::minutesBetween($arguments['start'] ?? null, $arguments['end'] ?? null)
            ?? (int) ($arguments['minutes'] ?? 0);

        if ($minutes <= 0) {
            throw new McpToolException('That entry has no duration. Give start and end times, or minutes.');
        }

        $entry = new TimesheetEntry([
            'user_id' => $user->id,
            'worked_on' => $workedOn->toDateString(),
            'task' => $arguments['task'],
            'task_type' => $arguments['type'] ?? TimesheetEntry::inferTaskType($arguments['task']),
            'venture' => $this->client($arguments['client'] ?? null),
            'started_at' => $arguments['start'] ?? null,
            'ended_at' => $arguments['end'] ?? null,
            'minutes' => $minutes,
            'notes' => $arguments['notes'] ?? null,
        ]);

        $entry->was_backdated = TimesheetEntry::isLateFor($workedOn->toDateString());
        $entry->save();

        // The same reopening the web form does: a manager signed off what the
        // day said then, not what it says now.
        $reopened = TimesheetDay::where('user_id', $user->id)
            ->whereDate('worked_on', $workedOn->toDateString())
            ->delete();

        return array_filter([
            'logged' => $entry->task,
            'date' => $workedOn->toDateString(),
            'duration' => $entry->durationLabel(),
            'client' => $entry->venture,
            'filed_late' => $entry->was_backdated ?: null,
            'note' => $reopened > 0
                ? 'That day had already been decided, so it has gone back under review.'
                : null,
        ], fn ($value) => $value !== null);
    }

    private function day(?string $value): Carbon
    {
        if ($value === null) {
            return today();
        }

        try {
            $day = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new McpToolException('That date could not be read. Use YYYY-MM-DD.');
        }

        if ($day->gt(today())) {
            throw new McpToolException('That day has not happened yet. A timesheet records work already done.');
        }

        return $day;
    }

    /**
     * Clients come from a fixed list, so a made-up name is caught here rather
     * than quietly stored as a client nobody has.
     */
    private function client(?string $value): string
    {
        return Clients::match($value);
    }
}
