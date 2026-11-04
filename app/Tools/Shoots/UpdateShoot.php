<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;

/**
 * Edit a booked shoot: the portal's Shoots → Edit form, field by field.
 * Only the fields given change. Crew is added with assign_shoot_crew and
 * taken off here (remove_crew); "live" is not a status but a run state,
 * see run_shoot.
 */
class UpdateShoot extends Tool
{
    public function name(): string
    {
        return 'update_shoot';
    }

    public function title(): string
    {
        return 'Edit a shoot';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Change a shoot\'s title, client, start/end time, location, status '
            .'(planned / confirmed / completed / cancelled) or notes, or take someone off its crew. '
            .'Only the fields given change. To add crew use assign_shoot_crew; to start or wrap the '
            .'shoot (the "live" state) use run_shoot. Nobody is notified. Get shoot_id from list_shoots.';
    }

    public function permission(): ?string
    {
        return 'shoots.edit';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'shoot_id' => ['type' => 'integer'],
            'title' => ['type' => 'string', 'description' => 'Up to 160 characters.'],
            'starts_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM, 24-hour, studio time.'],
            'ends_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM, not before the start. Empty string clears it.'],
            'client' => ['type' => 'string', 'description' => 'Client id or portal name. Empty string makes it an in-house shoot.'],
            'location' => ['type' => 'string', 'description' => 'Empty string clears it.'],
            'status' => ['type' => 'string', 'enum' => array_keys(Shoot::STATUSES)],
            'notes' => ['type' => 'string', 'description' => 'Brief for the crew. Replaces the old notes; empty string clears them.'],
            'remove_crew' => ['type' => 'string', 'description' => 'Take this person (full name or email) off the crew.'],
        ], ['shoot_id']);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoot = Shoot::with('client:id,name')->find((int) ($arguments['shoot_id'] ?? 0))
            ?? throw new ToolException('There is no shoot with that id. Use list_shoots.');

        $changes = [];

        if (array_key_exists('title', $arguments)) {
            $title = trim((string) $arguments['title']);
            if ($title === '' || mb_strlen($title) > 160) {
                throw new ToolException('The title must be 1 to 160 characters.');
            }
            $changes['title'] = $title;
        }

        if (array_key_exists('starts_at', $arguments)) {
            $changes['starts_at'] = CreateShoot::time($arguments['starts_at'], 'starts_at');
        }

        if (array_key_exists('ends_at', $arguments)) {
            $changes['ends_at'] = filled($arguments['ends_at']) ? CreateShoot::time($arguments['ends_at'], 'ends_at') : null;
        }

        $start = $changes['starts_at'] ?? $shoot->starts_at;
        $end = array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $shoot->ends_at;
        if ($start && $end && $end->lt($start)) {
            throw new ToolException('The end time is before the start time.');
        }

        if (array_key_exists('client', $arguments)) {
            $changes['client_id'] = filled($arguments['client']) ? ClientResolver::resolve($arguments['client'])->id : null;
        }

        if (array_key_exists('location', $arguments)) {
            $changes['location'] = filled($arguments['location']) ? mb_substr((string) $arguments['location'], 0, 255) : null;
        }

        if (array_key_exists('status', $arguments)) {
            if (! array_key_exists((string) $arguments['status'], Shoot::STATUSES)) {
                throw new ToolException('status must be one of: '.implode(', ', array_keys(Shoot::STATUSES)).'. To start or wrap a shoot use run_shoot.');
            }
            $changes['status'] = (string) $arguments['status'];
        }

        if (array_key_exists('notes', $arguments)) {
            $changes['notes'] = filled($arguments['notes']) ? mb_substr((string) $arguments['notes'], 0, 5000) : null;
        }

        $removed = null;
        if (filled($arguments['remove_crew'] ?? null)) {
            $needle = trim((string) $arguments['remove_crew']);
            $crew = $shoot->crew()->with('user:id,name,email')->get()
                ->filter(fn ($c) => $c->user && (strcasecmp($c->user->email, $needle) === 0 || stripos($c->user->name, $needle) === 0));
            if ($crew->isEmpty()) {
                throw new ToolException("Nobody called \"{$needle}\" is on this shoot's crew.");
            }
            if ($crew->count() > 1) {
                throw new ToolException("\"{$needle}\" matches more than one crew member — ask which: ".$crew->pluck('user.name')->implode(', ').'.');
            }
            $removed = $crew->first()->user->name;
            $crew->first()->delete();
        }

        if ($changes === [] && $removed === null) {
            throw new ToolException('Nothing to change: give at least one field.');
        }

        $shoot->update($changes);
        $shoot->refresh()->load(['client:id,name', 'crew.user:id,name']);

        return [
            'updated' => array_keys($changes),
            'removed_from_crew' => $removed,
            'shoot' => self::shootDetails($shoot),
        ];
    }

    /** @return array<string, mixed> */
    public static function shootDetails(Shoot $shoot): array
    {
        $shoot->loadMissing(['client:id,name', 'crew.user:id,name']);

        return [
            'shoot_id' => $shoot->id,
            'title' => $shoot->title,
            'client' => $shoot->client?->name,
            'client_id' => $shoot->client_id,
            'starts_at' => $shoot->starts_at?->format('Y-m-d H:i'),
            'ends_at' => $shoot->ends_at?->format('Y-m-d H:i'),
            'location' => $shoot->location,
            'status' => $shoot->status,
            'live' => $shoot->isInProgress(),
            'departed_at' => $shoot->departed_at?->format('Y-m-d H:i'),
            'reached_at' => $shoot->reached_at?->format('Y-m-d H:i'),
            'started_at' => $shoot->started_at?->format('Y-m-d H:i'),
            'finished_at' => $shoot->finished_at?->format('Y-m-d H:i'),
            'notes' => $shoot->notes,
            'crew' => $shoot->crew->map(fn ($c) => [
                'user_id' => $c->user_id,
                'name' => $c->user?->name,
                'role' => $c->role,
                'call_time' => $c->call_time ? substr((string) $c->call_time, 0, 5) : null,
            ])->values()->all(),
            'open_in_portal' => route('shoots.show', $shoot),
        ];
    }
}
