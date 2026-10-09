<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The Studio Map's city plan: clients grouped into districts by where they
 * really are, and the districts laid out round the studio's own in the
 * direction they really lie.
 *
 * The city itself is drawn, not real (resources/js/studio-city.js) -- but
 * Chennai should still be up and to the right of Manapparai, and every
 * client on Madurai Road should still be neighbours. So:
 *
 *  - clients within CLUSTER_KM of a district's centre join it (greedy, in
 *    id order, so the plan is stable from one refresh to the next);
 *  - a district is named after the town its clients' addresses most often
 *    end in ("Madurai Road , Manapparai" → Manapparai);
 *  - a client with no location joins the district its address names, or the
 *    studio's own when it names none;
 *  - the studio's district sits at slot [0, 0]; every other district takes
 *    the free slot nearest its real compass bearing, ring by ring outward.
 *
 * Slots are cells of the drawn city's district grid; in screen terms
 * [-1, -1] is straight up (north), [1, -1] right (east), [1, 1] down.
 */
final class CityDistricts
{
    public const CLUSTER_KM = 12;

    /** A studio pin further than this from every district gets one of its own. */
    private const STUDIO_REACH_KM = 25;

    /**
     * @param  list<array{id: int, lat: ?float, lng: ?float, address: ?string}>  $clients
     * @param  array{lat: ?float, lng: ?float, set: bool}  $studio
     * @return array{districts: list<array<string, mixed>>, of: array<int, string>, home: string}
     */
    public static function build(array $clients, array $studio): array
    {
        $districts = [];

        // 1. Cluster everyone who has a location.
        $pinned = array_values(array_filter($clients, fn ($c) => $c['lat'] !== null && $c['lng'] !== null));
        usort($pinned, fn ($a, $b) => $a['id'] <=> $b['id']);
        foreach ($pinned as $client) {
            $joined = false;
            foreach ($districts as &$d) {
                if (self::km($d['lat'], $d['lng'], $client['lat'], $client['lng']) <= self::CLUSTER_KM) {
                    $d['members'][] = $client;
                    $n = count($d['members']);
                    $d['lat'] += ($client['lat'] - $d['lat']) / $n;
                    $d['lng'] += ($client['lng'] - $d['lng']) / $n;
                    $joined = true;
                    break;
                }
            }
            unset($d);
            if (! $joined) {
                $districts[] = ['lat' => $client['lat'], 'lng' => $client['lng'], 'members' => [$client]];
            }
        }

        foreach ($districts as $i => &$d) {
            $d['name'] = self::mostCommonTown($d['members']) ?? 'District '.($i + 1);
        }
        unset($d);

        // 2. Which one is home.
        $home = null;
        if ($studio['set'] ?? false) {
            $nearest = null;
            foreach ($districts as $i => $d) {
                $km = self::km($d['lat'], $d['lng'], $studio['lat'], $studio['lng']);
                if ($km <= self::STUDIO_REACH_KM && ($nearest === null || $km < $nearest[1])) {
                    $nearest = [$i, $km];
                }
            }
            if ($nearest !== null) {
                $home = $nearest[0];
            } else {
                $districts[] = ['lat' => $studio['lat'], 'lng' => $studio['lng'], 'members' => [], 'name' => 'Chakra'];
                $home = array_key_last($districts);
            }
        } elseif ($districts !== []) {
            $sizes = array_map(fn ($d) => count($d['members']), $districts);
            $home = array_search(max($sizes), $sizes, true);
        } else {
            $districts[] = ['lat' => null, 'lng' => null, 'members' => [], 'name' => 'Chakra'];
            $home = 0;
        }

        // 3. Everyone without a location: the district their address names,
        //    or home.
        foreach ($clients as $client) {
            if ($client['lat'] !== null && $client['lng'] !== null) {
                continue;
            }
            $town = self::townFrom($client['address'] ?? null);
            $match = $town === null ? null : self::findByName($districts, $town);
            $districts[$match ?? $home]['members'][] = $client;
        }

        // 4. Slots: home in the middle, the rest by real bearing.
        $taken = ['0,0' => true];
        $districts[$home]['slot'] = [0, 0];
        $order = array_keys($districts);
        usort($order, fn ($a, $b) => [count($districts[$b]['members']), $districts[$a]['name']] <=> [count($districts[$a]['members']), $districts[$b]['name']]);
        foreach ($order as $i) {
            if ($i === $home) {
                continue;
            }
            $bearing = self::bearing($districts[$home], $districts[$i]);
            $districts[$i]['slot'] = self::freeSlot($bearing, $taken);
            $taken[implode(',', $districts[$i]['slot'])] = true;
        }

        // 5. Keys, and who lives where.
        $out = [];
        $of = [];
        $used = [];
        foreach ($districts as $i => $d) {
            $key = Str::slug($d['name']) ?: 'district';
            $base = $key;
            for ($n = 2; isset($used[$key]); $n++) {
                $key = $base.'-'.$n;
            }
            $used[$key] = true;
            foreach ($d['members'] as $client) {
                $of[$client['id']] = $key;
            }
            $out[] = [
                'key' => $key,
                'name' => $d['name'],
                'slot' => $d['slot'],
                'home' => $i === $home,
                'count' => count($d['members']),
            ];
            if ($i === $home) {
                $homeKey = $key;
            }
        }

        return ['districts' => $out, 'of' => $of, 'home' => $homeKey];
    }

    /**
     * The town an address ends in: "Madurai Road , Manapparai" → Manapparai,
     * "… No1 Tollgate, Trichy-621216" → Trichy, "Chennai , Tamil Nadu" →
     * Chennai. Null when nothing usable is left.
     */
    public static function townFrom(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $parts = array_map(fn ($p) => trim(preg_replace('/[\s\-–]*\b\d{6}\b\s*$/u', '', trim($p))), preg_split('/[,\n]+/', $address));
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''
            && ! preg_match('/\d/', $p)
            && ! preg_match('/^(tamil\s*nadu|tn|india)$/i', $p)));

        if ($parts === []) {
            return null;
        }

        return Str::title(Str::lower(end($parts)));
    }

    /** @param  list<array<string, mixed>>  $members */
    private static function mostCommonTown(array $members): ?string
    {
        $towns = array_filter(array_map(fn ($m) => self::townFrom($m['address'] ?? null), $members));
        if ($towns === []) {
            return null;
        }
        $counts = array_count_values($towns);
        arsort($counts);

        return array_key_first($counts);
    }

    private static function findByName(array $districts, string $town): ?int
    {
        foreach ($districts as $i => $d) {
            if (strcasecmp($d['name'], $town) === 0) {
                return $i;
            }
        }

        return null;
    }

    /** Degrees clockwise from north, from one district to another. */
    private static function bearing(array $from, array $to): float
    {
        if ($from['lat'] === null || $to['lat'] === null) {
            return 90.0; // nowhere to measure from: put it to the east
        }
        $east = ($to['lng'] - $from['lng']) * cos(deg2rad(($from['lat'] + $to['lat']) / 2));
        $north = $to['lat'] - $from['lat'];

        return fmod(rad2deg(atan2($east, $north)) + 360, 360);
    }

    /**
     * The free cell, nearest ring first, whose on-screen direction best
     * matches the bearing. A cell [dx, dy] sits at screen (dx - dy, dx + dy)
     * from home, y pointing down.
     *
     * @param  array<string, true>  $taken
     * @return array{0: int, 1: int}
     */
    private static function freeSlot(float $bearing, array $taken): array
    {
        for ($ring = 1; ; $ring++) {
            $best = null;
            for ($dx = -$ring; $dx <= $ring; $dx++) {
                for ($dy = -$ring; $dy <= $ring; $dy++) {
                    if (max(abs($dx), abs($dy)) !== $ring || isset($taken["{$dx},{$dy}"])) {
                        continue;
                    }
                    $cell = fmod(rad2deg(atan2($dx - $dy, -($dx + $dy))) + 360, 360);
                    $off = abs(fmod($cell - $bearing + 540, 360) - 180);
                    if ($best === null || $off < $best[1]) {
                        $best = [[$dx, $dy], $off];
                    }
                }
            }
            if ($best !== null) {
                return $best[0];
            }
        }
    }

    private static function km(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): float
    {
        if ($lat1 === null || $lat2 === null) {
            return INF;
        }
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
