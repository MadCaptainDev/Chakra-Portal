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

    /** Videos listed per venture card. */
    public const LIST_MAX = 14;

    /** Platforms in the order a card shows them. */
    public const PLATFORMS = [
        ContentItem::SOURCE_REEL => 'Reel',
        ContentItem::SOURCE_YOUTUBE => 'YouTube',
        ContentItem::SOURCE_POST => 'Post',
        ContentItem::SOURCE_STORY => 'Story',
    ];

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
        $accountNames = \App\Models\ContentAccount::query()->pluck('name', 'id');

        return self::items()->whereNotNull('venture')->where('venture', '!=', '')
            ->selectRaw('venture, COUNT(*) AS total, SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count($live), '?')).') THEN 1 ELSE 0 END) AS active', $live)
            ->groupBy('venture')
            ->get()
            // One entry per client, not per spelling: each Notion planner spells
            // some clients its own way ("Janet" / "janet Hospital"), and the
            // content account connections say which are one. Named by the
            // spelling with the most rows.
            ->groupBy(fn ($row) => ContentPieces::family($row->venture))
            ->map(function ($rows, string $family) use ($accountNames) {
                $named = str_starts_with($family, 'a:') ? mb_strtolower(trim((string) ($accountNames[(int) substr($family, 2)] ?? ''))) : null;
                $main = ($named ? $rows->first(fn ($r) => mb_strtolower(trim($r->venture)) === $named) : null)?->venture
                    ?? $rows->sortByDesc('total')->first()->venture;

                return [
                    'name' => $main,
                    'active' => (int) $rows->sum('active'),
                    'total' => (int) $rows->sum('total'),
                    'spellings' => $rows->pluck('venture')->values()->all(),
                    'logo_url' => PlannerVisuals::client($main)['logo_url'] ?? null,
                ];
            })
            ->sortBy([['active', 'desc'], ['total', 'desc'], ['name', 'asc']])
            ->values();
    }

    /** @return list<string> the ventures this person watches, in their order */
    public static function chosen(User $user): array
    {
        // Two spellings of one client chosen before they were merged count once.
        return VentureWatch::query()->where('user_id', $user->id)->orderBy('sort_order')->orderBy('id')->pluck('venture')
            ->unique(fn (string $v) => ContentPieces::family($v))->values()->all();
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
        $ventures = collect($ventures)->unique(fn (string $v) => ContentPieces::family($v))->values()->all();
        $rows = collect($ventures)->mapWithKeys(fn (string $v) => [$v => self::blank($v)])->all();

        if ($ventures !== []) {
            $from = $month->copy()->startOfMonth();
            $to = $month->copy()->endOfMonth();

            // The other spellings of the same accounts too ("janet Hospital" is
            // where Janet's YouTube copies are), so a reel meets its YouTube copy.
            $items = self::items()->whereIn('venture', ContentPieces::siblings($ventures))
                ->whereNotIn('status', self::IGNORED)
                ->get(['id', 'title', 'venture', 'status', 'source', 'published_date']);

            // MySQL matched whereIn() ignoring case and trailing spaces
            // ("Janet " is "Janet"), so file each item under the name asked for.
            $asked = collect($ventures)->mapWithKeys(fn (string $v) => [self::fold($v) => $v]);
            $byFamily = collect($ventures)->mapWithKeys(fn (string $v) => [ContentPieces::family($v) => $v]);
            $order = array_flip([...array_keys(self::STAGES), 'other']);

            // One video, however many planners it sits in: the reel and its
            // YouTube copy are counted once (see ContentPieces::videoKey).
            foreach (ContentPieces::videos($items) as $parts) {
                // Filed under the venture asked for: its own spelling first, else
                // the asked venture of the same account.
                $key = $parts->map(fn ($i) => $asked[self::fold((string) $i->venture)] ?? null)->filter()->first()
                    ?? $byFamily[ContentPieces::family($parts->first()->venture)] ?? null;
                if ($key === null) {
                    continue;
                }
                $row = &$rows[$key];
                $done = $parts->filter(fn ($i) => in_array($i->status, self::DONE, true));
                $open = $parts->reject(fn ($i) => in_array($i->status, self::DONE, true));
                $video = self::video($parts);

                if ($done->isNotEmpty()) {
                    // Out (on at least one platform): never "in hand" again.
                    $outOn = $done->max('published_date');
                    if ($outOn && $outOn->between($from, $to)) {
                        $row['out_month']++;
                        foreach ($done->pluck('source')->unique() as $source) {
                            $row['out_by_type'][$source] = ($row['out_by_type'][$source] ?? 0) + 1;
                        }
                        $row['videos'][] = $video;
                    }
                    if ($video['missing_youtube'] && $outOn && $outOn->gte($from)) {
                        $row['missing_youtube']++;
                    }
                    unset($row);

                    continue;
                }

                // Not out anywhere: as far along as its least-advanced copy.
                $stage = $open->map(fn ($i) => self::stageOf($i->status))->sortBy(fn ($s) => $order[$s])->first();
                $row['stages'][$stage]++;
                $date = $open->pluck('published_date')->filter()->min();
                if (in_array($stage, self::IN_HAND, true)) {
                    $row['in_hand']++;
                    if ($date && $date->gte(today())) {
                        $row['planned_until'] = max($row['planned_until'] ?? '', $date->toDateString());
                    }
                }
                if ($date && $date->lt(today())) {
                    $row['overdue']++;
                }
                if ($video['missing_youtube']) {
                    $row['missing_youtube']++;
                }
                if ($stage !== 'idea') {
                    $row['videos'][] = $video;
                }
                unset($row);
            }

            foreach ($rows as &$r) {
                // Ready first, then by date; what went out this month after.
                usort($r['videos'], fn ($a, $b) => [$a['out'], $a['out'] ? -strtotime((string) $a['date']) : -$a['rank'], $a['date'] ?? '9999']
                    <=> [$b['out'], $b['out'] ? -strtotime((string) $b['date']) : -$b['rank'], $b['date'] ?? '9999']);
                $r['videos'] = array_slice($r['videos'], 0, self::LIST_MAX);
            }
            unset($r);

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
                'missing_youtube' => $sum('missing_youtube'),
                'overdue' => $sum('overdue'),
            ],
            'stages' => collect(self::STAGES)->map(fn ($s, $k) => ['key' => $k, 'label' => $s[0], 'color' => $s[2]])->values()->all(),
            'month' => $month->format('F Y'),
            'last_synced_at' => NotionSyncRunner::lastSyncedAt()?->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * One video for the card: where it is, and each platform it goes to
     * with whether it is out there. A video we publish or schedule goes to
     * YouTube too; one only delivered to the client does not.
     *
     * @param  Collection<int, ContentItem>  $parts
     * @return array<string, mixed>
     */
    public static function video(Collection $parts): array
    {
        $done = $parts->filter(fn ($i) => in_array($i->status, self::DONE, true));
        $open = $parts->reject(fn ($i) => in_array($i->status, self::DONE, true));
        $order = array_flip([...array_keys(self::STAGES), 'other']);
        $stage = $done->isNotEmpty() ? 'out' : $open->map(fn ($i) => self::stageOf($i->status))->sortBy(fn ($s) => $order[$s])->first();
        $date = $done->isNotEmpty() ? $done->max('published_date') : $open->pluck('published_date')->filter()->min();
        $sources = $parts->pluck('source')->unique();
        $posted = $parts->contains(fn ($i) => in_array($i->status, ['Published', 'Scheduled'], true));

        return [
            'title' => ContentPieces::displayTitle($parts->first()->title),
            'stage' => $stage,
            'rank' => $order[$stage] ?? 99,
            'out' => $done->isNotEmpty(),
            'date' => $date?->toDateString(),
            'platforms' => collect(self::PLATFORMS)->filter(fn ($l, $src) => $sources->contains($src))
                ->map(function ($label, $src) use ($parts) {
                    $row = $parts->firstWhere('source', $src);

                    return ['source' => $src, 'label' => $label, 'status' => $row->status,
                        'out' => in_array($row->status, self::DONE, true), 'scheduled' => $row->status === 'Scheduled'];
                })->values()->all(),
            // Published or scheduled as a reel, and no YouTube copy at all.
            'missing_youtube' => $posted && $sources->contains(ContentItem::SOURCE_REEL) && ! $sources->contains(ContentItem::SOURCE_YOUTUBE)
                && ! $parts->contains(fn ($i) => $i->status === 'Delivered'),
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
            'missing_youtube' => 0,
            'videos' => [],
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
