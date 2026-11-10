<?php

namespace App\Support;

use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\VentureWatch;
use App\Services\Notion\NotionSyncRunner;
use App\Services\Planner\PlannerSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "How many videos do we have?" for the ventures a person picked: every
 * stage of the Notion planners, counted per venture, with what is shot and
 * waiting (in hand) and how far ahead it is planned.
 *
 * Read from content_items, which the Notion sync, the webhook and the
 * planner keep current -- nothing is stored for this.
 */
class VentureBoard
{
    /**
     * The stages, in pipeline order: key => [label, Notion statuses, colour].
     * A status Notion adds later that is not named here lands in "other".
     */
    public const STAGES = [
        'idea' => ['Idea', ['Idea'], '#c19a6b'],
        'to_shoot' => ['To shoot', ['To Be Shooted'], '#facc15'],
        'to_edit' => ['To edit', ['To Be Edited'], '#fb923c'],
        'editing' => ['Editing', ['Edit in Progress', 'Under Review', 'Under review'], '#60a5fa'],
        'ready' => ['Ready', ['Video Ready'], '#f472b6'],
        'scheduled' => ['Scheduled', ['Scheduled'], '#c084fc'],
    ];

    /** Shot and not out yet: what the studio actually has in hand. */
    public const IN_HAND = ['to_edit', 'editing', 'ready', 'scheduled'];

    /** Finished statuses, counted for the month only. */
    public const DONE = ['Published', 'Delivered'];

    /** Never counted. */
    public const IGNORED = ['Canceled', 'Cancelled', 'Hold'];

    /** How many ventures a person sees before choosing any. */
    public const DEFAULT_COUNT = 6;

    /** The planner rows this board reads: the four planners, still in Notion. */
    public static function items()
    {
        return ContentItem::query()->visible()->whereIn('source', PlannerSchema::sources());
    }

    /**
     * Every venture there is, busiest first, for the picker.
     *
     * @return Collection<int, array{name: string, active: int, total: int, logo_url: ?string}>
     */
    public static function all(): Collection
    {
        $live = array_merge(...array_column(self::STAGES, 1));

        return self::items()->whereNotNull('venture')->where('venture', '!=', '')
            ->selectRaw('venture, COUNT(*) AS total, SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count($live), '?')).') THEN 1 ELSE 0 END) AS active', $live)
            ->groupBy('venture')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->venture,
                'active' => (int) $row->active,
                'total' => (int) $row->total,
                'logo_url' => PlannerVisuals::client($row->venture)['logo_url'] ?? null,
            ])
            ->sortBy([['active', 'desc'], ['total', 'desc'], ['name', 'asc']])
            ->values();
    }

    /** @return list<string> the ventures this person watches, in their order */
    public static function chosen(User $user): array
    {
        return VentureWatch::query()->where('user_id', $user->id)->orderBy('sort_order')->orderBy('id')->pluck('venture')->all();
    }

    /**
     * What the dashboard shows: the person's ventures, or the busiest few
     * until they choose.
     *
     * @return array<string, mixed>
     */
    public static function forUser(User $user, ?Carbon $month = null): array
    {
        $chosen = self::chosen($user);
        $ventures = $chosen ?: self::all()->where('active', '>', 0)->take(self::DEFAULT_COUNT)->pluck('name')->all();

        $low = \App\Models\VentureAlertSetting::query()->where('user_id', $user->id)->value('low_stock') ?? 4;

        return self::build($ventures, $month ?? now(), (int) $low) + ['chosen' => $chosen !== [], 'low_stock' => (int) $low];
    }

    /**
     * @param  list<string>  $ventures
     * @return array<string, mixed>
     */
    public static function build(array $ventures, Carbon $month, int $low = 4): array
    {
        $rows = collect($ventures)->mapWithKeys(fn (string $v) => [$v => self::blank($v)])->all();

        if ($ventures !== []) {
            $from = $month->copy()->startOfMonth();
            $to = $month->copy()->endOfMonth();

            $items = self::items()->whereIn('venture', $ventures)
                ->whereNotIn('status', self::IGNORED)
                ->get(['id', 'venture', 'status', 'source', 'published_date']);

            // MySQL matched whereIn() ignoring case and trailing spaces
            // ("Janet " is "Janet"), so file each item under the name asked for.
            $asked = collect($ventures)->mapWithKeys(fn (string $v) => [self::fold($v) => $v]);

            foreach ($items as $item) {
                $key = $asked[self::fold((string) $item->venture)] ?? null;
                if ($key === null) {
                    continue;
                }
                $row = &$rows[$key];
                $stage = self::stageOf($item->status);

                if (in_array($item->status, self::DONE, true)) {
                    if ($item->published_date && $item->published_date->between($from, $to)) {
                        $row['out_month']++;
                        $row['out_by_type'][$item->source] = ($row['out_by_type'][$item->source] ?? 0) + 1;
                    }
                    continue;
                }

                $row['stages'][$stage]++;
                if (in_array($stage, self::IN_HAND, true)) {
                    $row['in_hand']++;
                    if ($item->published_date && $item->published_date->gte(today())) {
                        $row['planned_until'] = max($row['planned_until'] ?? '', $item->published_date->toDateString());
                    }
                }
                if ($item->published_date && $item->published_date->lt(today())) {
                    $row['overdue']++;
                }
                unset($row);
            }

            self::targets($rows);
        }

        $rows = array_values(array_map(fn (array $r) => $r + ['health' => self::health($r, $low)], $rows));
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'rows' => $rows,
            'totals' => [
                'stages' => collect(array_keys(self::STAGES) + [99 => 'other'])
                    ->mapWithKeys(fn ($k) => [$k => array_sum(array_map(fn ($r) => $r['stages'][$k], $rows))])->all(),
                'in_hand' => $sum('in_hand'),
                'out_month' => $sum('out_month'),
                'overdue' => $sum('overdue'),
            ],
            'stages' => collect(self::STAGES)->map(fn ($s, $k) => ['key' => $k, 'label' => $s[0], 'color' => $s[2]])->values()->all(),
            'month' => $month->format('F Y'),
            'last_synced_at' => NotionSyncRunner::lastSyncedAt()?->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** How MySQL compares venture names: case and surrounding spaces do not count. */
    public static function fold(string $venture): string
    {
        return mb_strtolower(trim($venture));
    }

    public static function stageOf(?string $status): string
    {
        foreach (self::STAGES as $key => [, $statuses]) {
            if (in_array($status, $statuses, true)) {
                return $key;
            }
        }

        return 'other';
    }

    /** @return array<string, mixed> */
    private static function blank(string $venture): array
    {
        return [
            'venture' => $venture,
            'logo_url' => PlannerVisuals::client($venture)['logo_url'] ?? null,
            'stages' => array_fill_keys([...array_keys(self::STAGES), 'other'], 0),
            'in_hand' => 0,
            'out_month' => 0,
            'out_by_type' => [],
            'overdue' => 0,
            'planned_until' => null,
            'target' => null,
        ];
    }

    /**
     * The reel target of the content account the venture is mapped to --
     * shown only where the account is this one venture, since an account's
     * target covers all its ventures together.
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    private static function targets(array &$rows): void
    {
        $maps = ContentAccountVenture::query()->whereIn('venture', array_keys($rows))->with('contentAccount')->get();
        $sizes = DB::table('content_account_ventures')->whereIn('content_account_id', $maps->pluck('content_account_id'))
            ->selectRaw('content_account_id, COUNT(*) n')->groupBy('content_account_id')->pluck('n', 'content_account_id');

        $asked = collect(array_keys($rows))->mapWithKeys(fn (string $v) => [self::fold($v) => $v]);

        foreach ($maps as $map) {
            $key = $asked[self::fold((string) $map->venture)] ?? null;
            if ($key !== null && ($sizes[$map->content_account_id] ?? 0) === 1 && $map->contentAccount?->target_reel) {
                $rows[$key]['target'] = (int) $map->contentAccount->target_reel;
            }
        }
    }

    /** low = little in hand; watch = some, below the person's comfort; ok. */
    public static function health(array $row, int $low = 4): string
    {
        return match (true) {
            $row['in_hand'] === 0 && $row['stages']['to_shoot'] === 0 => 'empty',
            $row['in_hand'] < $low => 'low',
            default => 'ok',
        };
    }
}
