<?php

namespace App\Services\Notion;

use App\Models\Client;
use App\Models\ContentAccountVenture;
use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns Notion shoots into real portal shoots.
 *
 * The portal's Shoots module is where crew, kit and call sheets live;
 * Notion is where the studio actually plans. These used to be two
 * disconnected lists shown on two separate screens -- a shoot could exist
 * in Notion with nobody able to pack a van for it, or exist here with no
 * link back to the plan it came from. Now every Notion sync runs this
 * automatically (see NotionShootController::sync()) and the Shoots screen
 * is the only place either kind is shown.
 *
 * One-way, because the integration token is read-only. Notion owns what a
 * shoot IS (title, date, location, status, crew); the portal owns
 * everything it adds around it (kits, scripts, notes), and a re-sync never
 * touches those. A shoot created directly in the portal has no Notion
 * counterpart and is marked as such rather than pretending the two are in
 * step.
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
     * Only the fields Notion owns are written on the Shoot row itself --
     * notes, kits and scripts are portal-side and are deliberately absent
     * from the update so an import cannot wipe work somebody did here.
     * Crew is the one exception: syncCrew() below adds portal crew for
     * Notion's Team names, but only ever adds, never removes.
     */
    public function import(NotionShoot $notionShoot): ?Shoot
    {
        if ($notionShoot->shoot_date === null) {
            return null;
        }

        $shoot = Shoot::updateOrCreate(
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

        $this->syncCrew($notionShoot, $shoot);

        return $shoot;
    }

    /**
     * Add portal crew for the names on Notion's Team property.
     *
     * Notion's Team is first names only, so matching is exact-fold against
     * the full name first, then a first-token match -- but only when
     * exactly one user's first name matches. Two Arons on staff would make
     * a first-name match a guess, and a wrong crew member on a call sheet
     * is worse than a name the sync left for a person to add by hand.
     *
     * Only adds. A crew member somebody removed here on purpose must not
     * reappear on the next sync, so existing rows are never touched and a
     * name nothing matches is simply skipped rather than invented.
     */
    private function syncCrew(NotionShoot $notionShoot, Shoot $shoot): void
    {
        $names = $notionShoot->teamMembers();

        if ($names === []) {
            return;
        }

        $users = User::all();
        $existingUserIds = $shoot->crew()->pluck('user_id')->all();

        foreach ($names as $name) {
            $user = $this->matchUser($name, $users);

            if (! $user || in_array($user->id, $existingUserIds, true)) {
                continue;
            }

            ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $user->id]);
            $existingUserIds[] = $user->id;
        }
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function matchUser(string $name, Collection $users): ?User
    {
        $folded = $this->fold($name);

        $exact = $users->first(fn (User $user) => $this->fold($user->name) === $folded);

        if ($exact) {
            return $exact;
        }

        $firstNameMatches = $users->filter(
            fn (User $user) => $this->fold(Str::before($user->name, ' ')) === $folded
        );

        return $firstNameMatches->count() === 1 ? $firstNameMatches->first() : null;
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
