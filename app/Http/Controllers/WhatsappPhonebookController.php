<?php

namespace App\Http\Controllers;

use App\Models\WhatsappPhonebook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Named contact lists a campaign sends to.
 *
 * No `show` -- a phonebook has nothing of its own worth a detail page; its
 * member list is just the Contacts screen filtered by it (see
 * WhatsappContactController::index()).
 */
class WhatsappPhonebookController extends Controller
{
    public function index(): View
    {
        return view('whatsapp-crm.phonebooks.index', [
            'phonebooks' => WhatsappPhonebook::withCount('contacts')->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('whatsapp-crm.phonebooks.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $phonebook = WhatsappPhonebook::create($data);

        return redirect()->route('whatsapp-crm.phonebooks.index')
            ->with('status', "\"{$phonebook->name}\" created.");
    }

    public function edit(WhatsappPhonebook $phonebook): View
    {
        return view('whatsapp-crm.phonebooks.edit', compact('phonebook'));
    }

    public function update(Request $request, WhatsappPhonebook $phonebook): RedirectResponse
    {
        $data = $this->validated($request);

        $phonebook->update($data);

        return redirect()->route('whatsapp-crm.phonebooks.index')
            ->with('status', "\"{$phonebook->name}\" updated.");
    }

    /**
     * Deleting a phonebook only removes the pivot rows (cascadeOnDelete on
     * the migration) -- the contacts themselves stay in the CRM, just no
     * longer grouped under this list.
     */
    public function destroy(WhatsappPhonebook $phonebook): RedirectResponse
    {
        $name = $phonebook->name;
        $phonebook->delete();

        return redirect()->route('whatsapp-crm.phonebooks.index')
            ->with('status', "\"{$name}\" deleted. Its contacts remain in the CRM.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
