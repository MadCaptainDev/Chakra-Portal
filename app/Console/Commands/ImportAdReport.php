<?php

namespace App\Console\Commands;

use App\Models\AdReport;
use App\Models\Client;
use App\Services\AdReports\AdReportImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Put a monthly paid-ads report into the portal and print the link to it.
 *
 *   php artisan ad-reports:import thillai-pet-clinic-meta-ads-2026-09.json --client=16
 *
 * Safe to run again for the same client and month: it replaces that report
 * and keeps the link it already had.
 */
class ImportAdReport extends Command
{
    protected $signature = 'ad-reports:import
        {file : Path to the report JSON}
        {--client= : The portal client id this report belongs to}
        {--no-client : Import it without attaching it to a client}';

    protected $description = 'Import a monthly paid-ads report (JSON) and print its shareable link';

    public function handle(AdReportImporter $importer): int
    {
        $path = $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        try {
            $data = $importer->decode((string) file_get_contents($path));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($problems = $importer->problems($data)) {
            $this->error('This cannot be imported:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $client = $this->resolveClient((string) $data['report']['client']);

        if ($client === false) {
            return self::FAILURE;
        }

        if ($warnings = $importer->warnings($data)) {
            $this->warn('The numbers do not all agree — check before sending the link on:');
            foreach ($warnings as $warning) {
                $this->line("  - {$warning}");
            }
        } else {
            $this->info('Numbers check out: spend, results, charges, top-ups and best performer all agree.');
        }

        $existed = AdReport::query()
            ->where('platform', $data['report']['platform'] ?? 'Meta Ads')
            ->whereDate('period_start', $data['report']['period']['start'])
            ->when($client === null, fn ($q) => $q->whereNull('client_id'), fn ($q) => $q->where('client_id', $client->id))
            ->exists();

        $report = $importer->import($data, $client?->id);

        $this->newLine();
        $this->info(($existed ? 'Updated' : 'Imported').' #'.$report->id.': '.$report->title);
        $this->line('  Client: '.($client?->name ?? '(none attached)'));
        $this->line('  Link:   '.($report->publicUrl() ?? '(switched off — this link was revoked and stays off)'));

        return self::SUCCESS;
    }

    /**
     * @return Client|null|false the client, null for "none on purpose", false
     *                           when it could not be settled (already reported)
     */
    private function resolveClient(string $nameInReport): Client|null|false
    {
        if ($this->option('no-client')) {
            return null;
        }

        $id = $this->option('client');

        if ($id === null || $id === '') {
            // Never guessed from the name: a name in a report is free text, and
            // "Thillai Pet Clinic" is not "Thillai Pets Clinic". Suggestions
            // only, so the right id is easy to find.
            $this->error("The report is for \"{$nameInReport}\", and I will not guess which portal client that is.");

            $first = strtok($nameInReport, ' ');
            $near = Client::query()->where('name', 'like', '%'.$first.'%')->limit(5)->get(['id', 'name']);

            foreach ($near as $candidate) {
                $this->line("  --client={$candidate->id}   {$candidate->name}");
            }
            $this->line('Or pass --no-client to import it unattached.');

            return false;
        }

        $client = Client::find($id);

        if (! $client) {
            $this->error("There is no client with id {$id}.");

            return false;
        }

        return $client;
    }
}
