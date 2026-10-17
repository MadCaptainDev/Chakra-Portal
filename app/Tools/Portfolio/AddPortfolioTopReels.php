<?php

namespace App\Tools\Portfolio;

use App\Models\User;
use App\Services\Portfolio\TopReelsImporter;
use App\Tools\ClientResolver;
use App\Tools\Tool;

class AddPortfolioTopReels extends Tool
{
    public function name(): string
    {
        return 'add_portfolio_top_reels';
    }

    public function title(): string
    {
        return "Add a client's top reels to the portfolio";
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function description(): string
    {
        return "Adds a client's most-viewed Instagram reels to the PUBLIC website portfolio: their top N by views that are "
            .'not in it yet, each linked to its Instagram post (numbers refresh on every sync) with its cover saved and shown '
            .'straight away. Needs the client\'s Instagram connected. USE WHEN asked "add SVA\'s best reels to the portfolio". '
            .'Then write each piece\'s case study with update_portfolio_item.';
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
            'client' => ['type' => 'string', 'description' => 'The client, by name.'],
            'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Their top how many reels. Defaults to 2.'],
        ], ['client']);
    }

    public function handle(array $arguments, User $user): array
    {
        $client = ClientResolver::resolve($arguments['client']);
        $added = app(TopReelsImporter::class)->add($client, (int) ($arguments['count'] ?? 2));

        return [
            'client' => $client->name,
            'added' => array_map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'views' => $i->views, 'instagram' => $i->video_url], $added),
            'note' => $added ? 'Live on the website portfolio now.' : 'Nothing added: their top reels are already in, or their Instagram is not connected.',
        ];
    }
}
