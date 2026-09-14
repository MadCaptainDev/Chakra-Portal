<?php

namespace App\Tools;

use App\Models\User;
use App\Services\Notion\NotionSyncRunner;
use App\Support\ContentDashboard;

class ReelPlannerToday extends Tool
{
    public function name(): string
    {
        return 'reel_planner_today';
    }

    public function description(): string
    {
        return 'Today\'s Reel Planner board: how many Reels are due to post today, broken down by '
            .'stage (to be edited, edit in progress, under review, posted), plus the title and '
            .'editor of each one. Reel only -- Post and YouTube are not included. Refreshes from '
            .'Notion before answering, so this is always live, never a stale cache. Admin only. '
            .'Use this for "what\'s due today", "what\'s still with an editor", or "who\'s editing '
            .'what today".';
    }

    /**
     * No registered module for this -- and none is needed. AppServiceProvider's
     * Gate::before(fn ($user) => $user->isAdmin() ? true : null) passes an admin
     * through any ability string regardless of registration, and denies anyone
     * else since this one is never Gate::define()'d. That matches the underlying
     * HTTP route (content-dashboard.reel-today), which is admin-only with no
     * module check either.
     */
    public function permission(): ?string
    {
        return 'content-dashboard.view';
    }

    public function schema(): array
    {
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        NotionSyncRunner::ensureFresh();

        return ContentDashboard::todayReelBoard();
    }
}
