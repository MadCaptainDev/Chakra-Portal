<?php

namespace App\Services\AdminAgent\Tools;

use App\Models\Shoot;
use App\Models\User;
use App\Services\AdminAgent\Tool;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * The diary, for any window other than today.
 *
 * Takes two dates rather than words like "next week", because resolving
 * "next week" against the studio's own calendar is the model's job and it is
 * told today's date -- a tool that also tried to parse English would give two
 * different answers to the same question depending on which one got there
 * first.
 */
class ShootsBetweenTool implements Tool
{
    /**
     * A window wider than this is somebody asking for the whole year on a
     * phone. Answered, but trimmed, with the count so the model can say so.
     */
    private const MAX_ROWS = 15;

    public function name(): string
    {
        return 'shoots_between';
    }

    public function definition(): array
    {
        return [
            'name' => 'shoots_between',
            'description' => "Shoots scheduled between two dates inclusive, with call time, client, location and assigned crew. Dates must be YYYY-MM-DD; work them out yourself from today's date, which is in your instructions.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
                    'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD. Same as `from` for a single day.'],
                ],
                'required' => ['from', 'to'],
            ],
        ];
    }

    public function run(User $admin, array $input): string
    {
        try {
            $from = Carbon::parse((string) ($input['from'] ?? ''))->startOfDay();
            $to = Carbon::parse((string) ($input['to'] ?? ''))->endOfDay();
        } catch (InvalidFormatException) {
            return 'Those dates could not be read. Use YYYY-MM-DD.';
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $shoots = Shoot::query()
            ->whereBetween('starts_at', [$from, $to])
            ->with('crew.user', 'client')
            ->orderBy('starts_at')
            ->get();

        if ($shoots->isEmpty()) {
            return 'Nothing scheduled between '.$from->format('j M Y').' and '.$to->format('j M Y').'.';
        }

        $lines = [$shoots->count().' shoots between '.$from->format('j M Y').' and '.$to->format('j M Y').':'];

        foreach ($shoots->take(self::MAX_ROWS) as $shoot) {
            $crew = $shoot->crew->map(fn ($member) => $member->user?->name)->filter();

            $lines[] = implode(' | ', array_filter([
                $shoot->starts_at?->format('D j M'),
                // Midnight means a date synced with no time on it, the same
                // rule AdminPortal::whenSuffix() follows -- printing
                // "12:00 AM" would read as a genuine small-hours call.
                $shoot->starts_at && ! ($shoot->starts_at->hour === 0 && $shoot->starts_at->minute === 0)
                    ? $shoot->starts_at->format('g:i A')
                    : null,
                $shoot->title,
                $shoot->client?->name,
                $shoot->location,
                'crew: '.($crew->isEmpty() ? 'nobody assigned' : $crew->implode(', ')),
                $shoot->status,
            ]));
        }

        if ($shoots->count() > self::MAX_ROWS) {
            $lines[] = '…'.($shoots->count() - self::MAX_ROWS).' more not listed.';
        }

        return implode("\n", $lines);
    }
}
