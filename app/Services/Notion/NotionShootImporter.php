<?php

namespace App\Services\Notion;

use App\Models\Client;
use App\Models\ContentAccountVenture;
use App\Models\NotionShoot;
use App\Models\Shoot;
use Illuminate\Support\Str;

/**
 * Turns Notion shoots into real portal shoots.
 *
 * The portal's Shoots module is where crew, kit and call sheets live;
 * Notion is where the studio actually plans. Until now those were two
 * disconnected lists -- 82 shoots in Notion and 0 in the portal -- so
 * nothing planned could ever have a van packed for it.
 *
 * One-way, because the integration token is read-only. Notion owns what a
 * shoot IS (title, date, location, status); the portal owns everything it
 * adds around it (crew, kits, scripts), and a re-import never touches those.
 * A shoot created directly in the portal has no Notion counterpart and is
 * marked as such rather than pretending the two are in step.
 */
class NotionShootImporter
{
    /**
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function importAll(): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach (NotionShoot::with('shoot')->get() as $notionShoot) {
            // starts_at is not nullable and a shoot with no date cannot be
            // placed on a calendar, so it stays in Notion until it has one.
            if ($notionShoot->shoot_date === null) {
                $skipped++;

                continue;
            }

            $existing = $notionShoot->shoot;
            $this->import($notionShoot);
            $existing ? $updated++ : $imported++;
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Create or refresh the portal shoot for one Notion shoot.
     *
     * updateOrCreate on notion_shoot_id, which is unique -- pressing import
     * twice refreshes rather than growing a duplicate.
     *
     * Only the fields Notion owns are written. notes, crew, kits and
     * scripts are portal-side and are deliberately absent from the update
     * so an import cannot wipe work somebody did here.
     */
    public function import(NotionShoot $notionShoot): ?Shoot
    {
        if ($notionShoot->shoot_date === null) {
            return null;
        }

        return Shoot::updateOrCreate(
            ['notion_shoot_id' => $notionShoot->id],
            [
                'title' => $notionShoot->title ?: 'Untitled Notion shoot',
                'client_id' => $notionShoot->client_id,
                // Notion carries a date, not a time. startOfDay is honest
                // about that; inventing 09:00 would look like a call time
                // somebody agreed to.
                'starts_at' => $notionShoot->shoot_date->copy()->startOfDay(),
                'location' => $notionShoot->location,
                'status' => $notionShoot->portalStatus(),
            ]
        );
    }

    /**
     * Fill in client_id for Notion shoots whose client name matches a
     * portal client exactly.
     *
     * Exact, folded comparison only -- never fuzzy. On this data fuzzy
     * matching sends "SVA Golds and Diamonds" to SVA Silks on the shared
     * token, and a shoot filed under the wrong client is worse than one
     * filed under none. The rest are left for the mapping screen.
     *
     * Never overwrites a client_id already set: a person's answer outranks
     * anything inferred here.
     *
     * @return int how many were newly mapped
     */
    public function autoMapClients(): int
    {
        $lookup = [];

        foreach (Client::all() as $client) {
            $lookup[$this->fold($client->name)] = $client->id;

            if (filled($client->notion_venture)) {
                $lookup[$this->fold($client->notion_venture)] ??= $client->id;
            }
        }

        // Ventures already assigned to an account carry a client too, and
        // a shoot named the same way as a venture means the same client.
        foreach (ContentAccountVenture::with('contentAccount')->get() as $row) {
            $clientId = $row->contentAccount?->client_id;

            if ($clientId) {
                $lookup[$this->fold($row->venture)] ??= $clientId;
            }
        }

        $mapped = 0;

        foreach (NotionShoot::whereNull('client_id')->whereNotNull('client')->get() as $shoot) {
            $clientId = $lookup[$this->fold($shoot->getAttribute('client'))] ?? null;

            if ($clientId === null) {
                continue;
            }

            $shoot->forceFill(['client_id' => $clientId])->save();
            $mapped++;
        }

        return $mapped;
    }

    private function fold(?string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }
}
