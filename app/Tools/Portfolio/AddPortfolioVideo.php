<?php

namespace App\Tools\Portfolio;

use App\Models\ContentItem;
use App\Models\PortfolioItem;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Services\Portfolio\TopReelsImporter;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;

class AddPortfolioVideo extends Tool
{
    public function name(): string
    {
        return 'add_portfolio_video';
    }

    public function title(): string
    {
        return 'Add one video to the portfolio';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function description(): string
    {
        return 'Adds ONE video to the PUBLIC website portfolio. Give an Instagram link, or a Chakra Planner item id, and it is '
            .'linked to its synced post (cover saved, views refresh on every sync) -- but only after checking it was really '
            .'posted: the planner row says Published and its publishing date matches the Instagram posting date. If the '
            .'check fails nothing is added and the reason is returned. For a video that is not an Instagram post (YouTube, '
            .'Drive), give video_url with client and title. USE WHEN asked "add this reel to the portfolio". Then edit it or '
            .'write its case study with update_portfolio_item.';
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
            'instagram_url' => ['type' => 'string', 'description' => 'The Instagram reel/post link.'],
            'planner_item_id' => ['type' => 'integer', 'description' => 'A Chakra Planner item id (its posted Instagram reel is used).'],
            'video_url' => ['type' => 'string', 'description' => 'Any other video link (YouTube, Drive). Needs client and title.'],
            'client' => ['type' => 'string', 'description' => 'The client, by name. Needed with video_url; otherwise taken from the post.'],
            'title' => ['type' => 'string', 'description' => 'Public title. Defaults to the caption\'s first line.'],
            'shown' => ['type' => 'boolean', 'description' => 'Show it on the website straight away. Defaults to true.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $media = $this->media($arguments);

        if (! $media) {
            $url = trim((string) ($arguments['video_url'] ?? ''));
            if (! preg_match('#^https?://#i', $url)) {
                throw new ToolException('Give instagram_url, planner_item_id, or video_url (a full https:// link).');
            }
            if (blank($arguments['client'] ?? null) || blank($arguments['title'] ?? null)) {
                throw new ToolException('A video_url needs client and title.');
            }
            $client = ClientResolver::resolve($arguments['client']);
            $item = PortfolioItem::create([
                'client_id' => $client->id,
                'portfolio_category_id' => PortfolioItem::query()->where('client_id', $client->id)->whereNotNull('portfolio_category_id')->value('portfolio_category_id'),
                'title' => trim($arguments['title']),
                'video_url' => $url,
                'is_visible' => (bool) ($arguments['shown'] ?? true),
                'sort_order' => 0,
            ]);

            return $this->result($item, 'Added. Upload a cover for it on the portfolio screen.');
        }

        if ($existing = PortfolioItem::where('social_media_item_id', $media->id)->first()) {
            return $this->result($existing, 'Already in the portfolio.'.($existing->is_visible ? '' : ' It is hidden -- use update_portfolio_item with shown=true.'));
        }

        $check = TopReelsImporter::postedCheck($media);
        if (! $check['ok']) {
            throw new ToolException('Not added: '.$check['reason']);
        }

        $client = $media->socialAccount?->client
            ?? (filled($arguments['client'] ?? null) ? ClientResolver::resolve($arguments['client']) : null)
            ?? throw new ToolException('That Instagram account is not connected to a client. Pass client.');

        $item = app(TopReelsImporter::class)->addMedia($client, $media);
        $changes = array_filter(['title' => filled($arguments['title'] ?? null) ? trim($arguments['title']) : null]);
        if (array_key_exists('shown', $arguments)) {
            $changes['is_visible'] = (bool) $arguments['shown'];
        }
        if ($changes) {
            $item->update($changes);
        }

        return $this->result($item->fresh(), $check['reason'].($item->thumbnail_path ? '' : ' The cover comes with the next Instagram sync.'));
    }

    /** The synced Instagram post the arguments point at, if any. */
    private function media(array $arguments): ?SocialMediaItem
    {
        if ($id = $arguments['planner_item_id'] ?? null) {
            $row = ContentItem::find($id) ?? throw new ToolException('No Chakra Planner item with that id.');

            return $row->socialMediaItem ?? throw new ToolException(
                "Planner item #{$row->id} is not linked to an Instagram post yet (status: {$row->status}). It links once it is posted and Instagram syncs."
            );
        }

        if ($url = trim((string) ($arguments['instagram_url'] ?? ''))) {
            if (! preg_match('#instagram\.com/(?:[^/?]+/)?(?:reels?|p|tv)/([A-Za-z0-9_-]+)#i', $url, $m)) {
                throw new ToolException('That does not look like an Instagram post link.');
            }

            return SocialMediaItem::query()->where('permalink', 'like', '%/'.$m[1].'/%')->first()
                ?? throw new ToolException('That post is not synced yet. Is the client\'s Instagram connected? Run the Instagram sync, then try again.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function result(PortfolioItem $item, string $note): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'client' => $item->clientLabel(),
            'views' => $item->views,
            'posted_on' => $item->published_on?->toDateString(),
            'shown' => (bool) $item->is_visible,
            'link' => $item->video_url,
            'note' => $note,
        ];
    }
}
