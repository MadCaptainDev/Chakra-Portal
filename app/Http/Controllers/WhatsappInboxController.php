<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappConversation;
use App\Models\WhatsappLabel;
use App\Models\WhatsappQuickReply;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\FlowEngine;
use App\Services\WhatsappMedia;
use App\Services\WhatsappSender;
use App\Services\WhatsappTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
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
        if ($request->boolean('partial')) {
            // The refresh from an open thread says which row to keep lit.
            $active = WhatsappConversation::find($request->integer('active'));

            return view('whatsapp-crm.inbox._list', $this->listData($request, $active));
        }

        return view('whatsapp-crm.inbox.index', $this->listData($request));
    }

    /**
     * The conversation list both screens draw -- index on its own, show()
     * beside the open thread, the way a chat app keeps the list in view.
     * ?partial=1 on index returns just the list, for the 15-second refresh
     * in whatsapp-inbox.js.
     *
     * @return array<string, mixed>
     */
    private function listData(Request $request, ?WhatsappConversation $active = null): array
    {
        $search = trim((string) $request->string('q'));
        $labelId = $request->integer('label') ?: null;
        $filter = in_array($request->query('filter'), ['unread', 'mine'], true) ? $request->query('filter') : 'all';

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
            ->when($filter === 'unread', fn ($query) => $query->where('unread_count', '>', 0))
            ->when($filter === 'mine', fn ($query) => $query->where('assigned_to_id', $request->user()->id))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->appends(['partial' => null, 'active' => null]);

        return [
            'conversations' => $conversations,
            'labels' => WhatsappLabel::orderBy('name')->get(),
            'search' => $search,
            'selectedLabelId' => $labelId,
            'filter' => $filter,
            'active' => $active,
            'unreadTotal' => WhatsappConversation::where('unread_count', '>', 0)->count(),
        ];
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

        $closesAt = $conversation->windowClosesAt();

        return view('whatsapp-crm.inbox.show', $this->listData($request, $conversation) + [
            'conversation' => $conversation,
            'messages' => $this->present($messages, $conversation),
            'windowClosesAt' => $closesAt,
            'windowOpen' => $closesAt !== null && $closesAt->isFuture(),
            'templates' => $this->templates($conversation),
            'quickReplies' => WhatsappQuickReply::orderBy('title')->get(['id', 'title', 'content']),
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

        $all = $this->present($conversation->messages(), $conversation);
        $closesAt = $conversation->windowClosesAt();

        return response()->json([
            'messages' => $all->filter(fn (array $message) => $message['id'] > $after)->values(),
            // Delivered/read/failed land on messages already on screen, so
            // every poll carries the latest status of each one we sent.
            'statuses' => $all->where('type', WhatsappWebhookEvent::TYPE_OUTGOING)
                ->mapWithKeys(fn (array $message) => [$message['id'] => ['status' => $message['status'], 'error' => $message['error']]]),
            'window_open' => $closesAt !== null && $closesAt->isFuture(),
            'window_closes_at' => $closesAt?->toIso8601String(),
        ]);
    }

    /**
     * Free text, which Meta only delivers inside the 24-hour customer-service
     * window. Meta does not refuse it up front: outside the window it accepts
     * the call and fails the message a few seconds later (131047), long after
     * the person who pressed Send was told "sent". So the window is checked
     * here first, off the last message this number sent us, and a closed one
     * is answered straight away with the one thing that works -- a template.
     *
     * Any refusal Meta does give up front still surfaces the same way, as a
     * validation error on the reply box.
     */
    public function reply(Request $request, WhatsappConversation $conversation): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        if (! $conversation->windowOpen()) {
            throw ValidationException::withMessages([
                'body' => 'More than 24 hours have passed since this number last messaged the studio, so WhatsApp will only deliver an approved template. Send a template instead.',
            ]);
        }

        try {
            WhatsappSender::make()->sendText($conversation->wa_id, $data['body']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'body' => $e->getMessage().' Send a template message instead.',
            ]);
        }

        return $this->sent($request, $conversation, 'Message sent.');
    }

    /**
     * An approved template, the one thing WhatsApp delivers whether or not
     * the 24-hour window is open. Only a template Meta currently lists as
     * approved can be picked -- anything else Meta would refuse anyway.
     */
    public function sendTemplate(Request $request, WhatsappConversation $conversation): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'template' => ['required', 'string', 'max:512'],
            'params' => ['array'],
            'params.*' => ['required', 'string', 'max:1024'],
            'button_param' => ['nullable', 'string', 'max:2000'],
        ], [
            'params.*.required' => 'Fill in every blank in the template.',
        ]);

        $template = collect($this->templates($conversation))->firstWhere('name', $data['template']);

        if (! $template) {
            throw ValidationException::withMessages(['template' => 'That template is not approved in Meta. Refresh the template list and pick another.']);
        }

        $params = array_values(array_map('strval', $data['params'] ?? []));

        if (count($params) !== $template['placeholder_count']) {
            throw ValidationException::withMessages(['params' => 'Fill in every blank in the template.']);
        }

        if ($template['has_url_button'] && blank($data['button_param'] ?? null)) {
            throw ValidationException::withMessages(['button_param' => 'This template has a link button -- fill in the end of its link.']);
        }

        try {
            WhatsappSender::make()->sendTemplate(
                $conversation->wa_id,
                $template['name'],
                $template['language'],
                $params,
                $template['has_url_button'] ? $data['button_param'] : null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['template' => $e->getMessage()]);
        }

        return $this->sent($request, $conversation, 'Template sent.');
    }

    /** What the composer gets back: JSON for its own fetch, a redirect for a plain form post. */
    private function sent(Request $request, WhatsappConversation $conversation, string $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $latest = WhatsappWebhookEvent::where('wa_id', $conversation->wa_id)
                ->where('type', WhatsappWebhookEvent::TYPE_OUTGOING)
                ->latest('id')
                ->first();

            return response()->json([
                'status' => $status,
                'message' => $latest ? $this->present(collect([$latest]), $conversation)->first() : null,
            ]);
        }

        return redirect()->route('whatsapp-crm.inbox.show', $conversation)
            ->with('status', $status);
    }

    /**
     * Messages as the thread draws them, each one we sent carrying how far
     * it got: sent (Meta accepted it), delivered, read, or failed with
     * Meta's reason in plain words. Statuses can arrive out of order, so
     * they only ever move forward -- except failed, which always wins,
     * the same rule WhatsappSendLog::applyStatus() keeps.
     *
     * @param  Collection<int, WhatsappWebhookEvent>  $messages
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $messages, WhatsappConversation $conversation): Collection
    {
        $wamids = $messages->where('type', WhatsappWebhookEvent::TYPE_OUTGOING)->pluck('external_id')->filter()->values();

        $statuses = $wamids->isEmpty() ? collect() : WhatsappWebhookEvent::where('type', WhatsappWebhookEvent::TYPE_STATUS)
            ->whereIn('external_id', $wamids)
            ->orderBy('id')
            ->get(['external_id', 'status', 'payload'])
            ->groupBy('external_id');

        $progress = ['sent' => 1, 'delivered' => 2, 'read' => 3];

        return $messages->map(function (WhatsappWebhookEvent $event) use ($statuses, $progress, $conversation) {
            $status = null;
            $error = null;

            if ($event->type === WhatsappWebhookEvent::TYPE_OUTGOING) {
                $status = 'sent';

                foreach ($statuses->get($event->external_id, []) as $update) {
                    if ($update->status === 'failed') {
                        $status = 'failed';
                        $error = WhatsappSendLog::explain(data_get($update->payload, 'errors', [])) ?? 'Not delivered.';
                        break;
                    }

                    if (($progress[$update->status] ?? 0) > ($progress[$status] ?? 0)) {
                        $status = $update->status;
                    }
                }
            }

            $media = null;
            $summary = $event->summary;

            if (WhatsappMedia::has($event)) {
                $file = data_get($event->payload, $event->message_type, []);
                $media = [
                    'url' => route('whatsapp-crm.inbox.media', [$conversation, $event]),
                    'kind' => $event->message_type === 'document' && str_starts_with((string) ($file['mime_type'] ?? ''), 'video/')
                        ? 'video'
                        : $event->message_type,
                    'filename' => $file['filename'] ?? null,
                    // Not saved, and old enough that Meta will have deleted
                    // it (its link lasts minutes, the media id a few days):
                    // say so rather than offer a link that can only fail.
                    'expired' => ! WhatsappMedia::stored($event) && $event->occurred_at?->lt(now()->subDays(2)),
                ];

                // Only a real caption stays as text under the file; the
                // "[image]" placeholder or the filename again is noise.
                $caption = $file['caption'] ?? null;
                $summary = filled($caption) ? $caption : null;
            }

            return [
                'id' => $event->id,
                'type' => $event->type,
                'kind' => $event->message_type,
                'summary' => $summary,
                'media' => $media,
                // Meta withheld the content itself (131051): a view-once
                // photo, a poll, an event, a message edited after sending...
                // There is nothing to fetch, so the thread says so plainly.
                'unsupported' => $event->message_type === 'unsupported',
                'time' => $event->occurred_at?->format('h:i A'),
                'day' => $event->occurred_at?->format('Y-m-d'),
                'day_label' => $event->occurred_at ? self::dayLabel($event->occurred_at) : null,
                'status' => $status,
                'error' => $error,
            ];
        })->values();
    }

    private static function dayLabel(\Carbon\CarbonInterface $at): string
    {
        return match (true) {
            $at->isToday() => 'Today',
            $at->isYesterday() => 'Yesterday',
            $at->isCurrentYear() => $at->format('D, d M'),
            default => $at->format('d M Y'),
        };
    }

    /**
     * Approved templates, shaped for the composer's picker: the body text
     * to preview, how many blanks it has, whether its first button is a
     * link with a blank of its own. "Hi {{1}}" templates get the contact's
     * first name filled in already.
     *
     * Never throws: no token or Meta unreachable is an empty picker, not a
     * broken thread (WhatsappTemplateService::list() already swallows both).
     *
     * @return array<int, array<string, mixed>>
     */
    private function templates(WhatsappConversation $conversation): array
    {
        if (! WhatsappSetting::current()->canSend()) {
            return [];
        }

        $firstName = str($conversation->contact?->name ?? '')->trim()->explode(' ')->first() ?: '';

        return collect(WhatsappTemplateService::make()->list())
            ->map(function (array $template) use ($firstName) {
                $components = collect($template['components'] ?? []);
                $body = (string) ($components->firstWhere('type', 'BODY')['text'] ?? '');
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
                $count = $matches[1] === [] ? 0 : (int) max($matches[1]);
                $button = $components->firstWhere('type', 'BUTTONS')['buttons'][0] ?? null;

                $prefill = array_fill(0, $count, '');
                if ($count > 0 && $firstName !== '' && preg_match('/^\s*(hi|hello|dear)\s*\{\{\s*1\s*\}\}/i', $body)) {
                    $prefill[0] = $firstName;
                }

                return [
                    'name' => $template['name'],
                    'label' => str(preg_replace('/_v\d+$/i', '', $template['name']))->replace('_', ' ')->ucfirst()->toString(),
                    'language' => $template['language'] ?? 'en_US',
                    'category' => $template['category'] ?? null,
                    'body' => $body,
                    'placeholder_count' => $count,
                    'prefill' => $prefill,
                    'has_url_button' => ($button['type'] ?? null) === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{'),
                    'button_url' => $button['url'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A photo, video, voice note or file in this thread, off our own disk
     * (WhatsappMedia saves it as it arrives), or from Meta if it predates
     * that and Meta still has it.
     */
    public function media(Request $request, WhatsappConversation $conversation, WhatsappWebhookEvent $event): Response
    {
        abort_unless(
            $event->wa_id === $conversation->wa_id
                && in_array($event->type, [WhatsappWebhookEvent::TYPE_MESSAGE, WhatsappWebhookEvent::TYPE_OUTGOING], true)
                && in_array($event->message_type, WhatsappMedia::KINDS, true),
            404
        );

        abort_unless(WhatsappMedia::has($event), 404);

        try {
            WhatsappMedia::fetchAndStore($event);
        } catch (RuntimeException $e) {
            abort(410, 'WhatsApp no longer has this file. Meta deletes media after a short time, and this one arrived before the portal started saving files as they come in.');
        }

        $file = data_get($event->payload, $event->message_type, []);
        $mime = WhatsappMedia::mime($event);
        $disk = Storage::disk('local');
        $path = WhatsappMedia::path($event);
        $extension = explode('/', explode(';', $mime)[0])[1] ?? 'bin';
        $filename = $file['filename'] ?? "{$event->message_type}-{$event->id}.{$extension}";
        // Inline only for types a browser shows without running anything --
        // an SVG or HTML file someone sent must never execute on this origin.
        $safeInline = preg_match('#^(image/(jpeg|png|webp|gif)|video/|audio/|application/pdf)#', $mime) === 1;
        $disposition = $safeInline && ! $request->boolean('download') ? 'inline' : 'attachment';

        return response($disk->get($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.addcslashes($filename, '"\\').'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
        // Already carrying it: re-tapping must not re-run the automation.
        // syncWithoutDetaching is happily idempotent, the flow behind it is
        // not -- it would send the whole sequence a second time.
        $alreadyLabelled = $conversation->labels()->whereKey($label->id)->exists();

        $conversation->labels()->syncWithoutDetaching([$label->id]);

        if (! $alreadyLabelled) {
            // After the response, so a slow Graph API call never holds up the
            // redirect back to the thread.
            dispatch(function () use ($conversation, $label): void {
                app(FlowEngine::class)->handleLabelApplied($conversation, $label);
            })->afterResponse();
        }

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
