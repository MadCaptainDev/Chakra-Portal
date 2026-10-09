<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Geocoder;
use Illuminate\Console\Command;

/**
 * Put clients on the Studio Map: look up every address not looked up yet,
 * or changed since. A pin somebody placed by hand is left alone. Daily in
 * routes/console.php; safe to run any time.
 */
class GeocodeClients extends Command
{
    protected $signature = 'clients:geocode {--force : Look up every looked-up address again (never hand-placed pins)} {--pause=1100 : Milliseconds between lookups (Nominatim allows one a second)}';

    protected $description = 'Look up client addresses on OpenStreetMap so they appear on the Studio Map';

    public function handle(Geocoder $geocoder): int
    {
        $clients = Client::query()
            ->whereNotNull('address')->where('address', '!=', '')
            ->where(fn ($q) => $q->whereNull('location_source')->orWhere('location_source', '!=', 'manual'))
            ->get()
            ->filter(fn (Client $c) => $this->option('force') || $c->geocoded_address !== $c->address);

        if ($clients->isEmpty()) {
            $this->info('Every address is already on the map.');

            return self::SUCCESS;
        }

        $found = 0;
        foreach ($clients->values() as $i => $client) {
            if ($i > 0) {
                usleep((int) $this->option('pause') * 1000);
            }

            $hit = $geocoder->locate($client->address);

            // Remembered either way, so an address that cannot be found is not
            // asked about again every night -- only once it is edited.
            $client->forceFill([
                'latitude' => $hit['lat'] ?? null,
                'longitude' => $hit['lng'] ?? null,
                'location_source' => $hit ? 'geocoded' : null,
                'geocoded_address' => $client->address,
            ])->save();

            $hit ? $found++ : $this->warn("Not found: {$client->name} ({$client->address})");
        }

        $this->info("{$found} of {$clients->count()} placed on the map.");

        return self::SUCCESS;
    }
}
