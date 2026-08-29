<?php

namespace App\Http\Controllers;

use App\Models\WhatsappQuickReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Saved replies someone on the team can drop into a conversation without
 * retyping it.
 *
 * No `show` -- a quick reply has nothing of its own worth a detail page; the
 * index row already carries everything there is to see.
 */
class WhatsappQuickReplyController extends Controller
{
    public function index(): View
    {
        return view('whatsapp-crm.quick-replies.index', [
            'quickReplies' => WhatsappQuickReply::orderBy('title')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('whatsapp-crm.quick-replies.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by_id'] = $request->user()->id;

        $quickReply = WhatsappQuickReply::create($data);

        return redirect()->route('whatsapp-crm.quick-replies.index')
            ->with('status', "\"{$quickReply->title}\" created.");
    }

    public function edit(WhatsappQuickReply $quickReply): View
    {
        return view('whatsapp-crm.quick-replies.edit', compact('quickReply'));
    }

    public function update(Request $request, WhatsappQuickReply $quickReply): RedirectResponse
    {
        $data = $this->validated($request);

        $quickReply->update($data);

        return redirect()->route('whatsapp-crm.quick-replies.index')
            ->with('status', "\"{$quickReply->title}\" updated.");
    }

    public function destroy(WhatsappQuickReply $quickReply): RedirectResponse
    {
        $title = $quickReply->title;
        $quickReply->delete();

        return redirect()->route('whatsapp-crm.quick-replies.index')
            ->with('status', "\"{$title}\" deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:4096'],
        ]);
    }
}
