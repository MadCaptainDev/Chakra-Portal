<?php

namespace App\Http\Controllers;

use App\Models\BriefQuestion;
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
    public function index(): View
    {
        BrandBrief::flush();

        return view('brief-questions.index', [
            'steps' => BrandBrief::STEPS,
            'custom' => BriefQuestion::query()->ordered()->get()->groupBy('step_id'),
            'types' => BriefQuestion::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $question = BriefQuestion::create($this->validated($request) + [
            'created_by_id' => $request->user()->id,
        ]);

        BrandBrief::flush();

        return back()->with('status', "Added “{$question->label}”. Every client's brief asks it from now on.");
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
            'step_id' => ['required', Rule::in(array_column(BrandBrief::STEPS, 'id'))],
            'type' => ['required', Rule::in(array_keys(BriefQuestion::TYPES))],
            'label' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:255'],
            'required' => ['nullable', 'boolean'],
            'multi' => ['nullable', 'boolean'],
            // One option per line, which is the only editor anybody wants for
            // a list of eight words.
            'options' => ['nullable', 'string', 'max:2000'],
        ]);

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
