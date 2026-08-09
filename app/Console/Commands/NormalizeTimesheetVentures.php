<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\TimesheetEntry;
use App\Support\TimesheetVenture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeTimesheetVentures extends Command
{
    protected $signature = 'timesheet:normalize-ventures
                            {--dry-run : Show what would change without writing}
                            {--align-clients : Set short notion_venture aliases on known clients (DJ, SVA Silks)}';

    protected $description = 'Map timesheet_entries.venture onto canonical client labels; clear unmapped junk.';

    public function handle(): int
    {
        if ($this->option('align-clients')) {
            $this->alignClientAliases();
        }

        $before = TimesheetEntry::query()
            ->select('venture', DB::raw('count(*) as cnt'))
            ->groupBy('venture')
            ->orderByDesc('cnt')
            ->get()
            ->mapWithKeys(fn ($row) => [($row->venture ?? '(null)') => (int) $row->cnt]);

        $this->info('Before: '.$before->count().' distinct venture values, '.$before->sum().' rows');

        $allowed = TimesheetVenture::allowedValues();
        $this->line('Canonical clients ('.count($allowed).'): '.implode(', ', $allowed));

        $remapped = 0;
        $cleared = 0;
        $unchanged = 0;
        $samples = ['remapped' => [], 'cleared' => []];

        TimesheetEntry::query()->orderBy('id')->chunkById(200, function ($entries) use (&$remapped, &$cleared, &$unchanged, &$samples) {
            foreach ($entries as $entry) {
                $original = $entry->venture;
                $canonical = TimesheetVenture::normalize($original);

                if ($original === $canonical) {
                    $unchanged++;

                    continue;
                }

                if ($canonical === null && $original !== null) {
                    $cleared++;
                    if (count($samples['cleared']) < 15) {
                        $samples['cleared'][] = $original;
                    }
                } else {
                    $remapped++;
                    if (count($samples['remapped']) < 15) {
                        $samples['remapped'][] = ($original ?? '(null)').' → '.($canonical ?? '(null)');
                    }
                }

                if (! $this->option('dry-run')) {
                    $entry->venture = $canonical;
                    $entry->save();
                }
            }
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Remapped to a client', $remapped],
                ['Cleared (unmapped junk → null)', $cleared],
                ['Already canonical / null', $unchanged],
            ]
        );

        if ($samples['remapped'] !== []) {
            $this->line('Sample remaps:');
            foreach ($samples['remapped'] as $sample) {
                $this->line('  · '.$sample);
            }
        }
        if ($samples['cleared'] !== []) {
            $this->line('Sample cleared:');
            foreach ($samples['cleared'] as $sample) {
                $this->line('  · '.$sample);
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no rows written.');

            return self::SUCCESS;
        }

        $after = TimesheetEntry::query()
            ->select('venture', DB::raw('count(*) as cnt'))
            ->groupBy('venture')
            ->orderByDesc('cnt')
            ->get();

        $this->newLine();
        $this->info('After: '.$after->count().' distinct venture values');
        $this->table(
            ['Venture', 'Rows'],
            $after->map(fn ($row) => [$row->venture ?? '(null)', $row->cnt])->all()
        );

        return self::SUCCESS;
    }

    private function alignClientAliases(): void
    {
        $aliases = [
            'DJ THANGA MAALIGAI' => 'DJ',
            'SVA Silks and Readymades' => 'SVA Silks',
        ];

        foreach ($aliases as $name => $notion) {
            $client = Client::query()->where('name', $name)->first();
            if (! $client) {
                $this->warn("Client not found for alias: {$name}");

                continue;
            }
            if ($client->notion_venture === $notion) {
                continue;
            }
            $previous = $client->notion_venture ?: '(null)';
            if (! $this->option('dry-run')) {
                $client->notion_venture = $notion;
                $client->save();
            }
            $this->line("Client alias: {$name}: {$previous} → {$notion}");
        }
    }
}
