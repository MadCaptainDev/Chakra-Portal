<?php

namespace App\Console\Commands;

use App\Models\CompetitorAccount;
use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use App\Services\Competitors\CompetitorAnalysisException;
use App\Services\Competitors\GeminiVideoAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Have Gemini watch and break down the top-performing not-yet-analyzed
 * competitor reels, shot by shot.
 *
 *     php artisan competitors:analyze
 *     php artisan competitors:analyze --account=3
 *     php artisan competitors:analyze --limit=10
 *
 * CLI-only, deliberately never called from a web request: uploading a video
 * to Gemini and waiting for it to leave PROCESSING can legitimately take up
 * to two minutes PER VIDEO (GeminiVideoAnalyzer::MAX_WAIT_SECONDS), and this
 * host has no queue worker to hand that wait off to. See
 * SyncInstagramInsights for the same "no cron, run this by hand" shape this
 * command follows.
 *
 * One reel's failure does not stop the batch -- each is wrapped in its own
 * try/catch, exactly like SyncInstagramInsights treats each account.
 */
class AnalyzeCompetitorReels extends Command
{
    protected $signature = 'competitors:analyze
        {--account= : Only analyze reels belonging to this competitor account id}
        {--limit=5 : How many reels to analyze in this run}';

    protected $description = 'Have Gemini break down the top-performing un-analyzed competitor reels';

    /**
     * The reference tool leaves this prompt to whoever is running it; kept
     * here as a sensible default rather than hardcoded upstream, since a
     * studio adapting this for their own market will want their own eye.
     * The "#"-headed shape matters -- GeminiVideoAnalyzer::analyzeVideo()
     * strips everything before the first "#" in the response, the same
     * behaviour the reference tool's own analyzeVideo() has.
     */
    private const DEFAULT_ANALYSIS_PROMPT = <<<'PROMPT'
        Watch this Instagram Reel and break it down shot by shot. For each shot,
        note the timestamp, what is shown, the camera framing/movement, any
        on-screen text, and what makes it work. Then summarise, under a "#
        Breakdown" heading, the hook (first 1-2 seconds), the pacing, the
        emotional arc, and the single biggest reason this video likely performed
        well. Be specific and concrete, not generic.
        PROMPT;

    public function handle(): int
    {
        $settings = CompetitorSetting::current();

        if (! $settings->hasGemini()) {
            $this->error('No Gemini API key set — configure it under Setup → Competitor Analysis first.');

            return self::FAILURE;
        }

        $reels = CompetitorReel::query()
            ->notAnalyzed()
            ->with('account')
            ->when($this->option('account'), fn ($q, $id) => $q->where('competitor_account_id', $id))
            ->highestViewsFirst()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($reels->isEmpty()) {
            $this->info('Nothing to analyze — every tracked reel already has a breakdown.');

            return self::SUCCESS;
        }

        $analyzer = GeminiVideoAnalyzer::make();
        $failures = 0;

        foreach ($reels as $reel) {
            $label = ($reel->account?->handle() ?? 'unknown account').' — '.number_format((int) $reel->play_count).' views';
            $this->line("→ {$label}");

            if (! $reel->video_url) {
                $this->comment('  Skipped — no video URL was scraped for this reel.');

                continue;
            }

            try {
                $video = Http::timeout(60)->get($reel->video_url);

                if ($video->failed()) {
                    throw new CompetitorAnalysisException(
                        "Could not download the video file (HTTP {$video->status()}).", provider: 'download',
                    );
                }

                $uploaded = $analyzer->uploadVideo($video->body(), $video->header('Content-Type') ?: 'video/mp4');
                $breakdown = $analyzer->analyzeVideo($uploaded['uri'], $uploaded['mimeType'], self::DEFAULT_ANALYSIS_PROMPT);

                CompetitorReelAnalysis::updateOrCreate(
                    ['competitor_reel_id' => $reel->id],
                    ['breakdown' => $breakdown, 'gemini_model' => $settings->gemini_model, 'analyzed_at' => now()],
                );

                $this->info('  OK — breakdown stored ('.strlen($breakdown).' chars).');
            } catch (CompetitorAnalysisException $e) {
                $failures++;
                $this->error('  FAILED — '.$e->getMessage());
            } catch (Throwable $e) {
                // Deliberately not $e->getMessage() to terminal history: an
                // unexpected exception here may carry a request body, and
                // that body can contain the API key.
                $failures++;
                report($e);
                $this->error('  FAILED — unexpected error, logged.');
            }
        }

        $this->newLine();
        $this->line(sprintf('%d analyzed, %d failed.', $reels->count() - $failures, $failures));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
