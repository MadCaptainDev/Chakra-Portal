<?php

namespace App\Support;

use App\Models\ContentAccount;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared dashboard widgets: content pipeline by account and upcoming shoots.
 */
class DashboardWidgets
{
    /** Status buckets shown on admin and staff dashboards. */
    public const PIPELINE_STATUSES = [
        'published' => ['Published'],
        'to_be_edited' => ['To Be Edited'],
        'edit_in_progress' => ['Edit in Progress'],
    ];

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

    public static function resolveContentAccount(?int $id): ?ContentAccount
    {
        $accounts = self::contentAccounts();

        if ($accounts->isEmpty()) {
            return null;
        }

        if ($id !== null) {
            return $accounts->firstWhere('id', $id) ?? $accounts->first();
        }

        return $accounts->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function contentPipeline(?ContentAccount $account, Carbon $month): ?array
    {
        if ($account === null) {
            return null;
        }

        $sections = [];

        foreach (self::PIPELINE_STATUSES as $key => $statuses) {
            $items = ContentDashboard::itemsFor($account, $month, $statuses);

            $sections[$key] = [
                'label' => match ($key) {
                    'published' => 'Published',
                    'to_be_edited' => 'To be edited',
                    'edit_in_progress' => 'Edit in progress',
                    default => ucfirst(str_replace('_', ' ', $key)),
                },
                'count' => $items->count(),
                'items' => $items->take(5),
            ];
        }

        return [
            'account' => $account,
            'sections' => $sections,
        ];
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
