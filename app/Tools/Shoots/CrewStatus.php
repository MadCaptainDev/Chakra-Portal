<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\LiveActivities\CrewTrip;
use App\Tools\Tool;
use App\Tools\ToolException;

/**
 * A crew member says where they are on a shoot day: on the way (ETA),
 * arrived, wrapped. Admins and the rest of the crew are notified, and
 * admins' iPhones show it in the CrewTrip Live Activity.
 */
class CrewStatus extends Tool
{
    public function name(): string
    {
        return 'crew_status';
    }

    public function title(): string
    {
        return 'Crew: on the way, arrived, wrapped';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Tell the studio where you are on a shoot you are crew on: on_the_way (with eta_minutes), arrived, '
            .'or wrapped. Admins and the rest of the crew get a notification. on_the_way/arrived also mark the shoot '
            .'departed/reached the first time; wrapped wraps a live shoot. An admin or a shoot editor may report for '
            .'someone else with person.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function availableTo(User $user): bool
    {
        return ! $user->isClient();
    }

    public function schema(): array
    {
        return $this->object([
            'shoot_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => CrewTrip::STATUSES],
            'eta_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 600, 'description' => 'With on_the_way: minutes until arrival.'],
            'lat' => ['type' => 'number'],
            'lng' => ['type' => 'number'],
            'person' => ['type' => 'string', 'description' => 'Admins / shoot editors only: report for this crew member (name or email).'],
        ], ['shoot_id', 'status']);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoot = Shoot::find((int) ($arguments['shoot_id'] ?? 0))
            ?? throw new ToolException('There is no shoot with that id.');
        if ($shoot->status === Shoot::STATUS_CANCELLED) {
            throw new ToolException('That shoot is cancelled.');
        }

        $status = (string) ($arguments['status'] ?? '');
        if (! in_array($status, CrewTrip::STATUSES, true)) {
            throw new ToolException('status must be one of: '.implode(', ', CrewTrip::STATUSES).'.');
        }

        $member = $user;
        if (filled($arguments['person'] ?? null)) {
            if (! $user->isAdmin() && ! $user->can('shoots.edit')) {
                throw new ToolException('Only an admin or a shoot editor can report for someone else.');
            }
            $needle = trim((string) $arguments['person']);
            $member = $shoot->crew()->with('user')->get()->pluck('user')->filter()
                ->first(fn (User $u) => strcasecmp($u->email, $needle) === 0 || stripos($u->name, $needle) === 0)
                ?? throw new ToolException("Nobody called \"{$needle}\" is on this shoot's crew.");
        }

        $crew = $shoot->crew()->where('user_id', $member->id)->first()
            ?? throw new ToolException('You are not on this shoot\'s crew. An admin can add you with assign_shoot_crew.');

        $lat = isset($arguments['lat']) ? (float) $arguments['lat'] : null;
        $lng = isset($arguments['lng']) ? (float) $arguments['lng'] : null;
        $eta = isset($arguments['eta_minutes']) ? (int) $arguments['eta_minutes'] : null;

        try {
            $message = CrewTrip::report($shoot, $crew, $member, $status, $eta, $lat, $lng);
        } catch (\RuntimeException $e) {
            throw new ToolException($e->getMessage());
        }

        return ['message' => $message, 'shoot' => UpdateShoot::shootDetails($shoot->refresh())];
    }
}
