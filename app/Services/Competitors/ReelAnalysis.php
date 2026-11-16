<?php

namespace App\Services\Competitors;

use App\Models\CompetitorReel;
use App\Models\CompetitorBrainLog;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
     * One pass per reel. Gemini watches and listens, and answers in JSON:
     * the full script with timestamps (in the reel's own language, plus
     * English), the moments that earn likes and shares, what the comments
     * say, and the case-study write-up shown on the card.
     *
     * Instagram shows nobody a reel's shares, so share moments are Gemini's
     * read of what people would forward -- backed by how many of the latest
     * comments tag a friend, which is counted in PHP, not by the model.
     */
    public static function prompt(CompetitorReel $reel): string
    {
        $account = $reel->account;
        $avg = (int) ($account?->avg_views_30d ?? 0);
        $comments = collect($reel->comments ?? [])->take(30)
            ->map(fn ($c) => '- '.str_replace("\n", ' ', (string) $c['text']).(($c['likes'] ?? 0) ? " ({$c['likes']} likes)" : ''))->implode("\n");

        $facts = implode("\n", array_filter([
            'Account: @'.($account?->username ?? '?').($account?->followers_count ? ' ('.number_format($account->followers_count).' followers)' : ''),
            'Views: '.number_format((int) $reel->play_count).($avg ? ' (account average '.number_format($avg).', so '.round($reel->play_count / max(1, $avg), 1).'x)' : ''),
            'Likes: '.number_format((int) $reel->like_count).' ('.$reel->likeRate().' per 100 views) · Comments: '.number_format((int) $reel->comment_count),
            $reel->duration_seconds ? 'Length: '.$reel->duration_seconds.' s' : null,
            $reel->music ? 'Audio: '.(($reel->music['original'] ?? false) ? 'original audio' : trim(($reel->music['song'] ?? '').' — '.($reel->music['artist'] ?? ''))) : null,
            $reel->hashtags ? 'Hashtags: #'.implode(' #', array_slice($reel->hashtags, 0, 15)) : null,
            'Caption: '.Str::limit(str_replace("\n", ' ', (string) $reel->caption), 600),
        ]));

        return <<<PROMPT
        You are the research brain of an Indian video and social-media studio (Tamil Nadu, mostly Tamil and Tanglish content).
        Watch AND listen to this competitor's Instagram Reel. Our writers need its exact script and to understand where people like and share it.

        FACTS ABOUT THE REEL
        {$facts}

        LATEST COMMENTS
        {$comments}

        Answer ONLY with JSON in exactly this shape:
        {
          "language": "Tamil | Tanglish | English | ...",
          "transcript": [ { "at": "0:03", "said": "the exact words spoken, in the language spoken (Tamil in Tamil script)", "english": "English meaning", "on_screen": "on-screen text at that moment, exact, or empty" } ],
          "hook": "the first 1-3 seconds: what is seen and said, and why it stops the scroll",
          "format": "the reel's template in a few words, e.g. myth vs fact talking head",
          "topic": "what it is about, a few words",
          "like_moments": [ { "at": "0:12", "what": "what happens", "why": "why a viewer taps like here" } ],
          "share_moments": [ { "at": "0:20", "what": "what happens", "why": "why someone forwards it or tags a friend here, and to whom" } ],
          "comment_read": { "themes": ["what commenters talk about, most common first"], "quoted_lines": ["lines from the reel people repeat or react to"], "questions": ["what people ask"] },
          "summary": "two sentences: what the reel is and the single biggest reason it got views",
          "breakdown": "Markdown case study: ## Hook, ## Format, ## Pacing and editing, ## Emotional arc and payoff, ## Call to action, ## Why it beat the average, ## Copy this / Don't copy this"
        }

        Rules: every spoken line goes in "transcript" (all of it, not a summary); write timestamps as m:ss; if nobody speaks, describe the text and music instead.
        Ground like and share moments in the video and the comments, never generic advice. Write English except "said".
        PROMPT;
    }

    /**
     * Gemini's JSON, defensively: lists stay lists, text stays text.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw);
        $json = json_decode((string) $raw, true);
        if (! is_array($json)) {
            throw new CompetitorAnalysisException('Gemini did not return a readable answer. Try again.', provider: 'gemini');
        }

        $list = fn ($v, array $keys) => collect(is_array($v) ? $v : [])->filter(fn ($x) => is_array($x))
            ->map(fn ($row) => collect($keys)->mapWithKeys(fn ($k) => [$k => trim((string) ($row[$k] ?? ''))])->all())
            ->values()->all();
        $text = fn ($v, int $max) => Str::limit(trim(is_string($v) ? $v : ''), $max, '');

        $read = [
            'language' => $text($json['language'] ?? null, 40) ?: null,
            'transcript' => $list($json['transcript'] ?? [], ['at', 'said', 'english', 'on_screen']),
            'hook' => $text($json['hook'] ?? null, 1000) ?: null,
            'format' => $text($json['format'] ?? null, 160) ?: null,
            'topic' => $text($json['topic'] ?? null, 160) ?: null,
            'like_moments' => $list($json['like_moments'] ?? [], ['at', 'what', 'why']),
            'share_moments' => $list($json['share_moments'] ?? [], ['at', 'what', 'why']),
            'comment_read' => collect(['themes', 'quoted_lines', 'questions'])->mapWithKeys(fn ($k) => [$k => collect($json['comment_read'][$k] ?? [])->filter(fn ($x) => is_string($x))->map(fn ($x) => Str::limit(trim($x), 300))->values()->all()])->all(),
            'summary' => $text($json['summary'] ?? null, 1000) ?: null,
            'breakdown' => trim(is_string($json['breakdown'] ?? null) ? $json['breakdown'] : ''),
        ];

        if ($read['transcript'] === [] && $read['breakdown'] === '') {
            throw new CompetitorAnalysisException('Gemini returned an empty answer. Try again.', provider: 'gemini');
        }
        if ($read['breakdown'] === '') {
            $read['breakdown'] = '## Summary'."\n\n".($read['summary'] ?? '');
        }

        return $read;
    }

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
            $raw = $analyzer->ask($uploaded['uri'], $uploaded['mimeType'], self::prompt($reel), [
                'responseMimeType' => 'application/json',
                'temperature' => 0.3,
            ]);
            $read = self::parse($raw);

            $analysis = CompetitorReelAnalysis::updateOrCreate(
                ['competitor_reel_id' => $reel->id],
                [
                    'breakdown' => $read['breakdown'],
                    'language' => $read['language'],
                    'transcript' => $read['transcript'],
                    'hook' => $read['hook'],
                    'format' => $read['format'],
                    'topic' => $read['topic'],
                    'like_moments' => $read['like_moments'],
                    'share_moments' => $read['share_moments'],
                    'comment_read' => $read['comment_read'] + ['tags_a_friend' => $reel->friendTags(), 'comments_read' => count($reel->comments ?? [])],
                    'summary' => $read['summary'],
                    'gemini_model' => $analyzer->model,
                    'analyzed_at' => now(),
                    'learned_at' => null,
                ],
            );

            CompetitorBrainLog::write('scripted', 'Took the script of @'.$reel->account?->username.'\'s reel ('.number_format((int) $reel->play_count).' views): '.Str::limit((string) $read['summary'], 220),
                ['competitor_account_id' => $reel->competitor_account_id, 'competitor_reel_id' => $reel->id]);

            return $analysis;
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
