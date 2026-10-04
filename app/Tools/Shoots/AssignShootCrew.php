<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Notifications\ShootCrewAdded;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AssignShootCrew extends Tool
{
    public function name(): string
    {
        return 'assign_shoot_crew';
    }

    public function title(): string
    {
        return 'Put someone on a shoot\'s crew';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Add a team member to a shoot\'s crew, with an optional role ("Camera", "Lights") and '
            .'call time. Someone already on the crew just has their role and call time updated. '
            .'A newly added person gets a push notification on their phone (not when you add yourself, '
            .'or the shoot is cancelled or past). Warns -- but does not refuse -- when the person is '
            .'already on another shoot the same day; tell the person about any warning. '
            .'Get shoot_id from list_shoots or create_shoot.';
    }

    public function permission(): ?string
    {
        return 'shoots.edit';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'shoot_id' => ['type' => 'integer'],
            'person' => ['type' => 'string', 'description' => 'Team member\'s full name or email, as in the portal.'],
            'role' => ['type' => 'string', 'description' => 'Optional, up to 80 characters.'],
            'call_time' => ['type' => 'string', 'description' => 'Optional. HH:MM, 24-hour, on the shoot day.'],
        ], ['shoot_id', 'person']);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoot = Shoot::find((int) ($arguments['shoot_id'] ?? 0))
            ?? throw new ToolException('There is no shoot with that id. Use list_shoots.');

        if ($shoot->status === Shoot::STATUS_CANCELLED) {
            throw new ToolException('That shoot is cancelled.');
        }

        $member = self::member((string) ($arguments['person'] ?? ''));

        $callTime = $arguments['call_time'] ?? null;
        if (filled($callTime) && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $callTime)) {
            throw new ToolException('call_time must be HH:MM, 24-hour, e.g. 07:30.');
        }

        $crew = $shoot->crew()->updateOrCreate(
            ['user_id' => $member->id],
            ['role' => isset($arguments['role']) ? mb_substr((string) $arguments['role'], 0, 80) : null, 'call_time' => $callTime ?: null]
        );

        $notified = false;
        if ($crew->wasRecentlyCreated && $member->id !== $user->id && ! $shoot->starts_at->isPast()) {
            try {
                Notification::send($member, new ShootCrewAdded($crew));
                $notified = true;
            } catch (Throwable $e) {
                Log::error('Shoot crew push failed.', ['shoot_crew_id' => $crew->id, 'error' => $e->getMessage()]);
            }
        }

        $sameDay = Shoot::query()
            ->whereKeyNot($shoot->id)
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereDate('starts_at', $shoot->starts_at->toDateString())
            ->whereHas('crew', fn ($q) => $q->where('user_id', $member->id))
            ->get();

        return [
            'person' => $member->name,
            'shoot' => $shoot->title,
            'shoot_starts_at' => $shoot->starts_at->format('Y-m-d H:i'),
            'role' => $crew->role,
            'call_time' => $crew->call_time ? substr((string) $crew->call_time, 0, 5) : null,
            'result' => $crew->wasRecentlyCreated ? 'added' : 'already on the crew; details updated',
            'push_notification_sent' => $notified,
            'warnings' => $sameDay->map(fn (Shoot $s) => "{$member->name} is also on \"{$s->title}\" at {$s->starts_at->format('H:i')} that day.")->all(),
        ];
    }

    private static function member(string $needle): User
    {
        $needle = trim($needle);
        if ($needle === '') {
            throw new ToolException('Say who to add: a full name or email.');
        }

        $matches = User::staff()
            ->where(fn ($q) => $q->where('email', $needle)->orWhere('name', 'like', $needle.'%'))
            ->orderBy('name')
            ->get();

        $exact = $matches->first(fn (User $u) => strcasecmp($u->name, $needle) === 0);

        if ($exact) {
            return $exact;
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            throw new ToolException("No team member called \"{$needle}\".");
        }

        throw new ToolException("\"{$needle}\" matches more than one person — ask which: ".$matches->pluck('name')->implode(', ').'.');
    }
}
