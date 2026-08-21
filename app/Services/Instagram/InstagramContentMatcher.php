<?php

namespace App\Services\Instagram;

use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ties a planned Notion item to the real Instagram post it became.
 *
 * Notion says what was meant to go out and when. The Instagram cache says
 * what actually did, and how it performed. Neither alone answers "did we
 * deliver, and did it work" -- this joins them.
 *
 * The join is account + calendar day, because that is genuinely all the two
 * sides share: Notion carries no Instagram id, and Instagram carries no
 * Notion id. That means the match is a strong inference, not a fact, so:
 *
 *  - it only ever pairs content whose venture is mapped to an account whose
 *    client has a connected Instagram account (a venture nobody mapped
 *    cannot be attributed to anybody's Instagram);
 *  - each Instagram post is claimed by at most one planned item, so two
 *    reels on one day cannot both point at the same post and double-count
 *    its reach;
 *  - a same-day tie prefers a post whose format agrees with the planner it
 *    came from, so a reel planned in the Reel Planner matches the reel
 *    rather than the carousel that went out the same afternoon;
 *  - a link already stored is never silently re-pointed. Matching is
 *    additive, so a correction made by hand survives the next run.
 *
 * Only three clients have Instagram connected, so most content will never
 * match. That is expected and is not reported as a failure.
 */
class InstagramContentMatcher
{
    /**
     * @return array{considered: int, matched: int}
     */
    public function matchAll(): array
    {
        // venture => client_id, via the account it is mapped to.
        $ventureToClient = ContentAccountVenture::query()
            ->with('contentAccount')
            ->get()
            ->mapWithKeys(fn (ContentAccountVenture $row) => [
                $row->venture => $row->contentAccount?->client_id,
            ])
            ->filter();

        if ($ventureToClient->isEmpty()) {
            return ['considered' => 0, 'matched' => 0];
        }

        // client_id => instagram social_account_id.
        $clientToAccount = SocialAccount::query()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->pluck('id', 'client_id');

        if ($clientToAccount->isEmpty()) {
            return ['considered' => 0, 'matched' => 0];
        }

        // Every Instagram post we hold, bucketed by account and the IST
        // calendar day it went out -- the same day a Notion published_date
        // means. Already-claimed posts are excluded up front so a second
        // run cannot hand one post to a second planned item.
        $claimed = ContentItem::whereNotNull('social_media_item_id')
            ->pluck('social_media_item_id')
            ->all();

        $available = SocialMediaItem::query()
            ->whereIn('social_account_id', $clientToAccount->values())
            ->whereNotNull('posted_at')
            ->when($claimed !== [], fn ($q) => $q->whereNotIn('id', $claimed))
            ->get()
            ->groupBy(fn (SocialMediaItem $m) => $m->social_account_id.'|'.$m->posted_at->toDateString());

        $candidates = ContentItem::query()
            ->whereNull('social_media_item_id')
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereIn('venture', $ventureToClient->keys())
            ->orderBy('published_date')
            ->get();

        $considered = 0;
        $matched = 0;
        $used = [];

        foreach ($candidates as $item) {
            $clientId = $ventureToClient[$item->venture] ?? null;
            $accountId = $clientId ? ($clientToAccount[$clientId] ?? null) : null;

            if ($accountId === null) {
                continue;
            }

            $considered++;

            $key = $accountId.'|'.Carbon::parse($item->published_date)->toDateString();
            $bucket = $available->get($key);

            if (! $bucket) {
                continue;
            }

            $pick = $this->pick($bucket, $item, $used);

            if ($pick === null) {
                continue;
            }

            $item->forceFill(['social_media_item_id' => $pick->id])->save();
            $used[$pick->id] = true;
            $matched++;
        }

        return ['considered' => $considered, 'matched' => $matched];
    }

    /**
     * Choose which of a day's posts a planned item refers to.
     *
     * Format agreement first, then anything still unclaimed -- a reel
     * planned as a reel should not match the day's carousel while the
     * actual reel sits unmatched beside it.
     *
     * @param  Collection<int, SocialMediaItem>  $bucket
     * @param  array<int, true>  $used
     */
    private function pick(Collection $bucket, ContentItem $item, array $used): ?SocialMediaItem
    {
        $free = $bucket->reject(fn (SocialMediaItem $m) => isset($used[$m->id]));

        if ($free->isEmpty()) {
            return null;
        }

        $wantsReel = $item->source === ContentItem::SOURCE_REEL;

        return $free->first(fn (SocialMediaItem $m) => $m->isReel() === $wantsReel)
            ?? $free->first();
    }
}
