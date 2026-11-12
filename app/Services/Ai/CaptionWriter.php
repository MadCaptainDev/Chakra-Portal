<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ContentAccountVenture;
use App\Models\ContentCaption;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Support\PlannerVisuals;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Instagram captions for a planner video, written by Gemini in the studio's
 * own format and the client's own voice.
 *
 * Gemini returns the PARTS (hook, the line after the handle, body, call to
 * action, keywords, hashtags) and this class puts them together, so the
 * house rules hold whatever the model does:
 *
 *   Hook line
 *   @client_username - one short line about the client
 *
 *   Body
 *
 *   Call to action
 *
 *   [keyword, keyword, ...]
 *
 *   #Tag #Tag #Tag (never more than five)
 *
 * Kept cheap: one call returns three options; the context is trimmed (the
 * client's three best captions, the script, a few lines of the brief); and
 * the same ask again -- same video, script, note and language -- is
 * answered from the last time without calling Gemini. Every call is inside
 * the monthly Gemini limit (GeminiBudget).
 */
class CaptionWriter
{
    public const LANGUAGES = [
        'english' => 'English',
        'tanglish' => 'Tanglish (Tamil in English letters, mixed with English)',
        'tamil' => 'Tamil script with English words where people use them',
    ];

    private const BRIEF_KEYS = ['about', 'idealCustomer', 'mainGoal', 'perception', 'language', 'avoid', 'locations'];

    /**
     * Three caption options for the video.
     *
     * @param  bool  $fresh  ask Gemini again even if this exact ask was answered before
     */
    public function write(ContentItem $item, User $by, ?string $note = null, string $language = 'english', bool $fresh = false): ContentCaption
    {
        $language = array_key_exists($language, self::LANGUAGES) ? $language : 'english';
        $note = filled($note) ? Str::limit(trim($note), 1000, '') : null;

        $client = self::clientFor($item);
        $username = $client ? self::usernameFor($client) : null;
        $script = self::scriptOf($item);
        $context = [
            'video' => $item->title,
            'venture' => $item->venture,
            'type' => $item->source,
            'script' => $script,
            'username' => $username,
            'brief' => $client ? self::brief($client) : [],
            'examples' => $client ? self::examples($client) : [],
        ];
        $hash = hash('sha256', json_encode([$context, $note, $language, config('ai.captions')]));

        if (! $fresh && ($done = ContentCaption::query()->where('content_item_id', $item->id)->where('input_hash', $hash)->latest('id')->first())) {
            return $done;
        }

        $settings = AiSetting::current();
        if (! $settings->hasGeminiKey()) {
            throw new RuntimeException('Gemini is not connected. Add the key under AI settings.');
        }

        $before = GeminiBudget::spentThisMonth();
        [$reply, $model] = GeminiBudget::during('captions', fn () => $this->ask($settings->gemini_api_key, $this->prompt($context, $note, $language)), $by);

        $parts = json_decode((string) data_get($reply, 'candidates.0.content.parts.0.text', ''), true);
        $options = collect(is_array($parts) ? ($parts['captions'] ?? $parts) : [])
            ->filter(fn ($o) => is_array($o) && filled($o['hook'] ?? null))
            ->take(3)
            ->map(fn (array $o) => ['angle' => Str::limit(trim((string) ($o['angle'] ?? '')), 60, ''), 'text' => self::assemble($o, $username)])
            ->values()
            ->all();

        if ($options === []) {
            throw new RuntimeException('Gemini did not return a caption. Try again, or add a note about the video.');
        }

        return ContentCaption::create([
            'content_item_id' => $item->id,
            'user_id' => $by->id,
            'language' => $language,
            'note' => $note,
            'options' => $options,
            'input_hash' => $hash,
            'model' => $model,
            'cost_usd' => max(0, GeminiBudget::spentThisMonth() - $before),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: string} the reply and the model that gave it */
    private function ask(string $key, array $body): array
    {
        $models = (array) config('ai.captions.models');

        foreach ($models as $i => $model) {
            $response = Http::withHeaders(['x-goog-api-key' => $key])->timeout(60)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', $body);

            $retired = in_array($response->status(), [400, 404], true) && preg_match('/no longer available|not found|is not supported/i', (string) $response->json('error.message'));
            $busy = in_array($response->status(), [429, 500, 503], true);
            if ($response->successful()) {
                return [$response->json(), $model];
            }
            if (! ($retired || $busy) || $i === count($models) - 1) {
                throw new RuntimeException('Gemini could not write the caption: '.Str::limit((string) ($response->json('error.message') ?? $response->status()), 200));
            }
        }

        throw new RuntimeException('Gemini could not write the caption.');
    }

    /** @return array<string, mixed> the generateContent request */
    private function prompt(array $c, ?string $note, string $language): array
    {
        $max = (int) config('ai.captions.max_hashtags', 5);
        $handle = $c['username'] ? '@'.$c['username'] : 'the client';

        $system = 'You write Instagram captions for a video agency in Tamil Nadu, India. Write the best caption a top social media '
            .'manager would: a hook that stops the scroll, then value, then one clear call to action. Match the client\'s voice '
            .'from their own best-performing captions. Short lines, easy to read on a phone, a few relevant emojis, no filler, '
            .'no clichés ("in today\'s fast-paced world", "elevate", "unlock"), no made-up facts, prices, offers or medical claims '
            .'that are not in the script, note or brief. Return JSON only.';

        $rules = [
            'Language: '.self::LANGUAGES[$language].'.',
            'Give 3 different options, each with a different angle (for example: curiosity, benefit, story, question, urgency).',
            'hook: one line, under 70 characters, the first thing people read. No hashtags.',
            'tagline: what follows "'.$handle.' - " on the second line: under 8 words saying what the client is or offers. Do not repeat the handle.',
            'body: 2 to 6 short lines that deliver what the video promises. Lists with emoji or numbers are fine. No hashtags, no @mentions.',
            'cta: one line asking people to save, share, comment, DM or visit.',
            config('ai.captions.keywords', true) ? 'keywords: 5 to 8 search phrases people would type to find this video.' : 'keywords: an empty list.',
            "hashtags: at most {$max}, specific to the topic and the client, no generic ones like #reels #viral #trending #instagood.",
            'Keep the whole caption under 1800 characters.',
        ];

        $context = array_filter([
            'Video title' => $c['video'],
            'Client' => $c['venture'],
            'Instagram handle' => $c['username'] ? '@'.$c['username'] : null,
            'Planner' => $c['type'],
            'Script (what is said in the video)' => $c['script'],
            'About the client' => $c['brief'] ? collect($c['brief'])->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n") : null,
            'Their best-performing captions, for voice and format' => $c['examples'] ? collect($c['examples'])->map(fn ($e, $i) => 'Example '.($i + 1)." ({$e['views']} views):\n{$e['caption']}")->implode("\n\n") : null,
            'Note from the team (follow it)' => $note,
        ]);

        $text = collect($context)->map(fn ($v, $k) => "## {$k}\n{$v}")->implode("\n\n")
            ."\n\n## Rules\n- ".implode("\n- ", array_filter($rules))
            ."\n\nReturn {\"captions\": [{\"angle\", \"hook\", \"tagline\", \"body\", \"cta\", \"keywords\": [], \"hashtags\": []}, ...3]}.";

        return [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.9],
        ];
    }

    /**
     * Put one option together in the house format, enforcing the rules.
     *
     * @param  array<string, mixed>  $o
     */
    public static function assemble(array $o, ?string $username): string
    {
        $clean = fn ($v) => trim(preg_replace('/\*\*|__/', '', is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v));
        // Hashtags and @mentions belong on their own lines, not in the text.
        $plain = fn ($v) => trim(preg_replace(['/(^|\s)#[\p{L}\p{N}_]+/u', '/[ \t]{2,}/'], ['$1', ' '], $clean($v)));

        $hook = Str::limit($plain($o['hook'] ?? ''), 120, '');
        $tagline = trim(Str::limit(preg_replace('/^@\S+\s*-?\s*/', '', $plain($o['tagline'] ?? '')), 80, ''));
        $body = preg_replace("/\n{3,}/", "\n\n", $plain($o['body'] ?? ''));
        $cta = $plain($o['cta'] ?? '');

        $keywords = collect((array) ($o['keywords'] ?? []))->map(fn ($k) => trim(str_replace(['[', ']', '#'], '', (string) $k)))
            ->filter()->unique(fn ($k) => mb_strtolower($k))->take(10)->values();

        $max = (int) config('ai.captions.max_hashtags', 5);
        $tags = collect((array) ($o['hashtags'] ?? []))
            ->flatMap(fn ($t) => preg_split('/[\s,]+/', (string) $t))
            ->map(fn ($t) => preg_replace('/[^\p{L}\p{N}_]/u', '', (string) $t))
            ->filter(fn ($t) => $t !== '' && ! is_numeric($t))
            ->unique(fn ($t) => mb_strtolower($t))
            ->take($max)
            ->map(fn ($t) => '#'.$t);

        $handleLine = $username
            ? trim(str_replace('{username}', $username, (string) config('ai.captions.handle_line', '@{username} -')).' '.$tagline)
            : null;

        $blocks = array_filter([
            trim($hook."\n".($handleLine ?? '')),
            $body,
            $cta,
            config('ai.captions.keywords', true) && $keywords->isNotEmpty() ? '['.$keywords->implode(', ').']' : null,
            $tags->implode(' '),
        ], fn ($b) => filled($b));

        $text = implode("\n\n", $blocks);
        $limit = (int) config('ai.captions.max_length', 2200);

        // Over Instagram's limit: the body gives way, never the handle line or the tags.
        if (mb_strlen($text) > $limit) {
            $spare = $limit - (mb_strlen($text) - mb_strlen($body)) - 1;
            $blocks[1] = rtrim(mb_substr($body, 0, max(0, $spare))).'…';
            $text = implode("\n\n", array_filter($blocks, fn ($b) => filled($b)));
        }

        return $text;
    }

    /** How many hashtags a caption carries. */
    public static function hashtagCount(string $caption): int
    {
        return preg_match_all('/(^|\s)#[\p{L}\p{N}_]+/u', $caption);
    }

    /** The client a planner video belongs to: its venture's content account, else the planner's own logo match. */
    public static function clientFor(ContentItem $item): ?Client
    {
        if (blank($item->venture)) {
            return null;
        }

        $id = ContentAccountVenture::query()->where('venture', $item->venture)->with('contentAccount:id,client_id')->first()?->contentAccount?->client_id
            ?? PlannerVisuals::client($item->venture)['id'] ?? null;

        return $id ? Client::find($id) : null;
    }

    public static function usernameFor(Client $client): ?string
    {
        $account = SocialAccount::query()->where('client_id', $client->id)->where('platform', 'instagram')
            ->whereNotNull('username')->orderByRaw("status = 'connected' desc")->latest('id')->first();

        return $account?->username ? ltrim($account->username, '@') : null;
    }

    /** What is said in the video: the portal script, else the one written in Notion. Trimmed. */
    public static function scriptOf(ContentItem $item): ?string
    {
        $portal = $item->scriptRecord()->with('sections')->first();
        $text = $portal
            ? $portal->sections->map(fn ($s) => trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $s->body))))->filter()->implode("\n")
            : (string) $item->script;

        $text = trim(html_entity_decode(preg_replace("/\n{3,}/", "\n\n", $text)));

        return $text !== '' ? Str::limit($text, 2500, '…') : null;
    }

    /** @return array<string, string> a few lines of the client's brief */
    private static function brief(Client $client): array
    {
        $brief = ClientBrief::query()->where('client_id', $client->id)->first();
        if (! $brief) {
            return [];
        }

        return $brief->answers()->whereIn('question_key', self::BRIEF_KEYS)->get()
            ->mapWithKeys(function ($a) {
                $value = $a->value ?? (is_array($a->value_json) ? implode(', ', array_map(fn ($v) => is_array($v) ? implode(' ', $v) : (string) $v, $a->value_json)) : null);

                return filled($value) ? [$a->question_key => Str::limit(trim((string) $value), 300, '…')] : [];
            })
            ->all();
    }

    /** @return list<array{caption: string, views: int}> the client's best-viewed captions */
    private static function examples(Client $client): array
    {
        $media = SocialMediaItem::query()
            ->whereHas('socialAccount', fn ($q) => $q->where('client_id', $client->id))
            ->whereNotNull('caption')->where('caption', '!=', '')
            ->where('posted_at', '>=', now()->subMonths(9))
            ->latest('posted_at')->limit(80)->get(['id', 'caption']);

        if ($media->isEmpty()) {
            return [];
        }

        $views = SocialInsight::latestPerItem($media->pluck('id'), ['views', 'reach']);

        return $media
            ->map(fn ($m) => ['caption' => Str::limit(trim($m->caption), 900, '…'), 'views' => (int) ($views[$m->id]['views'] ?? $views[$m->id]['reach'] ?? 0)])
            ->filter(fn ($m) => mb_strlen($m['caption']) > 40)
            ->sortByDesc('views')
            ->take((int) config('ai.captions.examples', 3))
            ->values()
            ->all();
    }
}
