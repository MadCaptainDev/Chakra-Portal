<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Concerns\SavesClientBrief;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientBriefRequest;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientBriefAnswer;
use App\Support\BrandBrief;
use Illuminate\Http\JsonResponse;
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
    use SavesClientBrief;

    public function edit(Request $request): View
    {
        $client = $this->client($request);
        BrandBrief::forClient($client);

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
        ]);
    }

    public function update(ClientBriefRequest $request): RedirectResponse|JsonResponse
    {
        $client = $this->client($request);
        BrandBrief::forClient($client);
        $brief = $this->saveBrief($client, $request);

        // Autosave posts in the background and wants an answer, not a page.
        // The counts ride along so the progress line stays honest mid-typing.
        if ($request->wantsJson()) {
            return response()->json([
                'saved_at' => now()->toIso8601String(),
                'answered' => $brief->requiredAnswered(),
                'total' => $brief->requiredTotal(),
                'complete' => $brief->isComplete(),
            ]);
        }

        return redirect()
            ->route('client.brief')
            ->with('status', $brief->isComplete()
                ? 'Saved. Everything we need is answered — press Submit when you are ready.'
                : 'Saved. Come back whenever you like.');
    }

    public function submit(ClientBriefRequest $request): RedirectResponse
    {
        $client = $this->client($request);
        BrandBrief::forClient($client);
        $brief = $this->saveBrief($client, $request);

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

}
