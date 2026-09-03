<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\NotionShoot;
use App\Models\Shoot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "How much unposted content does this client have left, and is a shoot
 * booked before it runs out?" -- the question that started this feature: a
 * client's last dated video was days away with nothing on the calendar
 * behind it, and nobody was told.
 *
 * Reuses ContentDashboard's STATUS_GROUPS for what counts as "not yet
 * published" and DashboardController::contentPulse()'s 5-day edit-buffer
 * convention for how far ahead of depletion a shoot needs to be booked --
 * two numbers this class does not reinvent.
 */
class ContentForecast
{
    public const STATUS_CRITICAL = 'critical';

    public const STATUS_WARNING = 'warning';

    public const STATUS_OK = 'ok';

    public const STATUS_UNKNOWN = 'unknown';

    /** Same convention as DashboardController::contentPulse()'s $editBufferDays. */
    public const EDIT_BUFFER_DAYS = 5;

    /** Depletion within this many days, with nothing booked, is critical. */
    public const CRITICAL_WINDOW_DAYS = 7;

    /** Depletion within this many days, with nothing booked, is a warning. */
    public const WARNING_WINDOW_DAYS = 21;

    private const WEEKS_PER_MONTH = 30.44 / 7;

    /**
     * One client's forecast.
     *
     * @return array{
     *     client: Client,
     *     remaining: int,
     *     reliable: int,
     *     fuzzy: int,
     *     weekly_cadence: float|null,
     *     depletion_date: Carbon|null,
     *     next_shoot_date: Carbon|null,
     *     book_by_date: Carbon|null,
     *     status: string,
     * }
     */
    public static function forClient(Client $client): array
    {
        [$remaining, $reliable, $fuzzy] = self::remainingFor($client);
        $weeklyCadence = self::weeklyCadenceFor($client);
        $nextShootDate = self::nextShootDateFor($client);

        $depletionDate = match (true) {
            $weeklyCadence === null || $weeklyCadence <= 0 => null,
            $remaining <= 0 => now()->startOfDay(),
            default => now()->startOfDay()->addDays((int) round($remaining / $weeklyCadence * 7)),
        };

        $bookByDate = $depletionDate?->copy()->subDays(self::EDIT_BUFFER_DAYS);

        return [
            'client' => $client,
            'remaining' => $remaining,
            'reliable' => $reliable,
            'fuzzy' => $fuzzy,
            'weekly_cadence' => $weeklyCadence,
            'depletion_date' => $depletionDate,
            'next_shoot_date' => $nextShootDate,
            'book_by_date' => $bookByDate,
            'status' => self::statusFor($depletionDate, $nextShootDate),
        ];
    }

    /**
     * Every client with at least one content account, sorted soonest-critical
     * first -- what both the alert command and the Forecast page want.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forAllClients(): Collection
    {
        return Client::query()
            ->whereHas('contentAccounts')
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client) => self::forClient($client))
            ->sortBy(fn (array $row) => $row['depletion_date']?->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Not-yet-published items attributed to this client, split into
     * `reliable` (confirmed via the Notion Shoot<->Reel relation reaching a
     * shoot mapped to this exact client) and `fuzzy` (everything else --
     * venture-matched only, the studio's existing, admittedly imperfect
     * convention; see Client::contentItems()'s own doc block).
     *
     * @return array{0: int, 1: int, 2: int} [remaining, reliable, fuzzy]
     */
    private static function remainingFor(Client $client): array
    {
        $statuses = array_merge(
            ContentDashboard::STATUS_GROUPS['scheduled'],
            ContentDashboard::STATUS_GROUPS['in_progress'],
        );

        $items = $client->contentItems()
            ->whereIn('status', $statuses)
            ->select(['id', 'notion_shoot_id'])
            ->get();

        if ($items->isEmpty()) {
            return [0, 0, 0];
        }

        $shootIds = $items->pluck('notion_shoot_id')->filter()->unique()->values();

        $reliableShootIds = $shootIds->isEmpty() ? collect() : NotionShoot::query()
            ->whereIn('id', $shootIds)
            ->where('client_id', $client->id)
            ->pluck('id');

        $reliable = $items->filter(fn (ContentItem $i) => $i->notion_shoot_id !== null && $reliableShootIds->contains($i->notion_shoot_id))->count();

        return [$items->count(), $reliable, $items->count() - $reliable];
    }

    /**
     * Sum of this client's own published-per-week rate, from
     * ContentAccount's monthly targets -- the cadence the user confirmed
     * using rather than a new "posts per week" field. Null when the client
     * has content accounts but none carries any target at all: there is
     * nothing to divide by, and 0 would read as "already depleted" instead
     * of "unknown".
     */
    private static function weeklyCadenceFor(Client $client): ?float
    {
        $monthly = $client->contentAccounts()
            ->get()
            ->sum(fn ($account) => $account->totalTarget() ?? 0);

        return $monthly > 0 ? $monthly / self::WEEKS_PER_MONTH : null;
    }

    /**
     * The earliest upcoming, non-cancelled shoot booked for this client --
     * the portal's own Shoot record, same query already used on the client
     * dashboard (Client\DashboardController::index()'s 'nextShoot').
     * NotionShoot rows import into Shoot on every sync, so this is
     * effectively "what Notion currently says" without a second source of
     * truth to reconcile.
     */
    private static function nextShootDateFor(Client $client): ?Carbon
    {
        $shoot = Shoot::where('client_id', $client->id)->upcoming()->ordered()->first();

        return $shoot?->starts_at?->copy();
    }

    /**
     * critical: depleting soon with nothing booked before it.
     * warning: depleting further out, still nothing booked before it.
     * ok: either not depleting soon, or a shoot is already booked in time.
     * unknown: cadence can't be computed (no targets set).
     */
    private static function statusFor(?Carbon $depletionDate, ?Carbon $nextShootDate): string
    {
        if ($depletionDate === null) {
            return self::STATUS_UNKNOWN;
        }

        $covered = $nextShootDate !== null && $nextShootDate->lte($depletionDate);

        if ($covered) {
            return self::STATUS_OK;
        }

        // Absolute, not signed: depletionDate is never computed as earlier
        // than today (see forClient()), so there is no "already past" case
        // to distinguish here.
        $daysToDepletion = now()->startOfDay()->diffInDays($depletionDate);

        return match (true) {
            $daysToDepletion <= self::CRITICAL_WINDOW_DAYS => self::STATUS_CRITICAL,
            $daysToDepletion <= self::WARNING_WINDOW_DAYS => self::STATUS_WARNING,
            default => self::STATUS_OK,
        };
    }
}
