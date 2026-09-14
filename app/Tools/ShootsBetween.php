<?php

namespace App\Tools;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * The diary for any window other than today.
 *
 * Takes two dates rather than words like "next week", because resolving that
 * against the studio's calendar is the model's job -- a tool that also tried
 * to parse English would give two different answers to one question depending
 * on which got there first.
 */
class ShootsBetween extends Tool
{
    private const MAX_ROWS = 15;

    public function name(): string
    {
        return 'shoots_between';
    }

    public function description(): string
    {
        return 'Shoots scheduled between two dates inclusive, with call time, client, location and '
            .'assigned crew. Dates must be YYYY-MM-DD; work them out yourself from today.';
    }

    public function permission(): ?string
    {
        return 'shoots.view';
    }

    public function schema(): array
    {
        return $this->object([
            'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD. Same as `from` for a single day.'],
        ], ['from', 'to']);
    }

    public function handle(array $arguments, User $user): string
    {
        try {
            $from = Carbon::parse((string) ($arguments['from'] ?? ''))->startOfDay();
            $to = Carbon::parse((string) ($arguments['to'] ?? ''))->endOfDay();
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
