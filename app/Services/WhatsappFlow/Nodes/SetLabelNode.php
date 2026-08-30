<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappConversation;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappLabel;

/**
 * Tags the session's conversation with a label, creating the label by name
 * if this is the first flow to use it.
 *
 * Config: `label` (name), `next`. If the wa_id has no WhatsappConversation
 * row yet (the webhook observer that creates one may not have run first),
 * this is a no-op rather than a failure -- there is nothing to tag.
 */
class SetLabelNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $name = trim((string) ($nodeConfig['label'] ?? ''));

        if ($name !== '') {
            $conversation = WhatsappConversation::where('wa_id', $session->wa_id)->first();

            if ($conversation) {
                $label = WhatsappLabel::firstOrCreate(['name' => $name]);
                $conversation->labels()->syncWithoutDetaching([$label->id]);
            }
        }

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
