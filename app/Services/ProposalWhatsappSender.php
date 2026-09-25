<?php

namespace App\Services;

use App\Models\Proposal;
use App\Support\WhatsappServiceWindow;
use RuntimeException;

/**
 * Sending a client their proposal link on WhatsApp -- one path for the
 * Send button on the proposal page and for Claude's send tool.
 *
 * Two routes, the brand-brief reminder's (ClientBriefNudge): inside the
 * 24-hour window after the client last messaged the studio, the link goes as
 * plain text, which needs no Meta approval; outside it, Meta only accepts the
 * approved proposal_ready template, whose button carries the token into
 * p/{{1}}. Either way the attempt is logged on the proposal.
 *
 * Sending needs a link, so one is created if there is none -- which also
 * moves a draft to "sent", as creating it by hand does.
 */
class ProposalWhatsappSender
{
    public function __construct(private readonly DocumentWhatsappNotifier $notifier) {}

    /**
     * @return 'message'|'template' which route it went by
     *
     * @throws RuntimeException when WhatsApp refuses it; the message says why
     */
    public function send(Proposal $proposal, string $phone, int $userId): string
    {
        $proposal->loadMissing('client');

        if ($proposal->public_token === null) {
            $proposal->issuePublicToken();
        }

        if (WhatsappServiceWindow::isOpen($phone)) {
            $this->notifier->sendText($proposal, $phone, $proposal->whatsappMessage(), $userId);

            return 'message';
        }

        $this->notifier->send(
            document: $proposal,
            phone: $phone,
            template: Proposal::WHATSAPP_TEMPLATE,
            bodyParameters: [$proposal->recipientName(), $proposal->title],
            buttonUrlParameter: $proposal->public_token,
            sentByUserId: $userId,
        );

        return 'template';
    }
}
