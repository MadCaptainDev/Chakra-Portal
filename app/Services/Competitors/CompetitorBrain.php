<?php

namespace App\Services\Competitors;

use App\Models\CompetitorAccount;
use App\Models\CompetitorBrainLog;
use App\Models\CompetitorBrainNote;
use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use App\Models\User;
use App\Services\Ai\GeminiBudget;
use App\Services\Ai\GeminiBudgetExceeded;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The competitor brain: a memory of what makes competitors' reels get views,
 * likes and shares, that learns one reel at a time.
 *
 * learnFrom(): after a reel's script is taken (ReelAnalysis), Gemini reads
 * that reel next to everything the brain already believes and says which
 * notes it confirms (+1 strength), which it contradicts (-1; retired at 0)
 * and what is new. Every change is written to competitor_brain_log, so the
 * brain can always say how it came to believe something.
 *
 * ask(): answer a person's question from the notes and every reel's script
 * summary -- and log the question and answer too.
 *
 * Text-only Gemini calls (cheap), inside the monthly cap (tag "competitor").
 */
class CompetitorBrain
{
    /** How many notes Gemini sees when learning; the strongest first. */
    private const NOTES_IN_VIEW = 60;

    public static function learnFrom(CompetitorReelAnalysis $analysis): array
    {
        $reel = $analysis->reel()->with('account')->first();
        $account = $reel->account;
        $notes = CompetitorBrainNote::query()->active()->for($account?->id)
            ->orderByDesc('strength')->limit(self::NOTES_IN_VIEW)->get();

        $answer = self::ask_json(self::learnPrompt($reel, $analysis, $notes));

        $done = ['confirmed' => 0, 'contradicted' => 0, 'learned' => 0, 'retired' => 0];
        $links = ['competitor_account_id' => $account?->id, 'competitor_reel_id' => $reel->id];

        foreach ((array) ($answer['confirm'] ?? []) as $c) {
            $note = $notes->firstWhere('id', (int) (is_array($c) ? ($c['id'] ?? 0) : $c));
            if (! $note) {
                continue;
            }
            $note->forceFill([
                'strength' => $note->strength + 1,
                'evidence' => array_values(array_unique([...($note->evidence ?? []), $reel->id])),
                'last_confirmed_at' => now(),
            ])->save();
            CompetitorBrainLog::write('confirmed', $note->insight, $links + ['competitor_brain_note_id' => $note->id], is_array($c) ? ($c['why'] ?? null) : null);
            $done['confirmed']++;
        }

        foreach ((array) ($answer['contradict'] ?? []) as $c) {
            $note = $notes->firstWhere('id', (int) ($c['id'] ?? 0));
            if (! $note) {
                continue;
            }
            $note->strength--;
            if ($note->strength <= 0) {
                $note->status = 'retired';
                $done['retired']++;
            }
            $note->save();
            CompetitorBrainLog::write($note->status === 'retired' ? 'retired' : 'contradicted', $note->insight, $links + ['competitor_brain_note_id' => $note->id], $c['why'] ?? null);
            $done['contradicted']++;
        }

        foreach (array_slice((array) ($answer['new'] ?? []), 0, 6) as $n) {
            $insight = trim((string) ($n['insight'] ?? ''));
            $category = array_key_exists($n['category'] ?? '', CompetitorBrainNote::CATEGORIES) ? $n['category'] : 'style';
            if ($insight === '') {
                continue;
            }
            $note = CompetitorBrainNote::create([
                'competitor_account_id' => ($n['scope'] ?? 'account') === 'all' ? null : $account?->id,
                'category' => $category,
                'insight' => Str::limit($insight, 600),
                'evidence' => [$reel->id],
                'strength' => 1,
                'last_confirmed_at' => now(),
            ]);
            CompetitorBrainLog::write('learned', $note->insight, $links + ['competitor_brain_note_id' => $note->id], $n['why'] ?? null);
            $done['learned']++;
        }

        $analysis->forceFill(['learned_at' => now()])->save();

        return $done;
    }

    /** A person's question, answered from the brain. Logged. */
    public static function ask(string $question, ?CompetitorAccount $account, ?User $by): string
    {
        $notes = CompetitorBrainNote::query()->active()->for($account?->id)->orderByDesc('strength')->limit(120)->get();
        $reels = CompetitorReel::query()->with(['account', 'analysis'])
            ->whereHas('analysis', fn ($q) => $q->whereNotNull('transcript'))
            ->when($account, fn ($q) => $q->where('competitor_account_id', $account->id))
            ->highestViewsFirst()->limit(40)->get();

        $prompt = "You are the competitor research brain of an Indian video and social-media studio (Tamil Nadu).\n"
            ."Answer the team's question from what you have learned below. Be concrete: name reels by account and views,\n"
            ."quote hooks and lines. If the evidence is thin, say so. Write short Markdown.\n\n"
            ."QUESTION\n{$question}\n\n"
            ."WHAT YOU HAVE LEARNED (strength = how many reels back it)\n".self::notesText($notes)."\n\n"
            ."REELS YOU HAVE STUDIED\n".$reels->map(fn (CompetitorReel $r) => self::reelLine($r))->implode("\n");

        $answer = trim(self::ask_text($prompt));
        CompetitorBrainLog::write('asked', $question, ['competitor_account_id' => $account?->id, 'user_id' => $by?->id], $answer);

        return $answer;
    }

    private static function learnPrompt(CompetitorReel $reel, CompetitorReelAnalysis $a, Collection $notes): string
    {
        $avg = max(1, (int) ($reel->account?->avg_views_30d ?? 0));
        $moments = fn (?array $rows) => collect($rows ?? [])->map(fn ($m) => "  - {$m['at']} {$m['what']} — {$m['why']}")->implode("\n");
        $script = collect($a->transcript ?? [])->map(fn ($l) => "  {$l['at']} ".($l['english'] ?: $l['said']).($l['on_screen'] ? " [text: {$l['on_screen']}]" : ''))->implode("\n");

        return "You keep the long-term memory of a studio's competitor research. A new reel has been studied.\n"
            ."Compare it with what you already believe and update the memory.\n\n"
            ."THE REEL: @{$reel->account?->username}, ".number_format((int) $reel->play_count).' views ('.round($reel->play_count / $avg, 1)."x the account average), "
            .$reel->likeRate().' likes per 100 views, '.$reel->friendTags()." of the latest comments tag a friend.\n"
            ."Format: {$a->format}. Topic: {$a->topic}.\nHook: {$a->hook}\nSummary: {$a->summary}\n"
            ."Like moments:\n".$moments($a->like_moments)."\nShare moments:\n".$moments($a->share_moments)."\n"
            .'Comment themes: '.implode('; ', $a->comment_read['themes'] ?? [])."\n"
            ."Script (English):\n".Str::limit($script, 4000)."\n\n"
            ."WHAT YOU ALREADY BELIEVE (id, category, strength, scope, note)\n".self::notesText($notes)."\n\n"
            ."Rules: a reel far above its account average is evidence FOR its choices; one below average is evidence AGAINST.\n"
            ."Only add a note that is specific and useful to a script writer (no generic advice). Do not repeat an existing note: confirm it instead.\n"
            ."Use scope \"all\" only for something that would hold for any Tamil creator, else \"account\".\n"
            .'Categories: '.implode(', ', array_keys(CompetitorBrainNote::CATEGORIES)).".\n\n"
            ."Answer ONLY with JSON: {\"confirm\": [{\"id\": 3, \"why\": \"...\"}], \"contradict\": [{\"id\": 5, \"why\": \"...\"}], "
            ."\"new\": [{\"category\": \"hook\", \"scope\": \"account\", \"insight\": \"...\", \"why\": \"...\"}]}";
    }

    private static function notesText(Collection $notes): string
    {
        if ($notes->isEmpty()) {
            return '(nothing yet)';
        }

        return $notes->map(fn (CompetitorBrainNote $n) => "#{$n->id} [{$n->category}] strength {$n->strength}, "
            .($n->competitor_account_id ? '@'.($n->account?->username ?? $n->competitor_account_id) : 'all').": {$n->insight}")->implode("\n");
    }

    public static function reelLine(CompetitorReel $r): string
    {
        $a = $r->analysis;

        return '- @'.$r->account?->username.' · '.number_format((int) $r->play_count).' views · '.$r->likeRate().' likes/100 · '
            .$r->friendTags().' friend tags · '.$a?->format.' · '.$a?->topic.' · hook: '.Str::limit((string) $a?->hook, 200).' · '.Str::limit((string) $a?->summary, 250);
    }

    /** @return array<string, mixed> */
    private static function ask_json(string $prompt): array
    {
        $text = self::ask_text($prompt, true);
        $json = json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);

        if (! is_array($json)) {
            throw new CompetitorAnalysisException('The brain could not read Gemini\'s answer. Try again.', provider: 'gemini');
        }

        return $json;
    }

    private static function ask_text(string $prompt, bool $json = false): string
    {
        $settings = CompetitorSetting::current();
        $key = (string) $settings->geminiKey();
        if ($key === '') {
            throw new CompetitorAnalysisException('Add a Gemini key on the AI settings page first.', provider: 'gemini');
        }

        $response = null;
        foreach ($settings->geminiModels() as $model) {
            try {
                $response = GeminiBudget::during('competitor', fn () => Http::withHeaders(['x-goog-api-key' => $key])
                    ->asJson()->connectTimeout(15)->timeout(150)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['temperature' => 0.3] + ($json ? ['responseMimeType' => 'application/json'] : []),
                    ]));
            } catch (GeminiBudgetExceeded) {
                throw new CompetitorAnalysisException('This month\'s Gemini limit is used up. Raise it on the AI settings page.', provider: 'gemini');
            } catch (ConnectionException) {
                continue; // busy: next model
            }
            if (! in_array($response->status(), [404, 429, 500, 503], true)) {
                break;
            }
        }

        if (! $response || $response->failed()) {
            throw new CompetitorAnalysisException('Gemini did not answer ('.($response?->status() ?? 'timed out').'). Try again in a minute.', provider: 'gemini');
        }

        return collect((array) $response->json('candidates.0.content.parts', []))
            ->reject(fn ($p) => ! empty($p['thought']))->pluck('text')->filter()->implode('');
    }
}
