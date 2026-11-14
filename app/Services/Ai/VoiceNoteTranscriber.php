<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\ReviewComment;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A reviewer's voice note in, words out: what was said in Tanglish (how the
 * team types), and a one-line English version. The Tanglish becomes the
 * comment's body -- and so the correction's title on the editor's card.
 *
 * Gemini hears Tamil and Tanglish properly; the note is short WAV, sent
 * inline. Inside the monthly Gemini cap (GeminiBudget, feature "review").
 * Never throws: a note that cannot be heard stays a playable voice note.
 */
class VoiceNoteTranscriber
{
    private const GEMINI = 'https://generativelanguage.googleapis.com';

    private const PROMPT = 'This is a short voice note from a reviewer at a video agency in Tamil Nadu, giving feedback on an edit of a reel '
        .'(cuts, text, colour, audio, music, pacing). It is usually Tamil, Tanglish or English. Return JSON with: '
        .'tanglish (exactly what is said, Tamil written in English letters as people type on WhatsApp, English words kept as English; '
        .'if it is all English, the English as said); '
        .'english (a short natural English version of the instruction). '
        .'Do not add, guess or improve anything that was not said; write [unclear] for a word you cannot make out. '
        .'If nobody speaks, return empty strings.';

    public function transcribe(ReviewComment $comment): void
    {
        $path = $comment->voicePath();
        $key = AiSetting::current()->hasGeminiKey() ? AiSetting::current()->gemini_api_key : null;

        if (! $key || ! $path || ! is_file($path)) {
            $comment->forceFill(['voice_state' => 'failed'])->save();

            return;
        }

        try {
            $audio = ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode((string) file_get_contents($path))]];
            $response = null;
            foreach (ReelTranscriber::GEMINI_MODELS as $i => $model) {
                $response = GeminiBudget::during('review', fn () => Http::withHeaders(['x-goog-api-key' => $key])->timeout(90)
                    ->post(self::GEMINI.'/v1beta/models/'.$model.':generateContent', [
                        'system_instruction' => ['parts' => [['text' => self::PROMPT]]],
                        'contents' => [['parts' => [$audio, ['text' => 'Return JSON only.']]]],
                        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.1],
                    ]), $comment->user);
                $retry = in_array($response->status(), [404, 429, 500, 503], true);
                if (! $retry || $i === count(ReelTranscriber::GEMINI_MODELS) - 1) {
                    break;
                }
            }

            $out = json_decode((string) data_get($response?->json(), 'candidates.0.content.parts.0.text', ''), true);
            $tanglish = trim((string) ($out['tanglish'] ?? ''));

            if (! $response?->successful() || $tanglish === '') {
                $comment->forceFill(['voice_state' => 'failed'])->save();

                return;
            }

            $comment->forceFill([
                'body' => mb_substr($tanglish, 0, 2000),
                'voice_english' => filled($out['english'] ?? null) ? mb_substr(trim((string) $out['english']), 0, 1000) : null,
                'voice_state' => 'done',
            ])->save();

            // Already on the editor's card as a correction: give it the words.
            if ($comment->correction_todo_id && ($fix = $comment->correction)) {
                $fix->forceFill(['title' => \Illuminate\Support\Str::limit(($comment->stamp() ? $comment->stamp().' — ' : '').'🎤 '.$tanglish, 250)])->save();
            }
        } catch (Throwable $e) {
            report($e);
            $comment->forceFill(['voice_state' => 'failed'])->save();
        }
    }
}
