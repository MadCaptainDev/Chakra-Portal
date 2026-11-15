<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CompetitorAccount;
use App\Models\CompetitorReel;
use App\Services\Competitors\ReelAnalysis;
use Illuminate\Support\Str;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use App\Models\GeneratedConcept;
use App\Services\Competitors\CompetitorAnalysisException;
use App\Services\Competitors\ConceptGenerator;
use App\Services\Competitors\CompetitorScraper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Tracking competitor Instagram accounts, scraping their reels, and
 * generating concepts from the ones that outperform their own average.
 *
 * Module-gated ('competitors' in App\Support\Permission::MODULES) rather
 * than admin-only like the settings screen -- content strategy is
 * plausibly a producer's job, even though connecting the paid API keys
 * that make it possible is not.
 */
class CompetitorAccountController extends Controller
{
    public function index(): View
    {
        return view('competitors.index', [
            'accounts' => CompetitorAccount::query()
                ->withCount('reels')
                ->with('client')
                ->orderBy('username')
                ->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'settings' => CompetitorSetting::current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A leading @ is the natural way to type a handle. Stripped BEFORE
        // validation, not after -- the regex below has no @ in its
        // character class, so validating the raw input first would reject
        // exactly the input this comment says the form should accept.
        $request->merge(['username' => ltrim((string) $request->input('username'), '@')]);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._]+$/'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        CompetitorAccount::firstOrCreate(
            ['platform' => CompetitorAccount::PLATFORM_INSTAGRAM, 'username' => $validated['username']],
            ['client_id' => $validated['client_id'] ?? null, 'notes' => $validated['notes'] ?? null],
        );

        $returnTo = $request->input('return_to');

        return redirect()
            ->to(is_string($returnTo) && $returnTo !== '' ? $returnTo : route('competitors.index'))
            ->with('status', "Tracking @{$validated['username']}.");
    }

    public function destroy(CompetitorAccount $competitor): RedirectResponse
    {
        $handle = $competitor->handle();
        $competitor->delete();

        return redirect()->route('competitors.index')->with('status', "Stopped tracking {$handle}.");
    }

    /**
     * Three bounded Apify calls -- fast enough to run inside this request,
     * matching InstagramSyncRunner's own precedent for what belongs
     * in-request versus CLI-only (see GeminiVideoAnalyzer for the step that
     * is NOT safe here).
     */
    public function scrape(CompetitorAccount $competitor): RedirectResponse
    {
        if (! CompetitorSetting::current()->hasApify()) {
            return back()->with('status', 'Add an Apify token under Setup → Competitor Analysis first.');
        }

        try {
            $result = CompetitorScraper::make()->scrape($competitor);
        } catch (CompetitorAnalysisException $e) {
            return back()->with('status', 'Scrape failed: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('status', 'Scrape failed unexpectedly — see the log.');
        }

        return back()->with('status', "Scraped {$competitor->handle()} — {$result['reels']} reel(s), {$result['newReels']} new.");
    }

    public function show(CompetitorAccount $competitor): View
    {
        return view('competitors.show', [
            'competitor' => $competitor->load('client'),
            'reels' => $competitor->reels()->with(['account', 'analysis.concepts.client'])->highestViewsFirst()->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'settings' => CompetitorSetting::current(),
        ]);
    }

    /**
     * Gemini watches one reel. It takes a minute or two, so it runs after the
     * response (defer) and the page shows "Analysing…" until it lands.
     */
    public function analyze(CompetitorReel $reel): RedirectResponse
    {
        if (! CompetitorSetting::current()->hasGemini()) {
            return back()->with('status', 'Add a Gemini key on the AI settings page first.');
        }
        if (! ReelAnalysis::claim($reel)) {
            return back()->with('status', 'Already analysing that reel — it takes a minute or two.');
        }

        defer(fn () => $this->analyzeNow([$reel]));

        return back()->with('status', 'Gemini is watching the reel. Its breakdown appears here in a minute or two.');
    }

    /** The account's top un-analysed reels, best first, up to five per click. */
    public function analyzeTop(CompetitorAccount $competitor): RedirectResponse
    {
        if (! CompetitorSetting::current()->hasGemini()) {
            return back()->with('status', 'Add a Gemini key on the AI settings page first.');
        }

        $reels = $competitor->reels()->notAnalyzed()->withFreshVideo()->highestViewsFirst()->limit(5)->get()
            ->filter(fn (CompetitorReel $r) => ReelAnalysis::claim($r))->values();
        if ($reels->isEmpty()) {
            return back()->with('status', 'Nothing to analyse with a fresh video link. Press Scrape now first.');
        }

        defer(fn () => $this->analyzeNow($reels->all()));

        return back()->with('status', 'Gemini is watching the top '.$reels->count().' '.Str::plural('reel', $reels->count()).'. Breakdowns appear one by one over the next few minutes.');
    }

    /** @param  array<int, CompetitorReel>  $reels  each already claimed */
    private function analyzeNow(array $reels): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        foreach ($reels as $reel) {
            try {
                ReelAnalysis::run($reel);
            } catch (CompetitorAnalysisException) {
                // Kept on the card by ReelAnalysis::lastError().
            } catch (Throwable $e) {
                report($e);
            } finally {
                ReelAnalysis::release($reel);
            }
        }
    }

    /** One completion call (Claude if its key is set, else Gemini) -- quick enough to wait for. */
    public function generateConcepts(Request $request, CompetitorReelAnalysis $analysis): RedirectResponse
    {
        $settings = CompetitorSetting::current();

        if (! $settings->canWriteConcepts()) {
            return back()->with('status', 'Add a Gemini key on the AI settings page first.');
        }

        $validated = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'brand_prompt' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $conceptText = ConceptGenerator::make()->generateConcepts($analysis->breakdown, $validated['brand_prompt']);
        } catch (CompetitorAnalysisException $e) {
            return back()->withInput()->with('status', 'Could not generate concepts: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('status', 'Concept generation failed unexpectedly — see the log.');
        }

        GeneratedConcept::create([
            'competitor_reel_analysis_id' => $analysis->id,
            'client_id' => $validated['client_id'] ?? null,
            'brand_prompt' => $validated['brand_prompt'],
            'concept_text' => $conceptText,
            'generated_by_id' => $request->user()->id,
            'generated_at' => now(),
        ]);

        return back()->with('status', 'Concepts generated.');
    }
}
