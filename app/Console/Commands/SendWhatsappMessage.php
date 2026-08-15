<?php

namespace App\Console\Commands;

use App\Models\WhatsappSetting;
use App\Services\WhatsappSender;
use Illuminate\Console\Command;
use Throwable;

/**
 * Send one WhatsApp message from the command line.
 *
 * This exists to prove the connection works end to end without a screen in the
 * way: run it, watch the wamid come back, watch the delivery statuses land in
 * Settings -> WhatsApp a second later. When something is wrong it is Meta's own
 * error that gets printed, which is almost always the whole diagnosis.
 */
class SendWhatsappMessage extends Command
{
    protected $signature = 'whatsapp:send
        {to : Recipient number. A bare 10-digit number is treated as Indian (+91)}
        {--template= : Name of an approved template, e.g. hello_world}
        {--language=en_US : Template language code}
        {--param=* : Body parameters, filling the template placeholders in order}
        {--text= : Free text. Only reaches someone who messaged us in the last 24 hours}';

    protected $description = 'Send a WhatsApp message through the Meta Cloud API';

    public function handle(): int
    {
        $settings = WhatsappSetting::current();

        if (! $settings->canSend()) {
            $this->error('Not configured. Set the access token and phone number ID in Settings -> WhatsApp.');

            return self::FAILURE;
        }

        $to = WhatsappSender::normalise($this->argument('to'));
        $template = $this->option('template');
        $text = $this->option('text');

        if ((bool) $template === (bool) $text) {
            $this->error('Pass exactly one of --template or --text.');

            return self::FAILURE;
        }

        $this->line("Sending to <options=bold>{$to}</> from phone number ID {$settings->phone_number_id}");

        try {
            $result = $template
                ? WhatsappSender::make()->sendTemplate(
                    to: $to,
                    template: $template,
                    language: (string) $this->option('language'),
                    bodyParameters: (array) $this->option('param'),
                )
                : WhatsappSender::make()->sendText($to, (string) $text);
        } catch (Throwable $e) {
            /*
             * Meta's message verbatim. The three that actually happen all name
             * their own fix: the recipient is not on the test list, the template
             * does not exist, or the token has expired.
             */
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Accepted by Meta.');
        $this->line('  wamid: '.($result['wamid'] ?? '(none returned)'));
        $this->line('  Delivery status will arrive on the webhook and show in Settings -> WhatsApp.');

        return self::SUCCESS;
    }
}
