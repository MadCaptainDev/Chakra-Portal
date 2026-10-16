<?php

namespace App\Tools\Editing;

use App\Models\User;
use App\Support\TimesheetHints;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

/**
 * What the caller did on a day, from My Editing, My Shoots and posting,
 * worded as timesheet rows -- the same hints My Timesheet shows. Nothing is
 * logged; pass a row to log_timesheet_entry to add it.
 */
class TimesheetHint extends Tool
{
    public function name(): string
    {
        return 'timesheet_hint';
    }

    public function title(): string
    {
        return 'Timesheet hints';
    }

    public function group(): string
    {
        return 'Timesheet';
    }

    public function description(): string
    {
        return 'Suggests your timesheet rows for a day from what you already recorded: editing time per video '
            .'(from My Editing start/done/hold), shoots you were on (start to wrap) and videos you posted, each with '
            .'task, type, client, start and end time, and whether it already looks logged. USE WHEN someone asks '
            .'"what should I put in my timesheet", "fill my timesheet for today". Nothing is saved: confirm the rows '
            .'with the person, then call log_timesheet_entry for each one they want. Read-only.';
    }

    public function availableTo(User $user): bool
    {
        return ! $user->isClient();
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'date' => ['type' => 'string', 'format' => 'date', 'description' => 'The day, YYYY-MM-DD. Defaults to today.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $day = rescue(fn () => Carbon::parse($arguments['date'] ?? today()->toDateString())->startOfDay(), null, false);
        if (! $day || $day->isFuture()) {
            throw new ToolException('Give a date that has happened, as YYYY-MM-DD.');
        }

        $hints = TimesheetHints::for($user, $day);

        return [
            'date' => $day->toDateString(),
            'to_add' => array_values(array_filter($hints, fn ($h) => ! $h['logged'])),
            'already_logged' => array_values(array_map(fn ($h) => $h['task'], array_filter($hints, fn ($h) => $h['logged']))),
            'how' => 'Offer these to the person; for each they accept, call log_timesheet_entry with task, type, client, date, start and end (or minutes).',
        ];
    }
}
