<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Support\WhatsappServiceWindow;
use RuntimeException;

/**
 * Remind a client to fill in their brand brief through the studio's
 * connected WhatsApp number, so the send lands in WhatsApp CRM.
 */
class ClientBriefNudge
{
    public function send(Client $client, User $by): WhatsappConversation
    {
        if (! filled($client->phone)) {
            throw new RuntimeException('This client has no phone number on file.');
        }

        $brief = $client->brief()->firstOrCreate([]);

        if ($brief->isSubmitted()) {
            throw new RuntimeException('This brief has already been submitted.');
        }

        if ($brief->public_token === null) {
            $brief->issuePublicToken($by);
        }

        $message = $this->messageText($client, $brief);
        $sender = WhatsappSender::make();

        if (WhatsappServiceWindow::isOpen($client->phone)) {
            $sender->sendText($client->phone, $message);
        } else {
            $sender->sendTemplate(
                to: $client->phone,
                template: ClientBrief::WHATSAPP_TEMPLATE,
                bodyParameters: [$client->name],
                buttonUrlParameter: $brief->public_token,
            );
        }

        return WhatsappConversation::firstOrCreate(
            ['wa_id' => WhatsappSender::normalise($client->phone)],
            ['last_message_at' => now(), 'last_message_summary' => $message],
        );
    }

    public function messageText(Client $client, ClientBrief $brief): string
    {
        $link = $brief->publicUrl() ?? route('client.brief');

        return 'Hi '.$client->name.' — before we start writing, could you fill in your brand brief? '
            .'It takes about ten minutes: '.$link;
    }
}
