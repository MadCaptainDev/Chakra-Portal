<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\ClientBriefRequest;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientBriefAnswer;
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

            return $brief->load('answers');
        });
    }

}
