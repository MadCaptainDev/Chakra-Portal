<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Invoice::WHATSAPP_TEMPLATE_PAYMENT_RECEIVED
 * template App\Services\PaymentReceipt sends outside the 24-hour window.
 * Meta has to approve it before those sends arrive. Plenty of plain words
 * round the four variables -- Meta refuses a body that is mostly variables.
 */
class SeedPaymentReceivedTemplate extends Command
{
    protected $signature = 'app:seed-payment-received-template';

    protected $description = 'Submit the payment_received WhatsApp template (the client\'s thank-you for a payment) to Meta for approval';

    public function handle(): int
    {
        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Invoice::WHATSAPP_TEMPLATE_PAYMENT_RECEIVED,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi {{1}}, thank you! We have received your payment of Rs. {{2}} for invoice {{3}}. Balance remaining on this invoice: Rs. {{4}}. Thank you for working with us.',
                'body_example' => ['Janet', '7,500.00', 'CP-0042', '0.00'],
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
