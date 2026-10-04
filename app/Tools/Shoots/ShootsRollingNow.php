<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Tools\Tool;

class ShootsRollingNow extends Tool
{
    public function name(): string
    {
        return 'shoots_rolling_now';
    }

    public function title(): string
    {
        return 'Shoots happening right now';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Shoots a crew member has started on their phone and not yet wrapped: who started it, '
            .'when, the crew, and how many videos have been logged so far with their names. '
            .'USE WHEN asked "who is shooting now", "how far along is the SVA shoot". '
            .'For the calendar of planned shoots use list_shoots instead. Read-only.';
    }

    public function permission(): ?string
    {
        return 'shoots.view';
    }

    public function schema(): array
    {
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoots = Shoot::inProgress()->with(['client', 'startedBy', 'crew.user', 'videos'])->orderBy('started_at')->get();

        return [
            'rolling' => $shoots->count(),
            'shoots' => $shoots->map(fn (Shoot $s) => [
                'shoot_id' => $s->id,
                'title' => $s->title,
                'client' => $s->client?->name,
                'location' => $s->location,
                'started_by' => $s->startedBy?->name,
                'started_at' => $s->started_at?->format('Y-m-d H:i'),
                'running_for_minutes' => (int) $s->started_at->diffInMinutes(now()),
                'crew' => $s->crew->map(fn ($c) => $c->user?->name)->filter()->values()->all(),
                'videos_logged' => $s->videos->count(),
                'video_names' => $s->videos->pluck('name')->filter()->values()->all(),
            ])->all(),
        ];
    }
}
