<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentAccountVenture;
use App\Models\User;

/**
 * Faces and logos for Chakra Planner (the desktop app and its menu-bar
 * panel): a client's logo from a Notion venture name, a person's photo.
 *
 * URLs are plain public ones (public/uploads, resized to 256px by
 * ImageVariant) -- the app loads them in a browser context with no cookie,
 * and these files are already served publicly.
 *
 * A venture resolves to a client the way the content side does
 * (content-account connections, see Client::contentVentures()), then by the
 * client's notion_venture, then by name / public name.
 */
class PlannerVisuals
{
    /** Memoised per request in the container, not a static: tests rebuild it, a static would carry one test's clients into the next. */
    private const MEMO = 'planner-visuals.memo';

    /** @return array{id: int, logo_url: ?string}|null */
    public static function client(?string $venture): ?array
    {
        if (blank($venture)) {
            return null;
        }
        $id = self::map()[self::fold($venture)] ?? null;

        return $id ? self::clientById($id) : null;
    }

    /** @return array{id: int, logo_url: ?string}|null */
    public static function clientById(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : null;
        if (! $memo) {
            app()->instance(self::MEMO, $memo = new \ArrayObject(['map' => null, 'clients' => []]));
        }
        if (! array_key_exists($id, $memo['clients'])) {
            $client = Client::query()->find($id, ['id', 'logo_path']);
            $clients = $memo['clients'];
            $clients[$id] = $client ? ['id' => $client->id, 'logo_url' => self::image($client->logo_path)] : null;
            $memo['clients'] = $clients;
        }

        return $memo['clients'][$id];
    }

    public static function avatar(?User $user): ?string
    {
        return self::image($user?->avatar_path);
    }

    /** @return array{id: int, name: string, avatar_url: ?string}|null */
    public static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'avatar_url' => self::avatar($user)] : null;
    }

    /** A public, 256px copy of an upload; the original if it cannot be resized. */
    private static function image(?string $path): ?string
    {
        if (blank($path) || ! is_file(public_path($path))) {
            return null;
        }

        return rescue(fn () => ImageVariant::url($path, 256, 256), asset($path), false);
    }

    /** @return array<string, int> */
    private static function map(): array
    {
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : null;
        if (! $memo) {
            app()->instance(self::MEMO, $memo = new \ArrayObject(['map' => null, 'clients' => []]));
        }
        if ($memo['map'] !== null) {
            return $memo['map'];
        }
        $map = [];
        // Weakest first, so the stronger sources below overwrite them.
        foreach (Client::query()->get() as $c) {
            foreach ([$c->name, $c->publicName()] as $name) {
                if (filled($name)) {
                    $map[self::fold($name)] = $c->id;
                }
            }
        }
        foreach (Client::query()->whereNotNull('notion_venture')->get(['id', 'notion_venture']) as $c) {
            $map[self::fold($c->notion_venture)] = $c->id;
        }
        foreach (ContentAccountVenture::query()->with('contentAccount:id,client_id')->get() as $row) {
            if ($row->contentAccount?->client_id) {
                $map[self::fold($row->venture)] = $row->contentAccount->client_id;
            }
        }

        $memo['map'] = $map;

        return $map;
    }

    private static function fold(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", $value))));
    }
}
