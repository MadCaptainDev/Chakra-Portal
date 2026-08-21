<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Notifications\TodoSentBack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * A manager checking a finished to-do.
 *
 * The one thing the tracker can write. Everything else there is read-only:
 * a manager gives a verdict on work, the way they decide a timesheet day, and
 * never edits the work itself.
 *
 * Who may is a per-row question -- is this person one of that person's managers
 * -- which no middleware can answer, so it is asked here and aborts. An
 * employee with several managers can be signed off by any of them, whichever is
 * not on a shoot that day.
 */
class TodoReviewController extends Controller
{
    public function store(Request $request, Todo $todo): RedirectResponse
    {
        abort_unless($request->user()->manages($todo->user), 403);

        $data = $request->validate([
            'review_state' => ['required', Rule::in(array_keys(Todo::REVIEW_STATES))],
            // Accepting needs no reason; sending work back always does.
            'review_note' => [
                Rule::requiredIf($request->input('review_state') === Todo::REVIEW_REJECTED),
                'nullable', 'string', 'max:1000',
            ],
        ]);

        // A stale form is somebody's second tab, not an attack.
        if ($todo->isOpen() && $data['review_state'] === Todo::REVIEW_APPROVED) {
            return back()->with('status', 'That to-do is not finished yet, so there is nothing to check.');
        }

        $todo->review($data['review_state'], $request->user(), $data['review_note'] ?? null);

        if ($todo->wasSentBack()) {
            try {
                Notification::send($todo->user, new TodoSentBack($todo));
            } catch (Throwable $e) {
                Log::error('To-do sent-back push failed.', ['todo_id' => $todo->id, 'error' => $e->getMessage()]);
            }
        }

        return back()->with('status', $todo->wasSentBack()
            ? "Sent back to {$todo->user->name}."
            : 'Checked off.');
    }
}
