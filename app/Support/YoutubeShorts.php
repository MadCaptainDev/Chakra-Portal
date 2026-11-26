<?php

namespace App\Support;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The YouTube Short duty. Every reel posted on Instagram goes up on YouTube
 * too, as the same video without the copyrighted music. It is not counted as
 * content anywhere -- it is a job, and the editing card says who did it and
 * when (todos.youtube_posted_at / youtube_posted_by_id).
 *
 * "Missing": posted on Instagram (not just delivered to the client) more than
 * GRACE_HOURS ago, and not marked posted on YouTube.
 */
class YoutubeShorts
{
    public const GRACE_HOURS = 24;

    /** How far back the list looks: older ones are history, not a to-do. */
    public const LOOKBACK_DAYS = 30;

    /** Posted reels whose Short is still to go up (oldest first), optionally one person's. */
    public static function due(?User $for = null, bool $includeGrace = true): Collection
    {
        return Todo::query()->whereNull('parent_id')
            ->whereNotNull('posted_at')->whereNull('youtube_posted_at')
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Todo::KIND_DELIVERED))
            ->where(fn ($q) => $q->whereNull('kind')->orWhereIn('kind', ['reel']))
            ->where('posted_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->when(! $includeGrace, fn ($q) => $q->where('posted_at', '<=', now()->subHours(self::GRACE_HOURS)))
            ->when($for, fn ($q) => $q->where('user_id', $for->id))
            ->with('user:id,name')->orderBy('posted_at')->get();
    }

    /** Missing past the grace period, per editor: name => list. */
    public static function missingByEditor(): Collection
    {
        return self::due(null, false)->groupBy(fn (Todo $t) => $t->user?->name ?? 'No editor')->sortByDesc(fn ($g) => $g->count());
    }

    /** The admins' daily summary text, or null when nothing is missing. */
    public static function summary(): ?array
    {
        $by = self::missingByEditor();
        if ($by->isEmpty()) {
            return null;
        }
        $total = $by->sum(fn ($g) => $g->count());
        $head = $by->map(fn ($g, $name) => $name.' '.$g->count())->implode(', ');
        $lines = $by->flatMap(fn ($g, $name) => $g->take(6)->map(fn (Todo $t) => '• '.$name.': '.$t->title.($t->venture ? ' ('.$t->venture.')' : '').' — on Instagram '.$t->posted_at->diffForHumans(null, true).' ago'));

        return [
            'title' => '▶ '.$total.' YouTube '.($total === 1 ? 'Short' : 'Shorts').' not posted',
            'body' => $head."\n".$lines->implode("\n"),
        ];
    }

    /**
     * Per person over a period, for the Work Report: on time (within the
     * grace period), late, and still missing.
     *
     * @return Collection<int, array{on_time: int, late: int, missing: int}>
     */
    public static function record(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): Collection
    {
        return Todo::query()->whereNull('parent_id')->whereNotNull('posted_at')
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', 'reel'))
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['id', 'user_id', 'posted_at', 'youtube_posted_at'])
            ->groupBy('user_id')
            ->map(fn ($g) => [
                'on_time' => $g->filter(fn ($t) => $t->youtube_posted_at && $t->youtube_posted_at->lte($t->posted_at->copy()->addHours(self::GRACE_HOURS)))->count(),
                'late' => $g->filter(fn ($t) => $t->youtube_posted_at && $t->youtube_posted_at->gt($t->posted_at->copy()->addHours(self::GRACE_HOURS)))->count(),
                'missing' => $g->filter(fn ($t) => ! $t->youtube_posted_at && $t->posted_at->lte(now()->subHours(self::GRACE_HOURS)))->count(),
            ]);
    }
}
