<?php

namespace App\Http\Controllers;

use App\Models\InstagramSyncJob;
use App\Services\Instagram\InstagramSyncQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use function Illuminate\Support\defer;

/** "Sync all Instagram": the button, and the live queue it fills. Admins only. */
class InstagramSyncController extends Controller
{
    public function index(): View
    {
        return view('instagram-sync.index', ['state' => $this->state()]);
    }

    public function store(Request $request, InstagramSyncQueue $queue): RedirectResponse
    {
        $queue->queueAll($request->user());
        // Start straight after this page answers; the scheduler carries on each minute.
        defer(fn () => $queue->work(50));

        return redirect()->route('instagram-sync.index');
    }

    public function status(): JsonResponse
    {
        return response()->json($this->state());
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $batch = InstagramSyncJob::query()->latest('id')->value('batch');
        $jobs = $batch ? InstagramSyncJob::query()->where('batch', $batch)->with('account.client')->orderBy('id')->get() : collect();

        return [
            'running' => $jobs->contains(fn ($j) => $j->isOpen()),
            'started' => $jobs->min('created_at')?->format('j M, g:i A'),
            'counts' => $jobs->countBy('status')->all() + ['total' => $jobs->count()],
            'jobs' => $jobs->map(fn (InstagramSyncJob $j) => [
                'id' => $j->id,
                'client' => $j->account?->client?->name ?? 'Account #'.$j->social_account_id,
                'client_id' => $j->account?->client_id,
                'handle' => $j->account?->username ? '@'.$j->account->username : null,
                'status' => $j->status,
                'message' => $j->message,
                'took' => $j->started_at && $j->finished_at ? max(1, (int) $j->started_at->diffInSeconds($j->finished_at)).'s' : null,
                'expires' => $j->account?->token_expires_at?->format('j M'),
            ])->values()->all(),
        ];
    }
}
