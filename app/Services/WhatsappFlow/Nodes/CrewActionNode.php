<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\EquipmentItem;
use App\Models\WhatsappFlowSession;
use App\Support\CrewPortal;
use RuntimeException;

/**
 * Crew self-service: their own shoots, confirming a call time, flagging kit.
 *
 * The staff-side twin of ClientActionNode. Only works when the number belongs
 * to a staff member (crew.id is injected by FlowEngine on a recognised
 * number); an unknown number gets told so and the flow stops there, rather
 * than a stranger learning the studio's schedule by texting it.
 */
class CrewActionNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $user = CrewPortal::userForSession($session);

        if ($user === null) {
            throw new RuntimeException('No staff member is linked to this WhatsApp number.');
        }

        // What they actually typed, for flag_kit to find an item name in.
        // The normalised copy is the one FlowEngine lower-cases; matching is
        // case-insensitive either way, so either would do.
        $message = (string) (data_get($session->variables, 'message.text') ?? '');

        $body = match ($nodeConfig['action'] ?? '') {
            'my_shoots' => CrewPortal::myShoots($user),
            'confirm_call_time' => CrewPortal::confirmNextShoot($user),
            'flag_damaged' => CrewPortal::flagKit($user, $message, EquipmentItem::STATUS_DAMAGED),
            'flag_missing' => CrewPortal::flagKit($user, $message, EquipmentItem::STATUS_LOST),
            default => throw new RuntimeException('Choose what this Crew Action node should do.'),
        };

        CrewPortal::sendToSession($session, $body);

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
