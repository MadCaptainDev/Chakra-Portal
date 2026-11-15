<?php

namespace App\Console\Commands;

use App\Models\CompetitorReel;
use App\Models\CompetitorSetting;
use App\Services\Competitors\CompetitorAnalysisException;
use App\Services\Competitors\ReelAnalysis;
use Illuminate\Console\Command;
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

    public function handle(): int
    {
        $settings = CompetitorSetting::current();

        if (! $settings->hasGemini()) {
            $this->error('No Gemini API key set — add one on the AI settings page first.');

            return self::FAILURE;
        }

        $reels = CompetitorReel::query()
            ->notAnalyzed()
            ->withFreshVideo()
            ->with('account')
            ->when($this->option('account'), fn ($q, $id) => $q->where('competitor_account_id', $id))
            ->highestViewsFirst()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($reels->isEmpty()) {
            $this->info('Nothing to analyze — every reel with a fresh video link has a breakdown. Scrape again for older reels.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($reels as $reel) {
            $label = ($reel->account?->handle() ?? 'unknown account').' — '.number_format((int) $reel->play_count).' views';
            $this->line("→ {$label}");

            if (! $reel->video_url) {
                $this->comment('  Skipped — no video URL was scraped for this reel.');

                continue;
            }

            if (! ReelAnalysis::claim($reel)) {
                $this->comment('  Skipped — the page is analysing it right now.');

                continue;
            }

            try {
                $analysis = ReelAnalysis::run($reel);

                $this->info('  OK — breakdown stored ('.strlen($analysis->breakdown).' chars).');
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
            } finally {
                ReelAnalysis::release($reel);
            }
        }

        $this->newLine();
        $this->line(sprintf('%d analyzed, %d failed.', $reels->count() - $failures, $failures));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
