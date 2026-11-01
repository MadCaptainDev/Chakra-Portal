<?php

namespace App\Http\Controllers;

use App\Models\ReelTranscript;
use App\Services\Ai\ReelTranscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Reel Transcriber: paste a reel link or upload the video, get what it says
 * (ReelTranscriber does the work; the transcribe_reel MCP tool is the same).
 */
class ReelTranscriptController extends Controller
{
    public function index(): View
    {
        return view('reel-transcripts.index', [
            'recent' => ReelTranscript::with('socialMediaItem.socialAccount.client:id,name', 'createdBy:id,name')->latest('id')->limit(30)->get(),
            'maxMb' => intdiv(ReelTranscriber::MAX_UPLOAD_KB, 1024),
        ]);
    }

    public function store(Request $request, ReelTranscriber $transcriber): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['nullable', 'required_without:video', 'url', 'max:500'],
            'username' => ['nullable', 'string', 'max:80'],
            'video' => ['nullable', 'required_without:url', 'file', 'max:'.ReelTranscriber::MAX_UPLOAD_KB, 'mimetypes:video/mp4,video/quicktime,video/webm,audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg,audio/webm'],
        ], ['url.required_without' => 'Paste a reel link or choose a video file.']);

        try {
            $transcript = $request->hasFile('video')
                ? $transcriber->fromFile($request->file('video'), $request->user())
                : $transcriber->fromUrl($data['url'], $request->user(), username: $data['username'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reel-transcripts.show', $transcript);
    }

    /** Transcribe a link again (after the Gemini key is set, say). An uploaded file has to be uploaded again. */
    public function again(Request $request, ReelTranscript $reelTranscript, ReelTranscriber $transcriber): RedirectResponse
    {
        abort_unless($reelTranscript->source_url, 404);
        try {
            $fresh = $transcriber->fromUrl($reelTranscript->source_url, $request->user(), refresh: true);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('reel-transcripts.show', $fresh)->with('status', 'Transcribed again.');
    }

    public function show(ReelTranscript $reelTranscript): View
    {
        return view('reel-transcripts.show', ['t' => $reelTranscript->load('socialMediaItem.socialAccount.client:id,name', 'socialMediaItem.insights', 'createdBy:id,name')]);
    }
}
