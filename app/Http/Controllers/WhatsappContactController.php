<?php

namespace App\Http\Controllers;

use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use App\Services\WhatsappContactImporter;
use App\Services\WhatsappSender;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Every person a campaign or a quick reply can reach, plus the CSV door
 * (importForm()/import()) most contacts actually arrive through -- typing
 * one in by hand (store()) is the exception, not the normal path.
 */
class WhatsappContactController extends Controller
{
    public function index(Request $request): View
    {
        $phonebookId = $request->integer('phonebook_id') ?: null;

        $contacts = WhatsappContact::query()
            ->with('phonebooks')
            ->when($phonebookId, fn ($query) => $query->whereHas(
                'phonebooks',
                fn ($q) => $q->whereKey($phonebookId)
            ))
            ->orderByRaw('name is null, name')
            ->orderBy('phone')
            ->paginate(25)
            ->withQueryString();

        return view('whatsapp-crm.contacts.index', [
            'contacts' => $contacts,
            'phonebooks' => WhatsappPhonebook::orderBy('name')->get(),
            'selectedPhonebookId' => $phonebookId,
        ]);
    }

    /**
     * There is no separate detail page for a contact -- var1..var5 and its
     * phonebooks are exactly what the edit form already shows -- so `show`
     * exists to keep this a full resource route set without a second view
     * that would only ever repeat the edit screen.
     */
    public function show(WhatsappContact $contact): RedirectResponse
    {
        return redirect()->route('whatsapp-crm.contacts.edit', $contact);
    }

    public function create(): View
    {
        return view('whatsapp-crm.contacts.create', [
            'phonebooks' => WhatsappPhonebook::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $phonebookIds = $data['phonebooks'] ?? [];
        unset($data['phonebooks']);

        $contact = WhatsappContact::create($data);

        if ($phonebookIds !== []) {
            $contact->phonebooks()->sync($phonebookIds);
        }

        $label = $contact->name ?: $contact->phone;

        return redirect()->route('whatsapp-crm.contacts.index')
            ->with('status', "\"{$label}\" added.");
    }

    public function edit(WhatsappContact $contact): View
    {
        return view('whatsapp-crm.contacts.edit', [
            'contact' => $contact->load('phonebooks'),
            'phonebooks' => WhatsappPhonebook::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, WhatsappContact $contact): RedirectResponse
    {
        $data = $this->validated($request, $contact);
        $phonebookIds = $data['phonebooks'] ?? [];
        unset($data['phonebooks']);

        $contact->update($data);
        $contact->phonebooks()->sync($phonebookIds);

        $label = $contact->name ?: $contact->phone;

        return redirect()->route('whatsapp-crm.contacts.index')
            ->with('status', "\"{$label}\" updated.");
    }

    /**
     * A contact a campaign has already logged a send to cannot be removed --
     * whatsapp_campaign_logs.contact_id is restrictOnDelete() on purpose (see
     * its migration): the send history stays traceable to who it went to,
     * rather than silently orphaning or cascading it away. Caught here so
     * that shows up as a clear flash message instead of a raw 500.
     */
    public function destroy(WhatsappContact $contact): RedirectResponse
    {
        $label = $contact->name ?: $contact->phone;

        try {
            $contact->delete();
        } catch (QueryException) {
            return redirect()->route('whatsapp-crm.contacts.index')
                ->with('error', "\"{$label}\" has campaign history and cannot be deleted.");
        }

        return redirect()->route('whatsapp-crm.contacts.index')
            ->with('status', "\"{$label}\" deleted.");
    }

    public function importForm(): View
    {
        return view('whatsapp-crm.contacts.import', [
            'phonebooks' => WhatsappPhonebook::orderBy('name')->get(),
        ]);
    }

    /**
     * The bulk door. Runs the whole file through WhatsappContactImporter in
     * one pass and flashes its summary back to the same form -- there is
     * nowhere else to show imported/skipped/errors that makes sense once the
     * redirect has already happened.
     */
    public function import(Request $request, WhatsappContactImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'phonebook_id' => ['required', 'integer', 'exists:whatsapp_phonebooks,id'],
        ]);

        $phonebook = WhatsappPhonebook::findOrFail($validated['phonebook_id']);

        $result = $importer->import($request->file('file')->getRealPath(), $phonebook);

        return redirect()->route('whatsapp-crm.contacts.import.form')
            ->with('import_result', $result)
            ->with('status', "Imported {$result['imported']}, skipped {$result['skipped']} into \"{$phonebook->name}\".");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WhatsappContact $contact = null): array
    {
        // Normalised before the uniqueness check runs, not after -- otherwise
        // "+91 98765 43210" would pass validation against an existing
        // "919876543210" row and then collide the moment the model's own
        // mutator normalises it a second time on save.
        if ($request->filled('phone')) {
            $request->merge(['phone' => WhatsappSender::normalise((string) $request->input('phone'))]);
        }

        return $request->validate([
            'phone' => [
                'required', 'string', 'min:8',
                Rule::unique('whatsapp_contacts', 'phone')->ignore($contact?->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'var1' => ['nullable', 'string', 'max:255'],
            'var2' => ['nullable', 'string', 'max:255'],
            'var3' => ['nullable', 'string', 'max:255'],
            'var4' => ['nullable', 'string', 'max:255'],
            'var5' => ['nullable', 'string', 'max:255'],
            'phonebooks' => ['nullable', 'array'],
            'phonebooks.*' => ['integer', 'exists:whatsapp_phonebooks,id'],
        ]);
    }
}
