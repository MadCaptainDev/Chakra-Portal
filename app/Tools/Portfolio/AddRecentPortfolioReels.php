<?php

namespace App\Tools\Portfolio;

use App\Models\User;
use App\Services\Portfolio\TopReelsImporter;
use App\Tools\Tool;

class AddRecentPortfolioReels extends Tool
{
    public function name(): string
    {
        return 'add_recent_portfolio_reels';
    }

    public function title(): string
    {
        return 'Add recent good reels to the portfolio';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function description(): string
    {
        return 'Adds the recent reels that did well to the PUBLIC portfolio, where they lead the "Latest work" row: posted in '
            .'the last N days, at least min_views views, at most per_client per client, and only ones confirmed posted (the '
            .'Chakra Planner row is Published and its publishing date matches the Instagram posting date). preview=true lists '
            .'them without adding. USE WHEN asked "add this month\'s best reels to the portfolio".';
    }

    public function permission(): ?string
    {
        return 'portfolio.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Posted in the last how many days. Defaults to 30.'],
            'min_views' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Defaults to 20000.'],
            'per_client' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Most recent pieces per client. Defaults to 2.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Most to add in one go. Defaults to 10.'],
            'preview' => ['type' => 'boolean', 'description' => 'List what would be added, add nothing.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $args = [(int) ($arguments['days'] ?? 30), (int) ($arguments['min_views'] ?? 20_000), (int) ($arguments['per_client'] ?? 2)];
        $limit = (int) ($arguments['limit'] ?? 10);
        $importer = app(TopReelsImporter::class);

        if ($arguments['preview'] ?? false) {
            return ['would_add' => $importer->recentCandidates(...$args)->take($limit)->map(fn ($c) => [
                'client' => $c['client']->name,
                'title' => TopReelsImporter::title($c['media'], $c['client']),
                'views' => $c['views'],
                'posted_on' => TopReelsImporter::postedCheck($c['media'])['posted_on'],
                'instagram' => $c['media']->permalink,
            ])->values()->all()];
        }

        $added = $importer->addRecent(...[...$args, $limit]);

        return [
            'added' => array_map(fn ($i) => ['id' => $i->id, 'client' => $i->clientLabel(), 'title' => $i->title, 'views' => $i->views, 'posted_on' => $i->published_on?->toDateString(), 'has_cover' => (bool) $i->thumbnail_path], $added),
            'note' => $added ? 'Live on the website portfolio, at the front of Latest work.' : 'Nothing new: no confirmed-posted reel in that window clears the bar, or they are already in.',
        ];
    }
}
