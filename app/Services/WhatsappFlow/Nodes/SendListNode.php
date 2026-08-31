<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use App\Services\WhatsappSender;
use App\Support\FlowVariables;

/**
 * Sends a WhatsApp list message -- a "Select Option" green button opening a
 * tappable menu of rows -- to the number the session belongs to.
 *
 * Stateless by design, same as every other node here: this node sends and
 * ends (leave `next` unset), and the client's tap arrives as a brand-new
 * inbound message rather than a reply this session waits for. There is no
 * "awaiting a tap" state -- FlowEngine's MAX_NODE_VISITS cap (5) exists to
 * stop a runaway graph, and a real menu session that stayed open across
 * many taps would run straight into it. Route the tap with an ordinary
 * `condition` node matching `message.choice`, which FlowEngine seeds with
 * the tapped row's id (or the typed text, for anyone who just types the
 * number instead) -- see FlowEngine::recordInboundMessage().
 *
 * List messages are free-form, same 24-hour customer-service window as
 * send_message -- see WhatsappSender::sendInteractiveList()'s own doc
 * block. A send_list placed behind a long delay node, or reached any way
 * other than as a direct reply, can fall outside that window and fail.
 *
 * Config: `body` (interpolated), `rows` (array of {id, title, description?}
 * -- id is never interpolated, it is the branch key), `button` (label,
 * defaults to "Select Option"), optional `header`/`footer` (interpolated),
 * optional `to` (defaults to the session's wa_id), `next`.
 */
class SendListNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $rows = array_map(
            fn (array $row) => [
                'id' => (string) ($row['id'] ?? ''),
                'title' => FlowVariables::interpolate((string) ($row['title'] ?? ''), $session),
                'description' => FlowVariables::interpolate((string) ($row['description'] ?? ''), $session),
            ],
            is_array($nodeConfig['rows'] ?? null) ? $nodeConfig['rows'] : []
        );

        $header = $nodeConfig['header'] ?? null;
        $footer = $nodeConfig['footer'] ?? null;

        WhatsappSender::make()->sendInteractiveList(
            to: $nodeConfig['to'] ?? $session->wa_id,
            body: FlowVariables::interpolate((string) ($nodeConfig['body'] ?? ''), $session),
            rows: $rows,
            buttonLabel: (string) ($nodeConfig['button'] ?? '') ?: 'Select Option',
            header: blank($header) ? null : FlowVariables::interpolate((string) $header, $session),
            footer: blank($footer) ? null : FlowVariables::interpolate((string) $footer, $session),
        );

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
