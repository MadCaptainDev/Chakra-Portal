<?php

namespace App\Console\Commands;

use App\Models\TimesheetEntry;
use App\Services\TimesheetWorkbookCleaner;
use Illuminate\Console\Command;

class CleanTimesheet extends Command
{
    protected $signature = 'timesheet:clean
                            {source? : Path to the messy Daily Timesheet .xlsx}
                            {--out= : Destination path for the cleaned workbook}';

    protected $description = 'Clean every timesheet row (dates, times, effort, status) into a new Excel file before import.';

    public function handle(TimesheetWorkbookCleaner $cleaner): int
    {
        $source = $this->argument('source') ?: base_path('Daily Timesheet .xlsx');
        $out = $this->option('out')
            ?: storage_path('app/timesheets/Daily Timesheet Clean.xlsx');

        $this->info('Cleaning '.$source);
        $this->line('Output  '.$out);

        try {
            $report = $cleaner->clean($source, $out);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Also drop a copy next to the source so it is easy to open/download.
        $publicCopy = base_path('Daily Timesheet Clean.xlsx');
        if (realpath($out) !== realpath($publicCopy)) {
            copy($out, $publicCopy);
            $this->line('Copied to '.$publicCopy);
        }

        $rows = [];
        foreach ($report['sheets'] as $name => $sheet) {
            $rows[] = [
                $name,
                $sheet['rows'],
                TimesheetEntry::formatMinutes($sheet['total_minutes']),
                $sheet['recovered'],
                $sheet['zero_effort'],
                ($sheet['date_min'] ?? '—').' → '.($sheet['date_max'] ?? '—'),
            ];
        }

        $this->newLine();
        $this->table(
            ['Sheet', 'Rows', 'Total time', 'Derived effort', 'Zero mins', 'Date range'],
            $rows
        );

        $this->newLine();
        $this->info('Clean workbook ready. Review it, then run: php artisan timesheet:import --fresh');

        return self::SUCCESS;
    }
}
