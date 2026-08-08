<?php

namespace App\Console\Commands;

use App\Models\TimesheetEntry;
use App\Services\TimesheetSheetImporter;
use Illuminate\Console\Command;

class ImportTimesheet extends Command
{
    protected $signature = 'timesheet:import
                            {file? : Path to a cleaned Daily Timesheet .xlsx}
                            {--fresh : Delete existing entries for the four employees before importing}
                            {--dry-run : Parse and report without writing}
                            {--raw : Import the original messy workbook instead of the cleaned one}';

    protected $description = 'Create employee logins and import rows from the cleaned Daily Timesheet workbook.';

    public function handle(TimesheetSheetImporter $importer): int
    {
        $cleanDefault = storage_path('app/timesheets/Daily Timesheet Clean.xlsx');
        $rawDefault = base_path('Daily Timesheet .xlsx');

        if ($this->argument('file')) {
            $file = $this->argument('file');
        } elseif ($this->option('raw')) {
            $file = $rawDefault;
        } elseif (is_file($cleanDefault)) {
            $file = $cleanDefault;
        } elseif (is_file(base_path('Daily Timesheet Clean.xlsx'))) {
            $file = base_path('Daily Timesheet Clean.xlsx');
        } else {
            $this->error('No cleaned workbook found. Run: php artisan timesheet:clean');

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '').'Reading '.$file);

        try {
            $report = $importer->run(
                $file,
                fresh: (bool) $this->option('fresh'),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Employees');
        $this->table(
            ['Name', 'Email', 'User ID'],
            collect($report['users'])->map(fn ($u) => [
                $u['name'],
                $u['email'],
                $u['id'] ?? '(dry-run)',
            ])->all()
        );

        if (! $report['dry_run']) {
            $this->line('Temporary password for all four: '.TimesheetSheetImporter::DEFAULT_PASSWORD);
        }

        $this->newLine();
        $this->info('Sheets');

        $rows = [];
        foreach ($report['sheets'] as $name => $sheet) {
            $rows[] = [
                $name,
                $sheet['imported'],
                $sheet['skipped'],
                $sheet['recovered_minutes'],
                TimesheetEntry::formatMinutes($sheet['total_minutes']),
                $sheet['pending'],
                $sheet['cancelled'],
                ($sheet['date_min'] ?? '—').' → '.($sheet['date_max'] ?? '—'),
            ];
        }

        $this->table(
            ['Sheet', 'Imported', 'Skipped', 'Recovered', 'Total time', 'Pending', 'Cancelled', 'Date range'],
            $rows
        );

        foreach ($report['sheets'] as $name => $sheet) {
            if ($sheet['issues'] === []) {
                continue;
            }
            $this->newLine();
            $this->warn("{$name} — sample issues (".count($sheet['issues']).' shown):');
            foreach ($sheet['issues'] as $issue) {
                $this->line('  · '.$issue);
            }
        }

        $grand = collect($report['sheets'])->sum('imported');
        $this->newLine();
        $this->info($report['dry_run']
            ? "Dry run complete — would import {$grand} entries."
            : "Import complete — {$grand} entries written.");

        return self::SUCCESS;
    }
}
