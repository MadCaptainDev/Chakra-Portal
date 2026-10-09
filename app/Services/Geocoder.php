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

    /** @return array{lat: float, lng: float, matched: string}|null */
    public function locate(string $address): ?array
    {
        foreach (self::candidates($address) as $query) {
            $hit = $this->ask($query);
            if ($hit !== null) {
                return $hit + ['matched' => $query];
            }
        }

        return null;
    }

    /**
     * "No 9, SK Complex, Near Signal, Chennai Bypass, Trichy" → that, then
     * "SK Complex, Near Signal, Chennai Bypass, Trichy", ... then "Trichy".
     *
     * @return list<string>
     */
    public static function candidates(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $address)), fn ($p) => $p !== ''));
        $candidates = [];
        for ($i = 0; $i < count($parts); $i++) {
            $candidates[] = implode(', ', array_slice($parts, $i));
        }

        return array_values(array_unique($candidates));
    }

    /** @return array{lat: float, lng: float}|null */
    private function ask(string $query): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ChakraPortal/1.0 (+'.config('app.url').')'])
                ->get(self::ENDPOINT, ['q' => $query, 'format' => 'jsonv2', 'countrycodes' => 'in', 'limit' => 1]);
        } catch (Throwable $e) {
            Log::warning('Geocoding failed.', ['query' => $query, 'error' => $e->getMessage()]);

            return null;
        }

        $first = $response->successful() ? ($response->json()[0] ?? null) : null;

        return $first ? ['lat' => (float) $first['lat'], 'lng' => (float) $first['lon']] : null;
    }
}
