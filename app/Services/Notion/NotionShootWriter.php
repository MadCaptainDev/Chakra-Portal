<?php

namespace App\Services\Notion;

use App\Models\NotionShoot;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps Notion's Shoots database in step with shoots booked or changed in
 * the portal, so there is one shoot in both places and the import never
 * makes a second copy:
 *
 *   created in Chakra   a page is made in Notion and linked straight away
 *   changed in Chakra   name, date and time, location, client, status and
 *                       team are written to its page
 *
 * Changes that came FROM Notion (the import) are not written back -- see
 * mute(). Never throws: Notion being down must not stop a booking; the
 * shoot is linked on the next save, or by shoots:link-notion.
 */
class NotionShootWriter
{
    private static bool $muted = false;

    /** Run $work without writing to Notion (the import, merges). */
    public static function mute(callable $work): mixed
    {
        $was = self::$muted;
        self::$muted = true;

        try {
            return $work();
        } finally {
            self::$muted = $was;
        }
    }

    public static function muted(): bool
    {
        return self::$muted;
    }

    public static function databaseId(): ?string
    {
        return config('notion.databases.shoot.ids.0');
    }

    /** Make the Notion page for a shoot booked in the portal, and link it. */
    public static function create(Shoot $shoot): ?NotionShoot
    {
        if (self::$muted || $shoot->notion_shoot_id || ! self::databaseId() || preg_match('/^\s*test\b/i', (string) $shoot->title)) {
            return null;
        }

        $notion = app(ContentSyncService::class);
        if (! $notion->isConfigured()) {
            return null;
        }

        try {
            $page = $notion->createPage(self::databaseId(), self::properties($shoot, true));
            $row = $notion->applyShootPage($page);
            $row->forceFill(['client_id' => $shoot->client_id])->save();

            self::mute(fn () => $shoot->forceFill(['notion_shoot_id' => $row->id])->saveQuietly());

            return $row;
        } catch (Throwable $e) {
            Log::warning('Could not create the shoot in Notion.', ['shoot_id' => $shoot->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Write a portal change to the shoot's Notion page. */
    public static function update(Shoot $shoot): bool
    {
        if (self::$muted) {
            return false;
        }
        if (! $shoot->notion_shoot_id) {
            return (bool) self::create($shoot);
        }

        $notion = app(ContentSyncService::class);
        $page = $shoot->notionShoot?->notion_page_id;
        if (! $page || ! $notion->isConfigured()) {
            return false;
        }

        try {
            $fresh = $notion->updatePage($page, self::properties($shoot, false));
            $notion->applyShootPage($fresh);

            return true;
        } catch (Throwable $e) {
            Log::warning('Could not update the shoot in Notion.', ['shoot_id' => $shoot->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The page properties, in the Shoots database's own shapes (all selects
     * and multi-selects, not Notion's status type).
     *
     * @return array<string, mixed>
     */
    public static function properties(Shoot $shoot, bool $creating): array
    {
        $shoot->loadMissing(['client', 'crew.user']);
        $start = $shoot->starts_at;
        $timed = $shoot->hasStartTime();

        $props = [
            'Shoot Name' => ['title' => [['type' => 'text', 'text' => ['content' => Str::limit(trim($shoot->title) ?: 'Shoot', 1900, '')]]]],
            'Date' => ['date' => $start ? array_filter([
                'start' => $timed ? $start->toIso8601String() : $start->toDateString(),
                'end' => $timed && $shoot->ends_at && $shoot->ends_at->gt($start) ? $shoot->ends_at->toIso8601String() : null,
            ]) : null],
            'Location' => ['rich_text' => filled($shoot->location) ? [['type' => 'text', 'text' => ['content' => Str::limit($shoot->location, 1900, '')]]] : []],
        ];

        if ($client = self::clientOption($shoot)) {
            $props['Client'] = ['select' => ['name' => $client]];
        }

        if ($status = self::statusFor($shoot, $creating)) {
            $props['Status'] = ['select' => ['name' => $status]];
        }

        if ($shoot->status === Shoot::STATUS_CONFIRMED) {
            $props['Client Conformation '] = ['select' => ['name' => 'Yes']];
        }

        $team = $shoot->crew->map(fn ($c) => self::teamName($c->user))->filter()->unique()->values();
        if ($team->isNotEmpty() || ! $creating) {
            $props['Team'] = ['multi_select' => $team->map(fn ($n) => ['name' => $n])->all()];
        }

        return $props;
    }

    /**
     * The Notion "Client" option for the portal client: the name Notion
     * already uses for them on earlier shoots, else the client's name.
     */
    public static function clientOption(Shoot $shoot): ?string
    {
        if (! $shoot->client_id) {
            return null;
        }

        $used = NotionShoot::query()->where('client_id', $shoot->client_id)->whereNotNull('client')
            ->selectRaw('client, COUNT(*) n')->groupBy('client')->orderByDesc('n')->value('client');

        return Str::limit(str_replace(',', ' ', $used ?: (string) $shoot->client?->name), 95, '') ?: null;
    }

    /**
     * Portal status to the Notion option. Wrapping is left to the shoot day
     * (NotionShootStatus pushes Shooting and Editing as the crew go), so an
     * edit does not drag a card the crew already moved.
     */
    private static function statusFor(Shoot $shoot, bool $creating): ?string
    {
        return match ($shoot->status) {
            Shoot::STATUS_CANCELLED => 'Cancelled',
            Shoot::STATUS_PLANNED => $creating ? 'Planned' : null,
            Shoot::STATUS_CONFIRMED => $creating ? 'Planned' : null,
            Shoot::STATUS_COMPLETED => $creating ? 'Completed' : null,
            default => null,
        };
    }

    /** Notion's Team uses first names ("Aron", "Sanjai"). */
    private static function teamName(?User $user): ?string
    {
        return $user ? Str::limit(str_replace(',', ' ', Str::before(trim($user->name), ' ')), 95, '') : null;
    }
}
