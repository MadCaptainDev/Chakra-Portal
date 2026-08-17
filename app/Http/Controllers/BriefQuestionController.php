<?php

namespace App\Http\Controllers;

use App\Models\BriefQuestion;
use App\Models\Client;
use App\Support\BrandBrief;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The brand brief's question list, as the studio edits it.
 *
 * One screen for every client: a question added here is asked of everybody who
 * opens a brief from that moment, and of nobody retrospectively -- briefs
 * already sent in keep the answers they were given and simply do not have this
 * one.
 *
 * Admin-only, alongside Settings and the PDF template. This is not per-client
 * work and it is not delegated a piece at a time: it changes what every client
 * of the studio is asked.
 */
class BriefQuestionController extends Controller
{
    public function index(Request $request): View
    {
        /*
         * Two modes, chosen by the picker at the top, because they are two
         * different jobs and mixing them is how a question meant for one
         * client ends up on everybody's brief:
         *
         *   no client  -- the shared set, grouped by the seven steps
         *   a client   -- only that client's own questions, in their own group
         *
         * A client selected here is asked the shared questions too; the screen
         * says so rather than listing them again read-only.
         */
        $client = $request->integer('client')
            ? Client::find($request->integer('client'))
            : null;

        BrandBrief::forClient($client);

        return view('brief-questions.index', [
            'steps' => BrandBrief::STEPS,
            'client' => $client,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'shared' => BriefQuestion::query()->shared()->ordered()->get()->groupBy('step_id'),
            'mine' => $client
                ? BriefQuestion::query()->where('client_id', $client->id)->ordered()->get()
                : collect(),
            'types' => BriefQuestion::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $question = BriefQuestion::create($this->validated($request) + [
            'created_by_id' => $request->user()->id,
        ]);

        BrandBrief::flush();

        return back()->with('status', $question->isPrivate()
            ? "Added “{$question->label}” for {$question->client->name}. Only their brief asks it."
            : "Added “{$question->label}”. Every client's brief asks it from now on.");
    }

    public function update(Request $request, BriefQuestion $briefQuestion): RedirectResponse
    {
        /*
         * The label may be reworded freely -- `key` was fixed at creation and
         * is what answers hang off, so a typo fix keeps every answer attached.
         */
        $briefQuestion->update($this->validated($request));

        BrandBrief::flush();

        return back()->with('status', 'Question updated.');
    }

    /**
     * Archive rather than delete.
     *
     * Clients have answered this. Hard-deleting the question would orphan
     * their answers and lose the studio's own record of what was said, to tidy
     * a list. Archiving hides it from new briefs and leaves the answers
     * readable on the client's page.
     */
    public function destroy(BriefQuestion $briefQuestion): RedirectResponse
    {
        $briefQuestion->update(['is_active' => false]);

        BrandBrief::flush();

        return back()->with('status',
            "Archived “{$briefQuestion->label}”. New briefs stop asking it; answers already given are kept.");
    }

    public function restore(BriefQuestion $briefQuestion): RedirectResponse
    {
        $briefQuestion->update(['is_active' => true]);

        BrandBrief::flush();

        return back()->with('status', 'Question restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            // Null means every client. A real id makes it that client's alone.
            'client_id' => ['nullable', 'exists:clients,id'],
            'step_id' => ['nullable', Rule::in(array_column(BrandBrief::STEPS, 'id'))],
            'group_label' => ['nullable', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys(BriefQuestion::TYPES))],
            'label' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:255'],
            'required' => ['nullable', 'boolean'],
            'multi' => ['nullable', 'boolean'],
            // One option per line, which is the only editor anybody wants for
            // a list of eight words.
            'options' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * A client's questions always land in that client's own group, never
         * in one of the seven shared steps. Deriving the step here rather than
         * trusting a posted one is what stops a private question being filed
         * into a shared group where every other client would then see it.
         */
        if ($clientId = $validated['client_id'] ?? null) {
            $validated['step_id'] = BriefQuestion::stepIdFor((int) $clientId);
            // ?? as well as ?: -- a nullable field that was not posted at all
            // is absent from the validated set, not null in it.
            $validated['group_label'] = ($validated['group_label'] ?? null) ?: 'Your craft';
        } else {
            $validated['group_label'] = null;

            if (blank($validated['step_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'step_id' => 'Choose which group this question belongs to.',
                ]);
            }
        }

        $isList = in_array($validated['type'], [BrandBrief::TYPE_CHIPS, BrandBrief::TYPE_CHECKS], true);

        $options = collect(preg_split('/\r\n|\r|\n/', (string) ($validated['options'] ?? '')))
            ->map(fn (string $line) => trim($line))
            ->filter()
            // Duplicates would make two chips that look identical and store
            // the same value, so the second could never be deselected.
            ->unique()
            ->values()
            ->all();

        if ($isList && $options === []) {
            throw ValidationException::withMessages([
                'options' => 'A list question needs at least one option — put one per line.',
            ]);
        }

        $validated['options'] = $isList ? $options : null;
        $validated['required'] = (bool) ($validated['required'] ?? false);
        $validated['multi'] = $isList && (bool) ($validated['multi'] ?? false);

        return $validated;
    }
}
