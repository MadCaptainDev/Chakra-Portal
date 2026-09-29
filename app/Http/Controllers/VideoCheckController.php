<?php

namespace App\Http\Controllers;

use App\Models\VideoCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Video Checker: a page that analyses an export in the browser
 * (resources/js/video-check.js) and a history of what was found.
 *
 * Nothing here ever receives the video. Hosting video is not worth it for
 * this studio, and a quality check does not need it -- the browser can read
 * the file's size, shape, length and sound on the device it is already on.
 */
class VideoCheckController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Admins see the whole team's checks; everyone else their own.
        $checks = VideoCheck::query()
            ->with('user:id,name')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->limit(30)
            ->get();

        return view('video-check.index', [
            'checks' => $checks,
            'presets' => VideoCheck::PRESETS,
            'showOwner' => $user->isAdmin(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:0'],
            'preset' => ['required', Rule::in(array_keys(VideoCheck::PRESETS))],
            'verdict' => ['required', Rule::in(VideoCheck::VERDICTS)],
            'results' => ['required', 'array', 'max:30'],
            'results.*.status' => ['required', Rule::in(['pass', 'warn', 'fail', 'info'])],
            'results.*.title' => ['required', 'string', 'max:120'],
            'results.*.detail' => ['nullable', 'string', 'max:500'],
        ]);

        $check = VideoCheck::create($validated + ['user_id' => $request->user()->id]);

        return response()->json(['id' => $check->id], 201);
    }
}
