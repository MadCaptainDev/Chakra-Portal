<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappConversation;
use App\Models\WhatsappLabel;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * The 2-way inbox: every WhatsApp thread the studio has open, and the one
 * screen that reads and replies to them.
 *
 * The list here is deliberately built off WhatsappConversation's own
 * denormalized columns (last_message_at, last_message_summary, unread_count)
 * rather than calling messages() per row -- messages() re-queries
 * WhatsappWebhookEvent from scratch and is only safe to call once a thread is
 * actually open. show() is that one place.
 */
class WhatsappInboxController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('q'));
        $labelId = $request->integer('label') ?: null;

        $conversations = WhatsappConversation::query()
            ->with(['contact', 'labels', 'assignedTo'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('wa_id', 'like', "%{$search}%")
                        ->orWhere('last_message_summary', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($labelId, fn ($query) => $query->whereHas('labels', fn ($l) => $l->whereKey($labelId)))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('whatsapp-crm.inbox.index', [
            'conversations' => $conversations,
            'labels' => WhatsappLabel::orderBy('name')->get(),
            'search' => $search,
            'selectedLabelId' => $labelId,
        ]);
    }

    /**
     * The thread. Reads messages() fresh (the only place in this controller
     * that is allowed to -- see the class docblock) and, as a side effect,
     * clears the badge for this conversation.
     *
     * ?peek=1 skips that clear. It exists so that something which opens this
     * page without a person actually reading it (a preview, a background
     * check) does not silently zero a badge nobody has seen yet. Nothing in
     * this task's own polling calls show() with it -- the polling hits
     * messages() below, which never touches unread_count -- but the flag is
     * the documented escape hatch for whatever future caller needs one.
     */
    public function show(Request $request, WhatsappConversation $conversation): View
    {
        $conversation->load(['contact', 'assignedTo', 'labels', 'notes.author']);

        $messages = $conversation->messages();

        if (! $request->boolean('peek')) {
            $conversation->update(['unread_count' => 0]);
        }

        return view('whatsapp-crm.inbox.show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'labels' => WhatsappLabel::orderBy('name')->get(),
            // Only people who can actually see this module -- assigning a
            // thread to someone with no way to open it would just be a name
            // on a label nobody can act on.
            'assignableUsers' => User::canSee('whatsapp-crm')->orderBy('name')->get(),
        ]);
    }

    /**
     * JSON tail of the thread, for the polling script in
     * resources/js/whatsapp-inbox.js. `after` is the highest WhatsappWebhookEvent
     * id the browser has already rendered -- everything past it is new.
     *
     * Never touches unread_count: a poll happening in the background of a
     * page that is already open is not a new "you have unread messages"
     * event, it is the same open thread catching up.
     */
    public function messages(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $after = $request->integer('after');

        $messages = $conversation->messages()
            ->filter(fn (WhatsappWebhookEvent $event) => $event->id > $after)
            ->values()
            ->map(fn (WhatsappWebhookEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'summary' => $event->summary,
                'time' => $event->occurred_at?->format('h:i A'),
            ]);

        return response()->json(['messages' => $messages]);
    }

    /**
     * Free text, which Meta only accepts inside the 24-hour customer-service
     * window. WhatsappSender::sendText() is the one place that window is
     * enforced (Meta itself rejects the call and WhatsappGraph turns that
     * into a RuntimeException carrying Meta's own reason) -- this deliberately
     * does not re-check the window itself, so there is only one place that
     * rule can drift from what Meta actually does.
     *
     * Any failure -- outside the window or otherwise -- surfaces as a
     * validation error on the reply form suggesting a template, since a
     * template is the only free-form-adjacent option that works regardless
     * of why sendText refused.
     */
    public function reply(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        try {
            WhatsappSender::make()->sendText($conversation->wa_id, $data['body']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'body' => $e->getMessage().' Send a template message instead.',
            ]);
        }

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', 'Message sent.');
    }

    public function markRead(WhatsappConversation $conversation): JsonResponse
    {
        $conversation->update(['unread_count' => 0]);

        return response()->json(['unread_count' => 0]);
    }

    public function assign(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $conversation->update(['assigned_to_id' => $data['assigned_to_id'] ?? null]);

        $name = $conversation->fresh()->assignedTo?->name;

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', $name ? "Assigned to {$name}." : 'Unassigned.');
    }

    /**
     * Names whoever this number belongs to -- a lot of threads start life
     * as nothing but a phone number, and the inbox and every WhatsApp
     * screen that shows this contact (campaigns, phonebooks) fall back to
     * that number until someone puts a name to it here.
     *
     * find-or-create by phone rather than requiring a contact to already
     * exist: the common case is exactly the one where it doesn't yet --
     * this IS how most contacts in this table get their first name.
     * WhatsappContact::setPhoneAttribute() normalises through the same
     * WhatsappSender::normalise() the wa_id itself already is, so the two
     * always agree on format.
     */
    public function updateContact(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $contact = $conversation->contact ?? WhatsappContact::firstOrNew(['phone' => $conversation->wa_id]);
        $contact->name = $data['name'];
        $contact->save();

        if ($conversation->contact_id !== $contact->id) {
            $conversation->update(['contact_id' => $contact->id]);
        }

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', "Saved as {$contact->name}.");
    }

    public function attachLabel(WhatsappConversation $conversation, WhatsappLabel $label): RedirectResponse
    {
        $conversation->labels()->syncWithoutDetaching([$label->id]);

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', "Labeled \"{$label->name}\".");
    }

    public function detachLabel(WhatsappConversation $conversation, WhatsappLabel $label): RedirectResponse
    {
        $conversation->labels()->detach($label->id);

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', "Removed \"{$label->name}\" label.");
    }
}
