<?php

namespace App\Console\Commands;

use App\Services\WhatsappTemplateService;
use App\Support\TimesheetDayReport;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Submits the template the 9 PM timesheet report falls back on when the
 * admin has not messaged the portal number in the last 24 hours -- see
 * SendTimesheetReport. Parameters are one line each (Meta refuses newlines
 * inside one), so the lists arrive comma-separated.
 */
class SeedTimesheetReportTemplate extends Command
{
    protected $signature = 'app:seed-timesheet-report-template';

    protected $description = 'Submit the timesheet_daily WhatsApp template (9 PM timesheet report) to Meta for approval';

    public function handle(): int
    {
        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => TimesheetDayReport::TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => "Hi, here is the timesheet for {{1}}: {{2}} of {{3}} people entered their hours.\n\nEntered: {{4}}\n\nNot entered: {{5}}\n\nReply to this message to get the full list every night.",
                'body_example' => ['Fri 2 Oct', '4', '6', 'Sanjai (7h 30m), Gokul (6h)', 'Nitis, Annamalai'],
                'footer' => 'Chakra Groups',
            ]);
        } catch (RuntimeException $e) {
            $this->error("Meta rejected the submission: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Submitted. Status: '.($response['status'] ?? 'unknown').'.');

        return self::SUCCESS;
    }
}
