<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Services\CheckRounds;
use App\Services\InboxDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Comment & Message Checker screen: Instagram DMs and comments, account by account.
 *
 * One page for both sides of the duty. The person doing it taps tiles; an
 * admin opening the same page watches them turn green, because the page
 * polls state() while it is on screen. Every write answers with the fresh
 * board, so the screen never shows a guess for longer than one round trip.
 */
class InboxDeskController extends Controller
{
    public function __construct(private readonly InboxDesk $desk) {}

    public function index(Request $request): View
    {
        abort_if($request->user()->isClient(), 403);

        return view('inbox-desk.index', [
            'board' => $this->desk->board($request->user()),
        ]);
    }

    /** The walkthrough: a self-playing "how this screen works". */
    public function guide(Request $request): View
    {
        abort_if($request->user()->isClient(), 403);

        return view('inbox-desk.guide');
    }

    public function state(Request $request): JsonResponse
    {
        abort_if($request->user()->isClient(), 403);

        return response()->json($this->desk->board($request->user()));
    }

    public function check(Request $request, int $occurrence): JsonResponse
    {
        abort_if($request->user()->isClient(), 403);

        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $result = $this->desk->check($request->user(), $occurrence, $data['count'] ?? null);

        return response()->json([
            'message' => $result['done'] > 0 ? 'Checked.' : 'Somebody already checked that one.',
            'board' => $this->desk->board($request->user()),
        ]);
    }

    /** Start the morning or evening round's clock (it stops on the round's last tick). */
    public function startRound(Request $request, CheckRounds $rounds): JsonResponse
    {
        abort_if($request->user()->isClient(), 403);
        $data = $request->validate([
            'routine_id' => ['required', 'integer'],
            'round' => ['required', 'in:'.implode(',', array_keys(CheckRounds::ROUNDS))],
        ]);
        $routine = Routine::query()->where('subject_type', Routine::SUBJECT_ACCOUNTS)->with('checkpoints')->find($data['routine_id']);
        abort_unless($routine && in_array($data['round'], $rounds->roundsOf($routine), true), 404);
        abort_unless($request->user()->isAdmin() || $routine->users()->where('users.id', $request->user()->id)->exists(), 404);

        $round = $rounds->start($request->user(), $routine, $data['round']);

        return response()->json([
            'message' => $round->wasRecentlyCreated ? CheckRounds::ROUNDS[$data['round']].' started.' : 'Already started at '.$round->started_at->format('g:i A').'.',
            'board' => $this->desk->board($request->user()),
        ]);
    }

    public function undo(Request $request, int $occurrence): JsonResponse
    {
        abort_if($request->user()->isClient(), 403);

        $this->desk->undo($request->user(), $occurrence);

        return response()->json([
            'message' => 'Undone.',
            'board' => $this->desk->board($request->user()),
        ]);
    }
}
