<?php

namespace App\Tools\Shoots;

use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootVideo;
use App\Models\User;
use App\Tools\Tool;

/**
 * The caller's own shoots -- the ones they are crew on -- for the next two
 * weeks, with the client's scripts still to shoot. Needs no Shoots module:
 * being named on the shoot is the permission, as on My Shoots.
 */
class MyShoots extends Tool
{
    public function name(): string
    {
        return 'my_shoots';
    }

    public function title(): string
    {
        return 'My shoots';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Your own shoots (the ones you are crew on) from today to two weeks ahead: when, where, the client, '
            .'the rest of the crew, its state (not started, on the way, reached, rolling, wrapped), videos logged, '
            .'and the client\'s scripts still to shoot with their status. USE WHEN a crew member asks "what is my next '
            .'shoot", "which scripts are we shooting tomorrow". For every shoot in the studio use list_shoots. Read-only.';
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
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoots = Shoot::query()->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereHas('crew', fn ($q) => $q->where('user_id', $user->id))
            ->where(fn ($q) => $q->inProgress()->orWhereBetween('starts_at', [today(), now()->addDays(14)->endOfDay()]))
            ->with(['client', 'crew.user'])->withCount('videos')->orderBy('starts_at')->get();

        $shotIds = ShootVideo::query()->whereNotNull('script_id')->pluck('script_id')->unique();

        return [
            'shoots' => $shoots->map(fn (Shoot $s) => [
                'shoot_id' => $s->id,
                'title' => trim($s->title),
                'client' => $s->client?->name,
                'when' => $s->starts_at->format('Y-m-d H:i'),
                'location' => $s->location,
                'state' => match (true) {
                    $s->isInProgress() => 'rolling',
                    $s->hasWrapped() => 'wrapped',
                    $s->hasReached() => 'reached',
                    $s->hasDeparted() => 'on the way',
                    default => 'not started',
                },
                'crew' => $s->crew->map(fn ($c) => $c->user ? $c->user->name.($c->role ? ' ('.$c->role.')' : '') : null)->filter()->values()->all(),
                'notes' => $s->notes,
                'videos_logged' => $s->videos_count,
                'scripts_to_shoot' => $s->client_id ? Script::query()->where('client_id', $s->client_id)
                    ->where('status', '!=', Script::STATUS_ARCHIVED)->whereNull('shot_at')->whereNotIn('id', $shotIds)
                    ->orderBy('id')->get()
                    ->map(fn (Script $sc) => ['number' => '#'.$sc->id, 'title' => $sc->title,
                        'status' => $sc->status === Script::STATUS_COMPLETED ? 'Approved' : $sc->statusLabel()])
                    ->values()->all() : [],
                'open_on_phone' => route('my.shoots.run', $s),
            ])->all(),
            'how' => 'To read a script word for word, call get_script with its number.',
        ];
    }
}
