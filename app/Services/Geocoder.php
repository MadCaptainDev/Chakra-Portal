<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Address text to a point on the map, via OpenStreetMap's Nominatim.
 *
 * Nominatim's usage policy: an identifying User-Agent, at most one request a
 * second, and cache what you get -- clients:geocode sleeps between calls and
 * the answer is stored on the client, so each address is asked about once.
 *
 * Studio addresses are written loosely ("Madurai Road , Manapparai"), so a
 * miss on the whole string is retried with the leading parts dropped, ending
 * on the town -- a pin in the right town beats no pin. Restricted to India.
 */
class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return array{lat: float, lng: float, matched: string}|null null when OpenStreetMap does not know it
     *
     * @throws GeocoderUnavailable when OpenStreetMap will not answer at all
     */
    public function locate(string $address): ?array
    {
        $candidates = self::candidates($address);
        $named = array_values(array_filter($candidates, fn ($c) => ! str_ends_with($c, ', India')));
        $town = $named === [] ? null : end($named);

        // The town first: a street name matches streets all over the state
        // ("Madurai Road" is in every town on the way to Madurai), so a more
        // exact hit only counts if it is near the town the address names.
        $townHit = $town !== null ? $this->ask($town) : null;

        foreach ($candidates as $query) {
            if ($query === $town) {
                if ($townHit !== null) {
                    return $townHit + ['matched' => $town];
                }

                continue;
            }
            $hit = $this->ask($query);
            if ($hit === null) {
                continue;
            }
            if ($townHit !== null && self::km($hit, $townHit) > self::NEAR_TOWN_KM) {
                continue;
            }

            return $hit + ['matched' => $query];
        }

        return null;
    }

    /** How far an exact match may sit from the town the address names. */
    private const NEAR_TOWN_KM = 20;

    /** @param  array{lat: float, lng: float}  $a  @param  array{lat: float, lng: float}  $b */
    private static function km(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /**
     * "No 9, SK Complex, Near Signal, Chennai Bypass, Trichy" → that, then
     * "SK Complex, Near Signal, Chennai Bypass, Trichy", ... then "Trichy".
     *
     * @return list<string>
     */
    public static function candidates(string $address): array
    {
        // What people write versus what OpenStreetMap calls it. "Pudhukottai"
        // with an h is a village near Thanjavur; the town has a double k.
        $address = preg_replace(
            ['/\bTrichy\b/i', '/\bPudh?u?kk?ott?ai\b/i'],
            ['Tiruchirappalli', 'Pudukkottai'],
            $address,
        );

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $address)), fn ($p) => $p !== ''));
        $candidates = [];
        for ($i = 0; $i < count($parts); $i++) {
            $candidates[] = implode(', ', array_slice($parts, $i));
        }

        // Last resort: a six-digit PIN code anywhere in it puts the pin in
        // the right postal area, which beats none.
        if (preg_match('/\b(6\d{5})\b/', $address, $pin)) {
            $candidates[] = $pin[1].', India';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * "Not there" and "would not answer" are different: the first is null and
     * is remembered, the second throws so nothing is remembered and the
     * address is asked about again next time. Nominatim answers 429 to a
     * shared hosting IP whenever its neighbours have been busy.
     *
     * @return array{lat: float, lng: float}|null
     *
     * @throws GeocoderUnavailable
     */
    private function ask(string $query): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ChakraPortal/1.0 (+'.config('app.url').')'])
                ->get(self::ENDPOINT, ['q' => $query, 'format' => 'jsonv2', 'countrycodes' => 'in', 'limit' => 1]);
        } catch (Throwable $e) {
            Log::warning('Geocoding failed.', ['query' => $query, 'error' => $e->getMessage()]);

            throw new GeocoderUnavailable('OpenStreetMap could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('Geocoding refused.', ['query' => $query, 'status' => $response->status()]);

            throw new GeocoderUnavailable($response->status() === 429
                ? 'OpenStreetMap is turning lookups away for a while (too many requests from this server).'
                : 'OpenStreetMap answered '.$response->status().'.');
        }

        $first = $response->json()[0] ?? null;

        return $first ? ['lat' => (float) $first['lat'], 'lng' => (float) $first['lon']] : null;
    }
}
