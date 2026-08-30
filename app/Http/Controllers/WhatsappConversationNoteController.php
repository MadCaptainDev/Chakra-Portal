<?php

namespace App\Http\Controllers;

use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Internal notes on one conversation -- never sent to the contact, just
 * context a teammate leaves for whoever picks the thread up next.
 *
 * Scoped entirely under the inbox (inbox/{conversation}/notes) rather than
 * given its own top-level resource: a note has no meaning outside the
 * conversation it was left on, and nothing here ever lists notes across
 * conversations.
 */
class WhatsappConversationNoteController extends Controller
{
    public function store(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        // Its own error bag: the thread view shows a note form and a reply
        // form side by side, both with a field named "body" -- without this
        // a note validation failure would light up the reply box too.
        $data = $request->validateWithBag('note', [
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $conversation->notes()->create([
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', 'Note added.');
    }

    /**
     * Route-model-bound on the conversation only ({note} is resolved as a
     * bare WhatsappConversationNote id) -- the mismatch guard below is what
     * actually stops a note id belonging to a different conversation being
     * deleted through the wrong URL.
     */
    public function destroy(WhatsappConversation $conversation, WhatsappConversationNote $note): RedirectResponse
    {
        abort_unless($note->conversation_id === $conversation->id, 404);

        $note->delete();

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', 'Note deleted.');
    }
}
