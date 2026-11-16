<?php

namespace App\Http\Controllers;

use App\Models\CompetitorAccount;
use App\Models\CompetitorBrainLog;
use App\Models\CompetitorBrainNote;
use App\Models\CompetitorReel;
use App\Services\Competitors\BrainQueue;
use App\Services\Competitors\CompetitorAnalysisException;
use App\Services\Competitors\CompetitorBrain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The competitor brain's own page: what it believes (notes by category,
 * strongest first), how it learned it (the log), what is still waiting, and
 * a box to ask it a question. See CompetitorBrain and BrainQueue.
 */
class CompetitorBrainController extends Controller
{
    public function show(Request $request): View
    {
        BrainQueue::nudge();
        $account = $request->filled('account') ? CompetitorAccount::query()->find($request->integer('account')) : null;

        return view('competitors.brain', [
            'account' => $account,
            'accounts' => CompetitorAccount::query()->orderBy('username')->get(),
            'notes' => CompetitorBrainNote::query()->active()->for($account?->id)->with('account')
                ->orderByDesc('strength')->orderByDesc('last_confirmed_at')->get()->groupBy('category'),
            'log' => CompetitorBrainLog::query()->with(['account', 'reel', 'user'])
                ->when($account, fn ($q) => $q->where(fn ($w) => $w->where('competitor_account_id', $account->id)->orWhereNull('competitor_account_id')))
                ->latest('id')->limit(80)->get(),
            'studied' => CompetitorReel::query()->whereHas('analysis', fn ($q) => $q->whereNotNull('transcript'))
                ->when($account, fn ($q) => $q->where('competitor_account_id', $account->id))->count(),
            'pending' => BrainQueue::pendingCount($account),
            'busy' => BrainQueue::busy(),
            'answer' => session('brain_answer'),
        ]);
    }

    /** Turn learning on for an account: every reel with a fresh link gets scripted and learned from. */
    public function learn(CompetitorAccount $competitor): RedirectResponse
    {
        $competitor->forceFill(['learning_requested_at' => now()])->save();
        $waiting = BrainQueue::pendingCount($competitor);
        BrainQueue::nudge();

        return back()->with('status', $waiting
            ? "The brain is learning from {$waiting} ".\Illuminate\Support\Str::plural('reel', $waiting).' of @'.$competitor->username.', one by one (a minute or two each). Keep this page open to keep it going.'
            : 'Nothing new to learn from @'.$competitor->username.'. Press Scrape now to fetch its latest reels first.');
    }

    public function ask(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'account' => ['nullable', 'integer', 'exists:competitor_accounts,id'],
        ]);

        try {
            $answer = CompetitorBrain::ask($data['question'], isset($data['account']) ? CompetitorAccount::find($data['account']) : null, $request->user());
        } catch (CompetitorAnalysisException $e) {
            return back()->withInput()->with('status', $e->getMessage());
        }

        return back()->withInput()->with('brain_answer', ['question' => $data['question'], 'answer' => $answer]);
    }

    /** A wrong or stale note: retire it by hand. Logged. */
    public function retire(Request $request, CompetitorBrainNote $note): RedirectResponse
    {
        $note->forceFill(['status' => 'retired'])->save();
        CompetitorBrainLog::write('retired', $note->insight, ['competitor_account_id' => $note->competitor_account_id, 'competitor_brain_note_id' => $note->id, 'user_id' => $request->user()->id], 'Retired by '.$request->user()->name);

        return back()->with('status', 'Note retired.');
    }
}
