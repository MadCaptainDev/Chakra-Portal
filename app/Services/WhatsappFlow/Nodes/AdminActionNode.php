<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use App\Support\AdminPortal;
use RuntimeException;

/**
 * The owner's self-service: money, overdue, today's shoots, timesheet gaps.
 *
 * The third portal node, alongside ClientActionNode and CrewActionNode, and
 * the only one whose refusal matters commercially: everything it sends reads
 * across every client's money, so an employee hitting it is refused exactly
 * as a stranger is.
 */
class AdminActionNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $user = AdminPortal::userForSession($session);

        if ($user === null) {
            throw new RuntimeException('This WhatsApp number does not belong to an admin.');
        }

        $body = match ($nodeConfig['action'] ?? '') {
            'money' => AdminPortal::money(),
            'overdue' => AdminPortal::overdue(),
            'todays_shoots' => AdminPortal::todaysShoots(),
            'timesheet_gaps' => AdminPortal::timesheetGaps(),
            default => throw new RuntimeException('Choose what this Admin Action node should send.'),
        };

        AdminPortal::sendToSession($session, $body);

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
