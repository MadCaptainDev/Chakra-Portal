<?php

namespace App\Services\Competitors;

use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Gemini watches one competitor reel and writes the shot-by-shot breakdown.
 *
 * Shared by the competitors:analyze command and the page's "Analyse" buttons,
 * which run it after the response (defer) because one reel can take a
 * minute or two: download, upload to Gemini, wait for PROCESSING, ask.
 * While it runs the reel is marked in the cache (analysing()), so the page
 * can say so and a second click does not start a second copy.
 */
class ReelAnalysis
{
    /**
     * The "#"-headed shape matters -- GeminiVideoAnalyzer::analyzeVideo()
     * strips everything before the first "#" in the response.
     */
    public const PROMPT = <<<'PROMPT'
        You are a senior short-form video strategist for an Indian video and social-media studio.
        Watch this Instagram Reel from a competitor and explain, concretely, why it got views.

        # Shot by shot
        For each shot: the timestamp, what is shown, framing and camera movement, any on-screen
        text (quote it) and what the voice or audio says.

        # Breakdown
        - Hook (first 1-2 seconds): exactly what stops the scroll.
        - Format: the reel's template in one line (e.g. "myth vs fact talking head", "before/after reveal").
        - Pacing and editing: cut rhythm, length, text style, music or trending audio.
        - Emotional arc and the payoff at the end.
        - Call to action, if any.
        - The single biggest reason this reel likely beat the account's average.
        - What to copy, and what NOT to copy, if we made our own version.

        Be specific to this video, never generic. Answer in English.
        PROMPT;

    private const LOCK_MINUTES = 10;

    public static function analysing(CompetitorReel $reel): bool
    {
        return Cache::has(self::key($reel));
    }

    /** Claim the reel for one run; false when a run is already going. */
    public static function claim(CompetitorReel $reel): bool
    {
        return Cache::add(self::key($reel), now()->toIso8601String(), now()->addMinutes(self::LOCK_MINUTES));
    }

    public static function release(CompetitorReel $reel): void
    {
        Cache::forget(self::key($reel));
    }

    /** The last failure for this reel, shown on its card until the next try. */
    public static function lastError(CompetitorReel $reel): ?string
    {
        return Cache::get(self::key($reel).':error');
    }

    /**
     * Run the whole analysis for one reel and store it.
     *
     * @throws CompetitorAnalysisException with a message fit to show a person
     */
    public static function run(CompetitorReel $reel): CompetitorReelAnalysis
    {
        Cache::forget(self::key($reel).':error');

        try {
            if (! $reel->video_url) {
                throw new CompetitorAnalysisException('No video file was scraped for this reel.', provider: 'download');
            }

            $video = Http::timeout(60)->get($reel->video_url);
            if ($video->failed()) {
                // Instagram's video links are signed and expire after a few days.
                throw new CompetitorAnalysisException(
                    "Could not download the video (HTTP {$video->status()}). The link has probably expired — press Scrape now, then analyse again.",
                    provider: 'download',
                );
            }

            $analyzer = GeminiVideoAnalyzer::make();
            $uploaded = $analyzer->uploadVideo($video->body(), $video->header('Content-Type') ?: 'video/mp4');
            $breakdown = $analyzer->analyzeVideo($uploaded['uri'], $uploaded['mimeType'], self::PROMPT);

            if (trim($breakdown) === '') {
                throw new CompetitorAnalysisException('Gemini returned an empty breakdown. Try again.', provider: 'gemini');
            }

            return CompetitorReelAnalysis::updateOrCreate(
                ['competitor_reel_id' => $reel->id],
                ['breakdown' => $breakdown, 'gemini_model' => $analyzer->model, 'analyzed_at' => now()],
            );
        } catch (\App\Services\Ai\GeminiBudgetExceeded $e) {
            self::remember($reel, 'This month\'s Gemini limit is used up. Raise it on the AI settings page, or wait for next month.');
            throw new CompetitorAnalysisException('This month\'s Gemini limit is used up.', provider: 'gemini');
        } catch (CompetitorAnalysisException $e) {
            self::remember($reel, $e->getMessage());
            throw $e;
        }
    }

    private static function remember(CompetitorReel $reel, string $message): void
    {
        Cache::put(self::key($reel).':error', \Illuminate\Support\Str::limit($message, 300), now()->addDay());
    }

    private static function key(CompetitorReel $reel): string
    {
        return 'competitor-analysing:'.$reel->id;
    }
}
