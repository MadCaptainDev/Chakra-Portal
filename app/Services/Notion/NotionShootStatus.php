<?php

namespace App\Services\Notion;

use App\Models\NotionSetting;
use App\Models\Shoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushing a shoot's status back to Notion -- the one thing this integration
 * writes.
 *
 * Everything else under App\Services\Notion reads: ContentSyncService pulls
 * databases down and NotionShootImporter mirrors them into portal rows. This
 * goes the other way, because the crew running a shoot are the people who
 * know it started, and making them also open Notion to say so is how a board
 * stops being true.
 *
 * Note for anyone who read Shoot's own doc block and expected this to be
 * impossible: that comment predates this class and describes the integration
 * as it was used, not the token's capability. The token does hold update
 * access -- verified against /v1/pages -- so a page the integration can see
 * is a page it can move.
 *
 * "Status" is a `select` property in the studio's Shoots database, not
 * Notion's newer `status` type. The payload shape differs between the two and
 * sending the wrong one is a 400, so this is not a detail to guess at; see
 * SELECT_PROPERTY.
 */
class NotionShootStatus
{
    private const API_BASE = 'https://api.notion.com/v1';

    private const NOTION_VERSION = '2022-06-28';

    /** The Notion property being written, and its type in that database. */
    private const SELECT_PROPERTY = 'Status';

    /** Set when a crew member starts the shoot. */
    public const SHOOTING = 'Shooting';

    /**
     * Set when a crew member finishes it.
     *
     * "Editing" rather than "Completed" on purpose: the shoot is over but the
     * work is not, and the studio's own pipeline (Planned, Shooting, Editing,
     * Review, Completed) treats Completed as the end of post, not the end of
     * the day. NotionShoot::STATUS_MAP already folds Editing back to the
     * portal's `completed`, so the two stay in step either way -- change this
     * one constant if the board should jump straight to Completed.
     */
    public const AFTER_WRAP = 'Editing';

    /**
     * Move the shoot's Notion card, if it has one.
     *
     * Never throws. A shoot that started is a fact the portal already
     * recorded; Notion being unreachable, the page having been deleted, or
     * the property having been renamed are all reasons to leave a log line,
     * not to fail the crew member's tap and leave them unable to work.
     *
     * @return bool whether Notion actually accepted the change
     */
    public static function push(Shoot $shoot, string $status): bool
    {
        $pageId = $shoot->notionShoot?->notion_page_id;

        if ($pageId === null) {
            // Booked in the portal, so Notion has never heard of it. Not a
            // failure -- there is simply nothing on the other side to move.
            return false;
        }

        $settings = NotionSetting::query()->latest('id')->first();

        if (! $settings?->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken($settings->api_key)
                ->withHeaders(['Notion-Version' => self::NOTION_VERSION])
                ->timeout(10)
                ->patch(self::API_BASE.'/pages/'.$pageId, [
                    'properties' => [
                        self::SELECT_PROPERTY => ['select' => ['name' => $status]],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('Notion refused a shoot status change.', [
                    'shoot_id' => $shoot->id,
                    'status' => $status,
                    'response' => $response->json('message') ?? $response->body(),
                ]);

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('Notion was unreachable for a shoot status change.', [
                'shoot_id' => $shoot->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        /*
         * Keep the local mirror honest too. Without this the next content
         * sync is the only thing that would correct it, and until then the
         * Shoots screen would still show the status the card had this
         * morning.
         */
        $shoot->notionShoot?->forceFill(['status' => $status])->save();

        return true;
    }
}
