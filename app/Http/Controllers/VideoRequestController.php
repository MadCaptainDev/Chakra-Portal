<?php

namespace App\Http\Controllers;

use App\Models\VideoRequest;
use App\Support\VideoRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** An admin approving or refusing an editor's cancel / planner move. See App\Support\VideoRequests. */
class VideoRequestController extends Controller
{
    public function approve(Request $request, VideoRequest $videoRequest): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($videoRequest->status === 'pending', 422, 'Already decided.');
        $done = VideoRequests::approve($videoRequest, $request->user());

        return back()->with($done->status === 'approved' ? 'status' : 'error', $done->status === 'approved'
            ? 'Approved: '.$done->summary().' · '.$done->title
            : 'Approved, but it did not go through: '.$done->decision_note);
    }

    public function refuse(Request $request, VideoRequest $videoRequest): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($videoRequest->status === 'pending', 422, 'Already decided.');
        VideoRequests::refuse($videoRequest, $request->user(), $request->string('note')->limit(250)->toString() ?: null);

        return back()->with('status', 'Refused. '.$videoRequest->user?->name.' has been told.');
    }
}
