<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientBriefRequest;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientBriefAnswer;
use App\Models\TaxonomyTerm;
use App\Support\BrandBrief;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The client answering the studio's discovery questions.
 *
 * Whose brief this is comes from ResolvesClient and nowhere else -- there is
 * no {client} in any of these paths, so there is no id to tamper with.
 *
 * Two write endpoints against one form: update() saves whatever has been
 * filled so far and demands nothing, submit() insists on the required set.
 * See ClientBriefRequest for why that distinction is drawn from the route name
 * rather than a posted field.
 */
class BriefController extends Controller
{
    use ResolvesClient;

    public function edit(Request $request): View
    {
        $client = $this->client($request);

        /*
         * make(), not firstOrCreate(). Opening the form must not create a row:
         * "not started" and "opened once and abandoned" are the same thing to
         * everyone who reads this, and a row that appears on a GET would also
         * appear when a staff member merely looks at the client record.
         */
        $brief = $client->brief()->with('answers')->first() ?: $client->brief()->make();

        return view('client.brief', [
            'client' => $client,
            'brief' => $brief,
            'answers' => $brief->exists ? $brief->keyedAnswers() : collect(),
            'options' => $this->taxonomyOptions($brief),
        ]);
    }

    public function update(ClientBriefRequest $request): RedirectResponse
    {
        $client = $this->client($request);
        $brief = $this->save($client, $request);

        return redirect()
            ->route('client.brief')
            ->with('status', $brief->isComplete()
                ? 'Saved. Everything we need is answered — press Submit when you are ready.'
                : 'Saved. Come back whenever you like.');
    }

    public function submit(ClientBriefRequest $request): RedirectResponse
    {
        $client = $this->client($request);
        $brief = $this->save($client, $request);

        /*
         * submitted_at records the FIRST time this came in and never moves. A
         * client may keep editing afterwards -- brands change -- and the studio
         * needs to tell "answered in March" from "changed last week", which is
         * what the per-answer timestamps are for.
         */
        $brief->forceFill([
            'status' => ClientBrief::STATUS_SUBMITTED,
            'submitted_at' => $brief->submitted_at ?? now(),
            'submitted_by_id' => $request->user()->id,
        ])->save();

        return redirect()
            ->route('client.dashboard')
            ->with('status', 'Thank you — that is everything we need. Your writers have it now.');
    }

    /**
     * Write the answers, and nothing else that the form could have claimed.
     *
     * One transaction: a half-saved brief would show a progress count that
     * does not match what is stored, and the client would have no way to tell.
     */
    private function save(Client $client, ClientBriefRequest $request): ClientBrief
    {
        $answers = $request->answers();

        return DB::transaction(function () use ($client, $answers) {
            $brief = $client->brief()->firstOrCreate([]);

            foreach ($answers as $key => $value) {
                $multi = BrandBrief::isMulti($key);

                /*
                 * updateOrCreate against the unique on (brief, key), rather
                 * than clearing and reinserting. Reinserting would reset every
                 * answer's updated_at, and those timestamps are the only
                 * record that the client changed their mind after we started.
                 */
                ClientBriefAnswer::updateOrCreate(
                    ['client_brief_id' => $brief->id, 'question_key' => $key],
                    [
                        'value' => $multi ? null : $value,
                        'value_json' => $multi ? $value : null,
                    ],
                );
            }

            if ($brief->status === ClientBrief::STATUS_NOT_STARTED) {
                $brief->update(['status' => ClientBrief::STATUS_IN_PROGRESS]);
            }

            /*
             * The sector answer is also a field on the client record, and the
             * client is the authority on it. Writing it back means the studio's
             * own reporting picks it up without anybody retyping it.
             */
            if (array_key_exists('industry_id', $answers)) {
                $client->update(['industry_id' => $answers['industry_id']]);
            }

            return $brief->load('answers');
        });
    }

    /**
     * The picker contents, one query per list rather than one per question.
     *
     * `keep` is passed the client's current answer so a term the studio has
     * since retired stays selectable on the form that already uses it --
     * otherwise saving an unrelated section would silently drop it.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function taxonomyOptions(ClientBrief $brief): array
    {
        $options = [];

        foreach (BrandBrief::QUESTIONS as $key => $question) {
            $type = BrandBrief::taxonomyFor($key);

            if (! $type) {
                continue;
            }

            $current = $brief->exists ? $brief->answer($key) : null;
            $keep = is_array($current) ? null : (int) $current;

            $options[$key] = TaxonomyTerm::options($type, $keep ?: null);

            // A multi-select cannot pass a single id to keep(), so any retired
            // terms it already holds are unioned back in explicitly.
            if (is_array($current) && $current !== []) {
                $missing = TaxonomyTerm::query()
                    ->whereIn('id', $current)
                    ->whereNotIn('id', $options[$key]->pluck('id'))
                    ->get();

                $options[$key] = $options[$key]->concat($missing);
            }
        }

        return $options;
    }
}
