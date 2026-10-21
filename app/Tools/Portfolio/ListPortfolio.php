<?php

namespace App\Tools\Portfolio;

use App\Models\PortfolioItem;
use App\Models\User;
use App\Tools\Tool;

class ListPortfolio extends Tool
{
    public function name(): string
    {
        return 'list_portfolio';
    }

    public function title(): string
    {
        return 'List portfolio';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function description(): string
    {
        return 'The pieces on the public website portfolio (chakragroups.in/portfolio): id, title, client, category, '
            .'live Instagram views and reach, whether it is shown and featured, and whether it has a written case study '
            .'(summary and creative notes). USE WHEN asked "what is in our portfolio", "which pieces have no case study". '
            .'Read-only.';
    }

    public function permission(): ?string
    {
        return 'portfolio.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Only this client (part of the name).'],
            'missing_case_study' => ['type' => 'boolean', 'description' => 'Only pieces with no summary written yet.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $items = PortfolioItem::query()->with(['client', 'category'])->orderByDesc('views')->get()
            ->when($arguments['client'] ?? null, fn ($c, $q) => $c->filter(fn ($i) => str_contains(mb_strtolower((string) ($i->client?->name ?? $i->client_name)), mb_strtolower($q))))
            ->when($arguments['missing_case_study'] ?? false, fn ($c) => $c->filter(fn ($i) => blank($i->summary)));

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (PortfolioItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'client' => $i->client?->name ?? $i->client_name,
                'category' => $i->category?->name,
                'views' => $i->views,
                'reach' => $i->reach,
                'shown' => (bool) $i->is_visible,
                'featured' => (bool) $i->is_featured,
                'has_summary' => filled($i->summary),
                'creative_notes' => array_keys(array_filter($i->only(array_keys(PortfolioItem::CREATIVE_FIELDS)), 'filled')),
                'instagram' => $i->video_url,
                'page' => $i->hasCaseStudy() ? route('portfolio.detail', $i) : null,
            ])->values()->all(),
        ];
    }
}
