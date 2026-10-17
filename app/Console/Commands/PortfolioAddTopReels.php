<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Portfolio\TopReelsImporter;
use Illuminate\Console\Command;

class PortfolioAddTopReels extends Command
{
    protected $signature = 'portfolio:add-top-reels {--per-client=2 : How many reels per client} {--client= : Only this client id}';

    protected $description = "Add each active client's most-viewed Instagram reels (not already in it) to the portfolio";

    public function handle(TopReelsImporter $importer): int
    {
        $clients = Client::query()->where('is_active', true)
            ->when($this->option('client'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->get();

        foreach ($clients as $client) {
            $added = $importer->add($client, max(1, (int) $this->option('per-client')));
            if ($added) {
                $this->info($client->name.': '.collect($added)->map(fn ($i) => "#{$i->id} {$i->title} (".number_format((int) $i->views).' views)')->implode('; '));
            }
        }

        return self::SUCCESS;
    }
}
