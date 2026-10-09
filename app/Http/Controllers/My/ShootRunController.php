<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\ShootRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('my.shoots.index', [
            'live' => $live,
            'today' => $today,
            'upcoming' => $shoots->reject(fn (Shoot $s) => $live->contains($s) || $today->contains($s)),
        ]);
    }

    public function show(Shoot $shoot): View
    {
        $this->authoriseCrew($shoot);

        $shoot->load(['client', 'videos.recordedBy', 'crew.user', 'notionShoot', 'reachedBy']);

        return view('my.shoots.run', [
            'shoot' => $shoot,
            // Named so the screen can say which shoot is in the way, rather
            // than only that something is.
            'blockedBy' => ShootRun::runningFor(request()->user())
                ->where('shoots.id', '!=', $shoot->id)
                ->first(),
        ]);
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
        ]);

        try {
            ShootRun::recordVideo(
                shoot: $shoot,
                crewMember: $request->user(),
                name: $data['name'],
                photo: $request->file('photo'),
                notes: $data['notes'] ?? null,
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
     * 404 rather than 403 for someone not on this shoot: which shoots exist
     * is not their business either.
     */
    private function authoriseCrew(Shoot $shoot): void
    {
        $user = request()->user();

        $isCrew = $shoot->crew()->where('user_id', $user->id)->exists()
            || $shoot->started_by_id === $user->id
            || $user->isAdmin();

        abort_unless($isCrew, 404);
    }
}
