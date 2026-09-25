<?php

namespace App\Console\Commands;

use App\Models\Proposal;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Proposal::WHATSAPP_TEMPLATE template that
 * ProposalController::sendWhatsapp() uses when the client has not messaged
 * the studio in the last 24 hours. Meta has to approve it before that path
 * can send -- run this once per WhatsApp Business Account, then watch
 * "Templates -> Refresh from Meta" for it to flip to Approved. Mirrors
 * SeedQuotationReadyTemplate, with p/{{1}} instead of q/{{1}}.
 */
class SeedProposalReadyTemplate extends Command
{
    protected $signature = 'app:seed-proposal-ready-template';

    protected $description = 'Submit the proposal_ready WhatsApp template (used by Send on WhatsApp on a proposal) to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Proposal::WHATSAPP_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi {{1}}, your proposal "{{2}}" is ready. Tap below to read it, comment on any section or download the PDF.',
                'body_example' => ['Priya', 'E-commerce platform'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'View Proposal',
                    'url' => $baseUrl.'/p/{{1}}',
                    'example' => [$baseUrl.'/p/sample-token-1234567890'],
                ]],
            ]);
        } catch (RuntimeException $e) {
            $this->error("Meta rejected the submission: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Submitted. Status: '.($response['status'] ?? 'unknown').'. Check Templates -> Refresh from Meta once Meta finishes reviewing it.');

        return self::SUCCESS;
    }
}
