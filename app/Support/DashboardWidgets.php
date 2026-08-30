<?php

namespace App\Support;

use App\Models\ContentAccount;
use App\Models\DashboardContentWidget;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared dashboard widgets: the content cards each person pins, and
 * upcoming shoots.
 */
class DashboardWidgets
{
    /**
     * How many accounts a person sees before pinning anything.
     *
     * A first visit showing an empty "pick some accounts" box would be a
     * screen that does nothing until configured, so the default is a useful
     * few; the picker is there to narrow or widen it.
     */
    public const DEFAULT_CARD_COUNT = 3;

    /**
     * @return Collection<int, ContentAccount>
     */
    public static function contentAccounts(): Collection
    {
        return ContentAccount::query()
            ->with('client')
            ->get()
            ->sortBy(fn (ContentAccount $account) => [
                $account->client?->name ?? '',
                $account->name,
            ])
            ->values();
    }

    /**
     * The accounts this person has pinned, in their chosen order -- or the
     * first few as a starting point when they have pinned nothing yet.
     *
     * @return Collection<int, ContentAccount>
     */
    public static function pinnedAccountsFor(User $user): Collection
    {
        $pinnedIds = DashboardContentWidget::query()
            ->where('user_id', $user->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('content_account_id');

        $accounts = self::contentAccounts();

        if ($pinnedIds->isEmpty()) {
            return $accounts->take(self::DEFAULT_CARD_COUNT)->values();
        }

        // Ordered by the pin, not by name: the point of pinning is deciding
        // what sits at the top. keyBy/filter rather than whereIn so an
        // account deleted since it was pinned simply drops out.
        $byId = $accounts->keyBy('id');

        return $pinnedIds
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->values();
    }

    public static function hasPinned(User $user): bool
    {
        return DashboardContentWidget::query()->where('user_id', $user->id)->exists();
    }

    /**
     * One card per pinned account: published counts split by type, against
     * target and last month, with the month's best post where Instagram
     * knows one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function contentCards(User $user, Carbon $month): Collection
    {
        return ContentDashboard::forAccounts(self::pinnedAccountsFor($user), $month);
    }

    public static function upcomingShootsForUser(User $user, int $limit = 5): Collection
    {
        return Shoot::query()
            ->with(['client'])
            ->upcoming()
            ->ordered()
            ->whereHas('crew', fn ($q) => $q->where('user_id', $user->id))
            ->limit($limit)
            ->get();
    }

    public static function upcomingShootsAll(int $limit = 5): Collection
    {
        return Shoot::query()
            ->with(['client', 'notionShoot'])
            ->upcoming()
            ->ordered()
            ->limit($limit)
            ->get();
    }
}
