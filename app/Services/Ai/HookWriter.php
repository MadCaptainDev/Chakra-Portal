<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Script;
use App\Models\ScriptHook;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Five stronger opening lines for a script -- before the shoot on the
 * script page, or on set from the reader when the first take is not
 * landing. Each comes as the line to say, the text to put on screen and
 * what to show in the first second, so it can be shot as it stands.
 *
 * One call per ask (about a fifth of a cent), the same ask again answered
 * from the last time, all inside the monthly Gemini limit (GeminiBudget).
 */
class HookWriter
{
    public const LANGUAGES = [
        'script' => 'the same language and mix as the script',
        'english' => 'English',
        'tanglish' => 'Tanglish (Tamil in English letters, mixed with English, as people speak)',
        'tamil' => 'Tamil, with English words where people use them',
    ];

    public function write(Script $script, User $by, ?string $note = null, string $language = 'script', bool $fresh = false): ScriptHook
    {
        $language = array_key_exists($language, self::LANGUAGES) ? $language : 'script';
        $note = filled($note) ? Str::limit(trim($note), 600, '') : null;
        $script->loadMissing(['sections', 'client', 'languageTerm']);

        $text = self::text($script);
        if ($text === '') {
            throw new RuntimeException('This script has no words yet. Write it first, then ask for hooks.');
        }

        $client = $script->client;
        $context = [
            'title' => $script->title,
            'client' => $client?->name,
            'handle' => $client ? CaptionWriter::usernameFor($client) : null,
            'script' => Str::limit($text, 3000, '…'),
            'script_language' => $script->languageTerm?->name,
            'best' => $client ? collect(CaptionWriter::examples($client))->map(fn ($e) => ['first_line' => Str::before(trim($e['caption']), "\n"), 'views' => $e['views']])->all() : [],
        ];
        $hash = hash('sha256', json_encode([$context, $note, $language]));

        if (! $fresh && ($done = ScriptHook::query()->where('script_id', $script->id)->where('input_hash', $hash)->latest('id')->first())) {
            return $done;
        }

        $settings = AiSetting::current();
        if (! $settings->hasGeminiKey()) {
            throw new RuntimeException('Gemini is not connected. Add the key under AI settings.');
        }

        $before = GeminiBudget::spentThisMonth();
        [$reply, $model] = GeminiBudget::during('hooks', fn () => $this->ask($settings->gemini_api_key, $this->prompt($context, $note, $language)), $by);

        $parsed = json_decode((string) data_get($reply, 'candidates.0.content.parts.0.text', ''), true);
        $hooks = collect(is_array($parsed) ? ($parsed['hooks'] ?? $parsed) : [])
            ->filter(fn ($h) => is_array($h) && filled($h['spoken'] ?? null))
            ->map(fn (array $h) => [
                'spoken' => Str::limit(trim(str_replace(['**', '"'], '', (string) $h['spoken'])), 220, ''),
                'on_screen' => Str::limit(trim(str_replace(['**', '#'], '', (string) ($h['on_screen'] ?? ''))), 70, ''),
                'first_shot' => Str::limit(trim((string) ($h['first_shot'] ?? '')), 160, ''),
                'angle' => Str::limit(trim((string) ($h['angle'] ?? '')), 40, ''),
            ])
            ->take(5)->values()->all();

        if ($hooks === []) {
            throw new RuntimeException('Gemini did not return hooks. Try again, or add a note.');
        }

        return ScriptHook::create([
            'script_id' => $script->id,
            'user_id' => $by->id,
            'language' => $language,
            'note' => $note,
            'hooks' => $hooks,
            'input_hash' => $hash,
            'model' => $model,
            'cost_usd' => max(0, GeminiBudget::spentThisMonth() - $before),
        ]);
    }

    /** The script's words, plain. */
    public static function text(Script $script): string
    {
        return trim(html_entity_decode($script->sections
            ->map(fn ($s) => trim(($s->heading ? $s->heading.":\n" : '').strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $s->body))))
            ->filter()->implode("\n\n")));
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function ask(string $key, array $body): array
    {
        $models = (array) config('ai.captions.models');

        foreach ($models as $i => $model) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => $key])->timeout(90)
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', $body);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Slow or busy: the next model, else say so.
                if ($i === count($models) - 1) {
                    throw new RuntimeException('Gemini is slow right now. Try again in a minute.');
                }
                continue;
            }
            if ($response->successful()) {
                return [$response->json(), $model];
            }
            $retired = in_array($response->status(), [400, 404], true) && preg_match('/no longer available|not found|is not supported/i', (string) $response->json('error.message'));
            $busy = in_array($response->status(), [429, 500, 503], true);
            if (! ($retired || $busy) || $i === count($models) - 1) {
                throw new RuntimeException('Gemini could not write hooks: '.Str::limit((string) ($response->json('error.message') ?? $response->status()), 200));
            }
        }

        throw new RuntimeException('Gemini could not write hooks.');
    }

    /** @return array<string, mixed> */
    private function prompt(array $c, ?string $note, string $language): array
    {
        $lang = $language === 'script'
            ? self::LANGUAGES['script'].($c['script_language'] ? ' ('.$c['script_language'].')' : '')
            : self::LANGUAGES[$language];

        $system = 'You are the best short-form video hook writer in Tamil Nadu, India. A hook is the first 1 to 3 seconds of an '
            .'Instagram reel: it must stop the scroll and make the viewer need the rest. Strong hooks use a sharp question, '
            .'a surprising fact from the script, a bold claim the video proves, a mistake people make, a "you" problem, or '
            .'open curiosity -- in plain spoken words, as a real person on camera would say them. Never clickbait that the '
            .'video does not pay off, never invent facts, prices, offers or medical claims that are not in the script or note. '
            .'Return JSON only.';

        $parts = array_filter([
            'Video' => $c['title'],
            'Client' => $c['client'].($c['handle'] ? ' (@'.$c['handle'].')' : ''),
            'The script' => $c['script'],
            'Opening lines of this client\'s best-performing posts' => $c['best'] ? collect($c['best'])->map(fn ($b) => '- '.$b['first_line'].' ('.$b['views'].' views)')->implode("\n") : null,
            'Note from the team (follow it)' => $note,
        ]);

        $text = collect($parts)->map(fn ($v, $k) => "## {$k}\n{$v}")->implode("\n\n")
            ."\n\n## Write 5 hooks\n"
            ."- Language: {$lang}.\n"
            ."- Each a different angle (question, shocking fact, mistake, bold claim, story, curiosity...).\n"
            ."- spoken: the exact opening line said to camera, under 15 words, easy to say in one breath.\n"
            ."- on_screen: the text overlay for the first second, under 6 words.\n"
            ."- first_shot: what the camera shows in that first second, one short practical direction for the crew.\n"
            ."- angle: one or two words.\n"
            .'Return {"hooks": [{"spoken", "on_screen", "first_shot", "angle"}, ...5]}.';

        return [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 1.0],
        ];
    }

    /** Put a hook at the top of the script, as its own section. */
    public static function useOnScript(Script $script, array $hook, User $by): void
    {
        $body = '<p>'.e($hook['spoken']).'</p>'
            .(filled($hook['on_screen'] ?? null) ? '<p><em>On screen: '.e($hook['on_screen']).'</em></p>' : '')
            .(filled($hook['first_shot'] ?? null) ? '<p><em>Shot: '.e($hook['first_shot']).'</em></p>' : '');

        \Illuminate\Support\Facades\DB::transaction(function () use ($script, $body, $by) {
            $script->sections()->increment('position');
            $section = new \App\Models\ScriptSection;
            $section->forceFill(['script_id' => $script->id, 'heading' => 'Hook', 'body' => $body, 'position' => 0])->save();
            $script->forceFill(['last_edited_by_id' => $by->id, 'last_edited_at' => now()])->save();
        });
    }
}
