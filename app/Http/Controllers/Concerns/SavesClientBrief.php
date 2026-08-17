<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\ClientBriefRequest;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientBriefAnswer;
use App\Models\TaxonomyTerm;
use App\Support\BrandBrief;
use Illuminate\Support\Facades\DB;

/**
 * Writing a brief, shared by the two ways in.
 *
 * A signed-in client fills it from the portal; a client with no login fills
 * the same form through a one-time link. The rules about what a brief is do
 * not change with the door used to reach it, so they live here rather than
 * being written twice and drifting apart.
 *
 * Access is emphatically NOT here. Each controller proves who it is talking to
 * in its own way -- a session for one, a token for the other -- and neither
 * delegates that.
 */
trait SavesClientBrief
{
    /**
     * Write the answers, and nothing else that the form could have claimed.
     *
     * One transaction: a half-saved brief would show a progress count that
     * does not match what is stored, and the client would have no way to tell.
     */
    protected function saveBrief(Client $client, ClientBriefRequest $request): ClientBrief
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
    protected function taxonomyOptions(ClientBrief $brief): array
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
