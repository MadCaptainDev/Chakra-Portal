<?php

namespace App\Http\Controllers;

use App\Models\WhatsappCampaign;
use App\Models\WhatsappPhonebook;
use App\Services\WhatsappTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Broadcasts: one approved Meta template, sent to everyone in one phonebook.
 *
 * No edit/update -- once a campaign has logs sitting under it (created the
 * moment store() runs), rewriting its template or phonebook out from under
 * those logs would leave them describing a send that never happened. Cancel
 * and create a new one instead.
 */
class WhatsappCampaignController extends Controller
{
    public function index(): View
    {
        return view('whatsapp-crm.campaigns.index', [
            // progress() is called per row directly in the view -- fine at
            // this scale (one broadcast list, paginated 20 at a time), and
            // keeps the counts exactly as fresh as the page load rather than
            // as fresh as whenever this query ran.
            'campaigns' => WhatsappCampaign::with('phonebook')->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        $templates = array_map(
            fn (array $template) => $template + ['placeholder_count' => $this->placeholderCount($template)],
            WhatsappTemplateService::make()->list()
        );

        return view('whatsapp-crm.campaigns.create', [
            'templates' => $templates,
            'phonebooks' => WhatsappPhonebook::withCount('contacts')->orderBy('name')->get(),
            'initialMapping' => $this->initialMapping(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'meta_template_name' => ['required', 'string', 'max:255'],
            'meta_template_language' => ['required', 'string', 'max:32'],
            'phonebook_id' => ['required', 'integer', 'exists:whatsapp_phonebooks,id'],
            'variable_mapping' => ['nullable', 'array'],
            'variable_mapping.*' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $phonebook = WhatsappPhonebook::findOrFail($data['phonebook_id']);

        // No scheduled_at means "send as soon as possible" -- scheduled now
        // rather than left in draft, so the very next whatsapp:dispatch-
        // campaigns tick (at most a minute away) picks it up without anyone
        // having to come back and press a second button.
        $scheduledAt = $data['scheduled_at'] ?? null;

        $campaign = WhatsappCampaign::create([
            'name' => $data['name'],
            'meta_template_name' => $data['meta_template_name'],
            'meta_template_language' => $data['meta_template_language'],
            'phonebook_id' => $phonebook->id,
            'variable_mapping' => array_values($data['variable_mapping'] ?? []),
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt ? Carbon::parse($scheduledAt) : now(),
            'created_by_id' => $request->user()->id,
        ]);

        $rows = $phonebook->contacts()->get()->map(fn ($contact) => [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            $campaign->logs()->insert($rows);
        }

        return redirect()->route('whatsapp-crm.campaigns.show', $campaign)
            ->with('status', "\"{$campaign->name}\" created for {$phonebook->contactsCount()} contact(s).");
    }

    public function show(WhatsappCampaign $campaign): View
    {
        return view('whatsapp-crm.campaigns.show', [
            'campaign' => $campaign->load('phonebook', 'createdBy'),
        ]);
    }

    /**
     * The progress endpoint show.blade.php's Alpine block polls -- status is
     * folded into progress()'s own counts so the client can decide when to
     * stop polling without a second request.
     */
    public function progress(WhatsappCampaign $campaign): JsonResponse
    {
        return response()->json($campaign->progress() + ['status' => $campaign->status]);
    }

    /**
     * Forces an unsent campaign to go out immediately, regardless of what its
     * scheduled_at said -- the next whatsapp:dispatch-campaigns tick treats
     * it exactly like any other campaign whose time has come.
     */
    public function sendNow(WhatsappCampaign $campaign): RedirectResponse
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return redirect()->route('whatsapp-crm.campaigns.show', $campaign)
                ->with('error', 'This campaign has already started sending.');
        }

        $campaign->update(['status' => 'scheduled', 'scheduled_at' => now()]);

        return redirect()->route('whatsapp-crm.campaigns.show', $campaign)
            ->with('status', 'Campaign will go out within a minute.');
    }

    /**
     * Stops a campaign before (or while) it sends. Logs already sent are left
     * exactly as they are -- cancelling stops new sends, it does not pretend
     * the ones that already went out never happened.
     */
    public function cancel(WhatsappCampaign $campaign): RedirectResponse
    {
        if ($campaign->status === 'completed') {
            return redirect()->route('whatsapp-crm.campaigns.show', $campaign)
                ->with('error', 'This campaign has already completed.');
        }

        $campaign->update(['status' => 'cancelled']);

        return redirect()->route('whatsapp-crm.campaigns.show', $campaign)
            ->with('status', 'Campaign cancelled.');
    }

    /**
     * Only a campaign that never sent anything (draft, scheduled but not yet
     * due, or cancelled before it started) can be removed -- its logs are
     * all still `pending`, so cascadeOnDelete on campaign_id loses nothing a
     * completed or in-flight campaign's logs would.
     */
    public function destroy(WhatsappCampaign $campaign): RedirectResponse
    {
        if (! in_array($campaign->status, ['draft', 'scheduled', 'cancelled'], true)) {
            return redirect()->route('whatsapp-crm.campaigns.index')
                ->with('error', 'A campaign that has already started sending cannot be deleted. Cancel it instead.');
        }

        $name = $campaign->name;
        $campaign->delete();

        return redirect()->route('whatsapp-crm.campaigns.index')
            ->with('status', "\"{$name}\" deleted.");
    }

    /**
     * How many {{1}}, {{2}} ... positional parameters this template's BODY
     * component actually has -- what the create form uses to know how many
     * variable-mapping rows to show for whichever template gets picked.
     *
     * @param  array<string, mixed>  $template
     */
    private function placeholderCount(array $template): int
    {
        $body = collect($template['components'] ?? [])
            ->first(fn ($component) => ($component['type'] ?? null) === 'BODY');

        if (! $body || ! is_string($body['text'] ?? null)) {
            return 0;
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body['text'], $matches);

        return $matches[1] === [] ? 0 : (int) max($matches[1]);
    }

    /**
     * Five {mode, value} rows for the create form's Alpine state, seeded from
     * a failed submission's old('variable_mapping') so a validation error
     * does not throw away what was already picked -- each raw string is
     * either a var1..var5 key (mode) or literal text (value).
     *
     * @return array<int, array{mode: string, value: string}>
     */
    private function initialMapping(): array
    {
        $old = (array) old('variable_mapping', []);

        return array_map(function (int $i) use ($old) {
            $entry = (string) ($old[$i] ?? '');

            return preg_match('/^var[1-5]$/', $entry)
                ? ['mode' => $entry, 'value' => '']
                : ['mode' => 'literal', 'value' => $entry];
        }, range(0, 4));
    }
}
