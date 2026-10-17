<?php

namespace App\Tools\Studio;

use App\Models\User;
use App\Support\ContentForecast;
use App\Tools\Tool;

class BookShoots extends Tool
{
    public function name(): string
    {
        return 'book_shoots';
    }

    public function title(): string
    {
        return 'Clients who need a shoot booked';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Which clients are running out of reels with no shoot booked in time: reels left to post, their weekly pace, '
            .'the day they run out, the day to book by, and the next shoot if any -- grouped book now (within a week), book soon '
            .'(within three weeks), covered, and cannot be counted (no Notion link or no reel target). USE WHEN asked "who needs a '
            .'shoot", "who is running out of content". Read-only.';
    }

    public function permission(): ?string
    {
        return 'forecast.view';
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
        $row = fn (array $r) => [
            'client' => $r['client']->name, 'reels_left' => $r['remaining'],
            'per_week' => $r['weekly_cadence'] !== null ? round($r['weekly_cadence'], 1) : null,
            'runs_out' => $r['depletion_date']?->toDateString(), 'book_by' => $r['book_by_date']?->toDateString(),
            'next_shoot' => $r['next_shoot_date']?->toDateString(),
        ];
        $all = ContentForecast::forAllClients()->groupBy('status');

        return [
            'book_now' => $all->get(ContentForecast::STATUS_CRITICAL, collect())->map($row)->values()->all(),
            'book_soon' => $all->get(ContentForecast::STATUS_WARNING, collect())->map($row)->values()->all(),
            'covered' => $all->get(ContentForecast::STATUS_OK, collect())->map(fn ($r) => $r['client']->name)->values()->all(),
            'cannot_be_counted' => $all->get(ContentForecast::STATUS_UNKNOWN, collect())->map(fn ($r) => $r['client']->name.($r['linked'] ? ' (no reel target)' : ' (not connected to Notion)'))->values()->all(),
        ];
    }
}
