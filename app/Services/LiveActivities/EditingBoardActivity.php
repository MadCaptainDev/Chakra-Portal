<?php

namespace App\Services\LiveActivities;

use App\Models\LiveActivityToken;
use App\Models\Todo;
use App\Models\User;

/**
 * The admins' EditingBoard Live Activity: the floor's counts and the latest
 * move, pushed on every editing change (TodoUpdate::record). Started the
 * first time each day something moves, ended at 23:00 by
 * `live-activities:end-day`.
 */
class EditingBoardActivity
{
    /** @return array<string, mixed> */
    public static function state(string $latest): array
    {
        $top = fn () => Todo::query()->whereNull('parent_id');

        return [
            'inProgress' => $top()->where('status', Todo::STATUS_STARTED)->count(),
            'underReview' => $top()->where('status', Todo::STATUS_COMPLETED)->whereNull('posted_at')->whereNull('ready_at')
                ->whereDate('closed_on', '>=', today()->subDays(14))->count(),
            'readyToPost' => $top()->where('status', Todo::STATUS_COMPLETED)->whereNotNull('ready_at')->whereNull('posted_at')->count(),
            'todo' => $top()->where('status', Todo::STATUS_WAITING)->onDay(today())->count(),
            'latest' => $latest,
            'latestAt' => time(),
        ];
    }

    /** @param  array<string, mixed>  $move  EditorBoard::move() */
    public static function changed(array $move): void
    {
        if (! LiveActivities::ready()) {
            return;
        }
        $latest = trim(($move['person'] ?? 'Someone').' · '.($move['label'] ?? 'Updated').': '.($move['title'] ?? ''));
        $state = self::state(mb_substr($latest, 0, 140));
        $day = today()->toDateString();

        foreach (User::query()->where('role', User::ROLE_ADMIN)->get() as $admin) {
            LiveActivities::updateOrStart($admin, LiveActivityToken::EDITING_BOARD, ['title' => 'Editing board'], $state,
                ['title' => 'Editing board', 'body' => $state['latest']], null,
                "eb:{$admin->id}:{$day}", (int) now()->diffInSeconds(now()->endOfDay()) + 1, todayOnly: true);
        }
    }

    public static function endDay(): int
    {
        $tokens = LiveActivities::updateTokens(LiveActivityToken::EDITING_BOARD);
        LiveActivities::end($tokens, self::state('Board closed for the day'));

        return $tokens->count();
    }
}
