<?php

namespace App\Http\Controllers;

use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionShoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Support\ContentDashboard;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Who has how much on their plate, right now -- not a ranking over time
 * (see EditorOutputController for that; this is the current queue, not a
 * historical comparison). Admin only, and deliberately not module-gated,
 * same convention EditorOutputController documents: a screen that counts
 * one person's load against another's is the owner's call to show, not a
 * permission checkbox.
 */
class WorkloadController extends Controller
{
    /** A piece still "in progress" whose shoot date is this many days past counts overdue. */
    private const OVERDUE_AFTER_DAYS = 7;

    /**
     * Weight per content type for the "load" figure sort order is based
     * on -- a raw item count treats a reel (real production: shoot, edit,
     * review) the same as a story (a phone clip, posted and forgotten),
     * which flatters whoever's queue happens to be story-heavy. Reel and
     * YouTube carry equal weight -- both are a full edit -- Post less,
     * Story least.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        ContentItem::SOURCE_REEL => 3,
        ContentItem::SOURCE_YOUTUBE => 3,
        ContentItem::SOURCE_POST => 2,
        ContentItem::SOURCE_STORY => 1,
    ];

    public function index(): View
    {
        $staff = User::staff()->orderBy('name')->get();

        $activeShoots = ShootCrew::query()
            ->whereHas('shoot', fn ($q) => $q->upcoming())
            ->selectRaw('user_id, count(*) as c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        $items = ContentItem::query()
            ->whereNotNull('assigned_user_id')
            ->select(['assigned_user_id', 'status', 'source', 'venture', 'shoot_date', 'notion_shoot_id'])
            ->with('notionShoot:id,shoot_date,client_id')
            ->get();

        $clientNameByVenture = $this->clientNameByVenture();
        $clientNameByNotionShootId = NotionShoot::query()->whereNotNull('client_id')->with('mappedClient:id,name')
            ->get(['id', 'client_id'])->mapWithKeys(fn (NotionShoot $ns) => [$ns->id => $ns->mappedClient?->name]);

        $inProgressStatuses = array_merge(
            ContentDashboard::STATUS_GROUPS['scheduled'],
            ContentDashboard::STATUS_GROUPS['in_progress'],
        );

        $overdueCutoff = now()->subDays(self::OVERDUE_AFTER_DAYS)->startOfDay();

        $clientNameFor = function (ContentItem $item) use ($clientNameByNotionShootId, $clientNameByVenture): string {
            // Reliable first: the actual linked shoot's client (Phase 0's
            // Notion Shoot<->Reel relation), then the studio's explicit
            // venture->account mapping. Never the fuzzy TimesheetVenture
            // guess this small a screen doesn't need to risk.
            return ($item->notion_shoot_id ? $clientNameByNotionShootId[$item->notion_shoot_id] ?? null : null)
                ?? $clientNameByVenture[$item->venture] ?? null
                ?? 'Unmapped';
        };

        $rows = $staff->map(function (User $user) use (
            $items, $inProgressStatuses, $overdueCutoff, $activeShoots, $clientNameFor
        ) {
            $mine = $items->where('assigned_user_id', $user->id);

            $active = $mine->whereIn('status', $inProgressStatuses);

            $overdue = $active->filter(function (ContentItem $item) use ($overdueCutoff) {
                $date = $item->notionShoot?->shoot_date ?? $item->shoot_date;

                return $date !== null && $date->lt($overdueCutoff);
            });

            $delivered = $mine->where('status', 'Published')->count();

            $weightedLoad = $active->sum(fn (ContentItem $item) => self::WEIGHTS[$item->source] ?? 1);

            $byClient = $active->groupBy($clientNameFor)
                ->map(fn (Collection $group) => $group->count())
                ->sortDesc();

            return [
                'user' => $user,
                'active_shoots' => (int) ($activeShoots[$user->id] ?? 0),
                'active_content' => $active->count(),
                'weighted_load' => $weightedLoad,
                'overdue' => $overdue->count(),
                'delivered' => $delivered,
                'by_client' => $byClient,
            ];
        })->sortByDesc(fn (array $row) => $row['weighted_load'] + $row['active_shoots'])
            ->values();

        return view('workload.index', [
            'rows' => $rows,
            'overdueAfterDays' => self::OVERDUE_AFTER_DAYS,
        ]);
    }

    /**
     * venture string -> client name, via the studio's explicit
     * content_account_ventures mapping -- the same reliable source
     * ContentDashboard uses, not TimesheetVenture's fuzzy guess.
     *
     * @return array<string, string>
     */
    private function clientNameByVenture(): array
    {
        return ContentAccountVenture::query()
            ->with('contentAccount.client:id,name')
            ->get()
            ->filter(fn (ContentAccountVenture $v) => $v->contentAccount?->client)
            ->mapWithKeys(fn (ContentAccountVenture $v) => [$v->venture => $v->contentAccount->client->name])
            ->all();
    }
}
