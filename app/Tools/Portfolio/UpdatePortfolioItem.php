<?php

namespace App\Tools\Portfolio;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;

class UpdatePortfolioItem extends Tool
{
    public function name(): string
    {
        return 'update_portfolio_item';
    }

    public function title(): string
    {
        return 'Edit a portfolio piece / write its case study';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function description(): string
    {
        return 'Changes one PUBLIC portfolio piece by id (from list_portfolio): title, client, category, video link, posting date, '
            .'description, the case-study summary and creative notes (hook, concept, storytelling, call to action, offer, '
            .'audience), and whether it is shown or featured. Only '
            .'the fields you pass change. Write case studies from the real caption and numbers -- never invent results, '
            .'sales or leads. USE WHEN asked "write the case study for #41", "hide that reel", "feature the Janet one".';
    }

    public function permission(): ?string
    {
        return 'portfolio.edit';
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
        $text = fn (string $d) => ['type' => 'string', 'description' => $d];

        return $this->object([
            'id' => ['type' => 'integer', 'description' => 'The piece, from list_portfolio.'],
            'title' => $text('Short public title.'),
            'summary' => $text('The case study in 2–4 sentences: what we made and how it performed.'),
            'creative_hook' => $text('The opening that stops the scroll.'),
            'creative_concept' => $text('The idea behind it.'),
            'creative_storytelling' => $text('How it unfolds.'),
            'creative_cta' => $text('What it asks the viewer to do.'),
            'creative_offer' => $text('Any offer it carries.'),
            'creative_audience' => $text('Who it is for.'),
            'client' => $text('Move it to this client, by name.'),
            'category' => $text('The portfolio category, by name (as on the website tabs).'),
            'video_url' => $text('The video link (for a piece not linked to an Instagram post).'),
            'published_on' => ['type' => 'string', 'format' => 'date', 'description' => 'When it went out, YYYY-MM-DD.'],
            'description' => $text('A longer description.'),
            'shown' => ['type' => 'boolean', 'description' => 'Show it on the website.'],
            'featured' => ['type' => 'boolean', 'description' => 'Pin it to the front.'],
        ], ['id']);
    }

    public function handle(array $arguments, User $user): array
    {
        $item = PortfolioItem::find($arguments['id'] ?? 0) ?? throw new ToolException('No portfolio piece with that id. Use list_portfolio.');

        $fields = array_intersect_key($arguments, array_flip(['title', 'summary', 'description', ...array_keys(PortfolioItem::CREATIVE_FIELDS)]));
        foreach ($fields as $k => $v) {
            $fields[$k] = is_string($v) ? trim($v) : $v;
            if ($k === 'title' && $fields[$k] === '') {
                throw new ToolException('A title cannot be empty.');
            }
        }
        if (array_key_exists('shown', $arguments)) {
            $fields['is_visible'] = (bool) $arguments['shown'];
        }
        if (array_key_exists('featured', $arguments)) {
            $fields['is_featured'] = (bool) $arguments['featured'];
        }
        if (filled($arguments['client'] ?? null)) {
            $fields['client_id'] = ClientResolver::resolve($arguments['client'])->id;
        }
        if (filled($arguments['category'] ?? null)) {
            $fields['portfolio_category_id'] = PortfolioCategory::query()->where('name', trim($arguments['category']))->orWhere('slug', trim($arguments['category']))->value('id')
                ?? throw new ToolException('No portfolio category called that. Categories: '.PortfolioCategory::ordered()->pluck('name')->implode(', '));
        }
        if (array_key_exists('video_url', $arguments)) {
            if ($item->social_media_item_id) {
                throw new ToolException('This piece is linked to its Instagram post; its link follows the post.');
            }
            if (! preg_match('#^https?://#i', (string) $arguments['video_url'])) {
                throw new ToolException('video_url must be a full https:// link.');
            }
            $fields['video_url'] = trim($arguments['video_url']);
        }
        if (filled($arguments['published_on'] ?? null)) {
            $fields['published_on'] = rescue(fn () => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $arguments['published_on'])->toDateString(), null, false)
                ?? throw new ToolException('published_on must be YYYY-MM-DD.');
        }
        if ($fields === []) {
            throw new ToolException('Pass at least one field to change.');
        }

        $item->update($fields);

        return ['id' => $item->id, 'changed' => array_keys($fields), 'page' => $item->fresh()->hasCaseStudy() ? route('portfolio.detail', $item) : null];
    }
}
