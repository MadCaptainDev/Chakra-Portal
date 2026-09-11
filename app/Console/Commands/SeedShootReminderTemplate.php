<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Shoot::WHATSAPP_TEMPLATE_REMINDER template
 * SendShootReminders depends on. Meta has to approve it before that
 * command's WhatsApp step can actually send -- run this once per WhatsApp
 * Business Account, then watch "Templates -> Refresh from Meta" for it to
 * flip to Approved. Mirrors SeedQuotationReadyTemplate; no button, since
 * the call sheet's URL (/shoots/{id}/call-sheet) puts the id in the middle
 * of the path and Meta's dynamic URL button only fills a trailing suffix
 * -- the reminder puts everything worth knowing in the body text instead.
 *
 * Body is written to satisfy Meta's own template rules, worked out the hard
 * way from the actual Graph API error responses (the exception message
 * this command prints is generic; the real reason lands in the Laravel log
 * -- see WhatsappGraph::throwFrom()'s error_user_msg): no two {{n}}
 * placeholders may sit directly next to each other, a placeholder may not
 * open the body, and a template three variables long with barely more real
 * words than that fails Meta's "parameters words ratio" check outright --
 * it wants a healthy amount of static text around its variables, not a
 * skeleton of mostly placeholders. Every {{n}} here always gets a real,
 * non-blank value from SendShootReminders -- there is no "some shoots skip
 * {{3}}" case, since an approved template's variables are not optional at
 * send time either.
 */
class SeedShootReminderTemplate extends Command
{
    protected $signature = 'app:seed-shoot-reminder-template';

    protected $description = 'Submit the shoot_reminder WhatsApp template (used by shoots:send-reminders) to Meta for approval';

    public function handle(): int
    {
        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Shoot::WHATSAPP_TEMPLATE_REMINDER,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => "Hi! This is a reminder that you're on the crew for \"{{1}}\", scheduled "
                    ."tomorrow on {{2}}. {{3}} Please plan to arrive on time and bring your usual kit.",
                'body_example' => ['SVA Silks shoot', 'Fri 12 Sep', 'Call 9:00am · Studio 2.'],
                'footer' => 'Chakra Groups',
            ]);
        } catch (RuntimeException $e) {
            $this->error("Meta rejected the submission: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Submitted. Status: '.($response['status'] ?? 'unknown').'. Check Templates -> Refresh from Meta once Meta finishes reviewing it.');

        return self::SUCCESS;
    }
}
