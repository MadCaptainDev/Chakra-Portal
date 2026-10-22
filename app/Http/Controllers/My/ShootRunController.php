<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootVideo;
use App\Services\ShootRun;
use App\Support\CrewShootRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * The on-location screen: one shoot, one phone, one video at a time.
 *
 * Gated on being crew for this shoot rather than on module:shoots -- four of
 * the studio's six employees hold no module permissions at all, and they are
 * exactly the people who stand behind the camera. Being named on the call
 * sheet is what earns you this screen; an admin gets it too, because they
 * carry everything.
 */
class ShootRunController extends Controller
{
    /**
     * The Shoot Updater's front door: every shoot this person is on that
     * is live, today, or in the next fortnight -- one tap from the sidebar
     * into the runner. Before this the runner was only linked from the
     * producer's shoot page, which most crew cannot open.
     *
     * An admin sees every shoot in that window, crewed or not, because they
     * cover for anybody.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $shoots = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('crew', fn ($c) => $c->where('user_id', $user->id))
                ->orWhere('started_by_id', $user->id)))
            ->where(fn ($q) => $q
                ->inProgress()
                ->orWhereBetween('starts_at', [now()->startOfDay(), now()->addDays(14)->endOfDay()]))
            ->with(['client', 'crew.user'])
            ->withCount('videos')
            ->orderBy('starts_at')
            ->get();

        $live = $shoots->filter(fn (Shoot $s) => $s->isInProgress());
        $today = $shoots->reject(fn (Shoot $s) => $s->isInProgress())
            ->filter(fn (Shoot $s) => $s->starts_at->isToday());

        // The one to get ready for: rolling now, else today's, else the next one.
        $next = $live->first()
            ?? $shoots->first(fn (Shoot $s) => ! $s->hasWrapped() && $s->starts_at->gte(today()))
            ?? $shoots->first();
        // Scripts for every shoot on the page, so each says what is ready to shoot.
        $scripts = $shoots->mapWithKeys(fn (Shoot $s) => [$s->id => $this->scripts($s)]);

        return view('my.shoots.index', [
            'next' => $next,
            'scripts' => $scripts,
            'live' => $live,
            'today' => $today,
            'upcoming' => $shoots->reject(fn (Shoot $s) => $live->contains($s) || $today->contains($s)),
            // Their own record; an admin, who sees every shoot above, sees everyone's.
            'past' => CrewShootRecord::shoots($user->isAdmin() ? null : $user),
            'person' => $user->isAdmin() ? null : $user,
        ]);
    }

    public function show(Shoot $shoot): View
    {
        $this->authoriseCrew($shoot);

        $shoot->load(['client', 'videos.recordedBy', 'videos.script', 'crew.user', 'notionShoot', 'reachedBy', 'departedBy']);

        return view('my.shoots.run', [
            'shoot' => $shoot,
            'scripts' => $this->scripts($shoot),
            'liveVersion' => $this->liveVersion($shoot),
            // Named so the screen can say which shoot is in the way, rather
            // than only that something is.
            'blockedBy' => ShootRun::runningFor(request()->user())
                ->where('shoots.id', '!=', $shoot->id)
                ->first(),
        ]);
    }

    /**
     * The shoot screen's live feed, polled every few seconds by each phone
     * on set -- this host cannot hold a websocket open. A cheap version
     * string is compared first; only when something moved (a script edited
     * in the portal or over MCP, a video saved by someone else on the crew,
     * the shoot wrapped) is the full list sent back.
     */
    public function live(Request $request, Shoot $shoot): JsonResponse
    {
        $this->authoriseCrew($shoot);

        $version = $this->liveVersion($shoot);
        if ($request->query('since') === $version) {
            return response()->json(['changed' => false, 'version' => $version]);
        }

        $shoot->load('videos.recordedBy');

        return response()->json([
            'changed' => true,
            'version' => $version,
            'wrapped' => $shoot->hasWrapped(),
            'scripts' => $this->scripts($shoot),
            'videos' => $shoot->videos->map(fn (ShootVideo $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'by' => $v->recordedBy?->name,
                'script_id' => $v->script_id,
            ])->values(),
        ]);
    }

    /** Changes whenever anything the shoot screen shows changes. */
    private function liveVersion(Shoot $shoot): string
    {
        $scripts = $shoot->client_id
            ? Script::query()->where('client_id', $shoot->client_id)
                ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS at')->first()
            : null;
        $sections = $shoot->client_id
            ? DB::table('script_sections')->join('scripts', 'scripts.id', '=', 'script_sections.script_id')
                ->where('scripts.client_id', $shoot->client_id)
                ->selectRaw('COUNT(*) AS n, MAX(script_sections.updated_at) AS at')->first()
            : null;
        // This shoot's videos, and any video shot from this client's scripts
        // (another shoot ticking one off), not every video in the studio.
        $videos = ShootVideo::query()
            ->where(fn ($q) => $q->where('shoot_id', $shoot->id)
                ->when($shoot->client_id, fn ($q) => $q->orWhereIn('script_id', Script::query()->select('id')->where('client_id', $shoot->client_id))))
            ->selectRaw('COUNT(*) AS n, MAX(id) AS last')->first();

        return md5(json_encode([
            $shoot->updated_at?->getTimestamp(),
            $scripts?->n, $scripts?->at, $sections?->n, $sections?->at,
            $videos?->n, $videos?->last,
        ]));
    }

    public function depart(Request $request, Shoot $shoot): RedirectResponse
    {
        $this->authoriseCrew($shoot);

        try {
            ShootRun::depart($shoot, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function reached(Request $request, Shoot $shoot): RedirectResponse
    {
        $this->authoriseCrew($shoot);

        try {
            ShootRun::reached($shoot, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function start(Request $request, Shoot $shoot): RedirectResponse
    {
        $this->authoriseCrew($shoot);

        try {
            ShootRun::start($shoot, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function storeVideo(Request $request, Shoot $shoot): RedirectResponse
    {
        $this->authoriseCrew($shoot);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            // 12 MB: a phone still, not footage. Footage never comes through
            // a form. Required: the still is how the editor finds the take,
            // and a title alone ("Product shot 3") rarely is.
            'photo' => ['required', 'image', 'max:12288'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Only one of this client's own scripts can be named.
            'script_id' => ['nullable', 'integer', Rule::exists('scripts', 'id')->where('client_id', $shoot->client_id ?? 0)],
            // Section timings from the reader, sent as JSON in one field.
            'section_log' => ['nullable', 'json', 'max:20000'],
        ]);

        $log = $this->sectionLog($data['section_log'] ?? null);

        try {
            ShootRun::recordVideo(
                shoot: $shoot,
                crewMember: $request->user(),
                name: $data['name'],
                photo: $request->file('photo'),
                notes: $data['notes'] ?? null,
                scriptId: isset($data['script_id']) ? (int) $data['script_id'] : null,
                sectionLog: isset($data['script_id']) ? $log : null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back()->with('status', 'saved');
    }

    public function finish(Request $request, Shoot $shoot): RedirectResponse
    {
        $this->authoriseCrew($shoot);

        try {
            ShootRun::finish($shoot, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('my.shoots.run', $shoot);
    }

    /**
     * The reader's per-section log, kept to the shape the screens read and
     * nothing else: heading, shot, takes, seconds (a day at most).
     *
     * @return list<array{heading: string, shot: bool, takes: int, seconds: int}>|null
     */
    private function sectionLog(?string $json): ?array
    {
        $rows = $json ? json_decode($json, true) : null;
        if (! is_array($rows)) {
            return null;
        }

        $clean = [];
        foreach (array_slice(array_values($rows), 0, 50) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clean[] = [
                'heading' => mb_substr(trim((string) ($row['heading'] ?? '')), 0, 255) ?: 'Section',
                'shot' => (bool) ($row['shot'] ?? false),
                'takes' => max(0, min(99, (int) ($row['takes'] ?? 0))),
                'seconds' => max(0, min(86400, (int) ($row['seconds'] ?? 0))),
            ];
        }

        return $clean ?: null;
    }

    /**
     * The client's scripts to read from on set: not shot yet first (on any
     * shoot), the ones the client has signed off before drafts, archived
     * ones never. Each carries its text so the reader opens with no request
     * -- signal on location is the thing that cannot be counted on.
     *
     * @return list<array<string, mixed>>
     */
    private function scripts(Shoot $shoot): array
    {
        if (! $shoot->client_id) {
            return [];
        }

        $shot = ShootVideo::query()->whereNotNull('script_id')
            ->selectRaw('script_id, MAX(shoot_id = ?) AS here', [$shoot->id])
            ->groupBy('script_id')
            ->pluck('here', 'script_id');

        $rank = [Script::STATUS_COMPLETED => 0, Script::STATUS_CLIENT_REVIEW => 1, Script::STATUS_READY => 2];

        return Script::query()
            ->where('client_id', $shoot->client_id)
            ->where('status', '!=', Script::STATUS_ARCHIVED)
            ->with('sections')
            ->get()
            ->sortBy(fn (Script $s) => [($shot->has($s->id) || $s->shot_at) ? 1 : 0, $rank[$s->status] ?? 3, $s->id])
            ->map(fn (Script $s) => [
                'id' => $s->id,
                'title' => $s->title,
                // Changes when the words change, so a phone can tell which script was edited.
                'rev' => md5($s->title.'|'.$s->sections->map(fn ($sec) => $sec->heading.$sec->body)->implode('|')),
                'status' => $s->status === Script::STATUS_COMPLETED ? 'Approved' : $s->statusLabel(),
                // A linked video says where; shot_at covers ones shot before videos were linked.
                'shot' => $shot->has($s->id) ? ((int) $shot[$s->id] ? 'here' : 'before') : ($s->shot_at ? 'before' : null),
                'sections' => $this->parts($s),
            ])
            ->filter(fn ($s) => $s['sections'] !== [])
            ->values()
            ->all();
    }

    /**
     * What the crew time on set, one Start/Stop each. A script written in
     * sections keeps them. One written as a single block -- most are, pasted
     * in whole -- is cut at its paragraphs, a short one-line paragraph ("First uh, Sage
     * Green...") staying with the paragraph it introduces, so each part is
     * one thing to say on camera.
     *
     * @return list<array{heading: string, body: string}>
     */
    private function parts(Script $script): array
    {
        $sections = $script->sections->reject->isEmpty()->values();

        if ($sections->count() !== 1) {
            return $sections->map(fn ($sec) => ['heading' => $sec->heading, 'body' => $sec->body])->all();
        }

        preg_match_all('#<p\b[^>]*>.*?</p>#s', $sections[0]->body, $m);
        $paragraphs = array_values(array_filter($m[0], fn ($p) => trim(strip_tags($p)) !== ''));

        if (count($paragraphs) < 2) {
            return [['heading' => $sections[0]->heading, 'body' => $sections[0]->body]];
        }

        $parts = [];
        $carry = '';
        foreach ($paragraphs as $i => $p) {
            $carry .= $p;
            $words = count(preg_split('/\s+/u', trim(strip_tags($p)), -1, PREG_SPLIT_NO_EMPTY));
            if ($words <= 10 && ! str_contains($p, '<br') && $i < count($paragraphs) - 1) {
                continue;
            }
            $parts[] = $carry;
            $carry = '';
        }

        return array_map(fn ($body, $i) => ['heading' => 'Part '.($i + 1).' of '.count($parts), 'body' => $body], $parts, array_keys($parts));
    }

    /**
     * 404 rather than 403 for someone not on this shoot: which shoots exist
     * is not their business either.
     */
    /**
     * The live link for one of this shoot's scripts, so the crew can hand it
     * to the talent or client on set. Crew only, and only a script of the
     * shoot's own client.
     */
    public function shareScript(Shoot $shoot, Script $script): JsonResponse
    {
        $this->authoriseCrew($shoot);
        abort_unless($shoot->client_id && $script->client_id === $shoot->client_id, 404);

        return response()->json(['url' => $script->shareLink()]);
    }

    private function authoriseCrew(Shoot $shoot): void
    {
        $user = request()->user();

        $isCrew = $shoot->crew()->where('user_id', $user->id)->exists()
            || $shoot->started_by_id === $user->id
            || $user->isAdmin();

        abort_unless($isCrew, 404);
    }
}
