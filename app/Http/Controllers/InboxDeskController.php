<?php

namespace App\Http\Controllers;

use App\Services\InboxDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Inbox Check screen: Instagram DMs and comments, account by account.
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
