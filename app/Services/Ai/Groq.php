<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Groq's free tier, spoken to over its OpenAI-compatible endpoint.
 *
 * The studio's choice: an open model served at no charge, rather than a bill
 * per question. Same seam as Claude -- AdminAgent cannot tell which one it is
 * talking to, which is the whole reason the seam exists, and why switching
 * providers is a dropdown rather than a rewrite.
 *
 * The conversation shape here is genuinely different from Anthropic's, not
 * just renamed, and the difference lives entirely in this file:
 *
 * - A tool's output is its own `tool` message, one per call. Anthropic takes
 *   every result as blocks inside a single user message; sending one message
 *   per result there would teach the model to stop asking in parallel. Here
 *   the opposite is true -- they must be separate, each carrying the id of
 *   the call it answers.
 * - The assistant turn is echoed back as the message object the API returned.
 *   `content` may be absent entirely when the model only asked for tools, and
 *   the key has to be present (null is fine) on the way back.
 * - A tool's arguments arrive as a JSON *string*, not an object.
 *
 * Plain HTTP through Laravel's client rather than a vendor SDK: the endpoint
 * is four fields and a bearer token, and going through Http means the tests
 * can assert the exact request without a package in between.
 */
class Groq implements ChatModel
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    /**
     * Generous, because the free tier queues: a request can sit for a second
     * or two before it is served, and this runs on a queue worker where
     * waiting costs nothing but the worker's own minute.
     */
    private const TIMEOUT_SECONDS = 60;

    /** A ceiling on the reply, not a target. */
    private const MAX_TOKENS = 2048;

    /**
     * The longest this will sit waiting for the free tier's per-minute window
     * to reopen. Long enough for a burst of questions, short enough that a
     * worker is never parked on a quota that resets tomorrow.
     */
    private const MAX_WAIT_SECONDS = 25;

    /**
     * Settings that only the reasoning models accept.
     *
     * `hidden` is the one that matters. Without it this family returns its
     * working-out alongside the answer, and a small model's working-out leaks
     * into the reply -- the first live test came back with a crew list ending
     * "Keerthasan? Actually ...", which is not something to send to somebody's
     * phone. `low` keeps it from rambling on a lookup that needs no thought.
     *
     * Not every model here takes them: `groq/compound` refuses outright. So
     * they are offered and withdrawn if refused, rather than the settings
     * screen quietly having models that cannot work.
     */
    private const REASONING_OPTIONS = [
        'reasoning_effort' => 'low',
        'reasoning_format' => 'hidden',
    ];

    public function reply(string $system, array $messages, array $tools): ModelTurn
    {
        $settings = AiSetting::current();

        if (! $settings->hasKey()) {
            throw new RuntimeException('No Groq API key is on file.');
        }

        $payload = [
            'model' => $settings->modelName(),
            'max_tokens' => self::MAX_TOKENS,
            /*
             * Zero. Everything this assistant says is copied from a tool --
             * a client's name, a crew member's name, a figure -- and sampling
             * is what turns "Annamalai" into "Annanmali" on the way out.
             * There is nothing here worth being creative about.
             */
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...$this->wire($messages),
            ],
            'tools' => $this->functions($tools),
        ];

        $attempt = $payload + self::REASONING_OPTIONS;
        $response = $this->send($attempt);

        if ($this->refusedTheReasoningOptions($response)) {
            $attempt = $payload;
            $response = $this->send($attempt);
        }

        $response = $this->waitOutARateLimit($response, $attempt);

        if ($response->failed()) {
            /*
             * The provider's own words, trimmed. A free tier refuses for
             * reasons worth reading -- the day's quota, a model that was
             * retired, a key that was rotated -- and "the assistant failed"
             * with none of that is an afternoon of guessing.
             */
            throw new RuntimeException('Groq refused the request ('.$response->status().'): '.mb_substr(
                (string) ($response->json('error.message') ?? $response->body()), 0, 300
            ));
        }

        return $this->turn($response->json() ?? []);
    }

    /**
     * The free tier's ceiling is a minute wide, not a day: 8,000 tokens per
     * minute on the default model, against roughly 1,500 per call. Three
     * questions in a row reach it, and the reply says so along with exactly
     * how long to wait.
     *
     * So wait. This runs on a queue worker, where ten seconds costs nothing
     * anybody can see -- and the alternative is the owner being told the
     * assistant is unavailable when it is simply somebody's third question in
     * a minute. Once only, and never for longer than MAX_WAIT_SECONDS: past
     * that it is the day's quota rather than a burst, and a sentence saying so
     * beats a worker asleep.
     *
     * @param  array<string, mixed>  $attempt  the payload as it was actually sent
     */
    private function waitOutARateLimit(Response $response, array $attempt): Response
    {
        if ($response->status() !== 429) {
            return $response;
        }

        $wait = (float) ($response->header('retry-after') ?: 0);

        if ($wait <= 0 && preg_match('/try again in ([\d.]+)s/i', (string) $response->json('error.message'), $m)) {
            $wait = (float) $m[1];
        }

        if ($wait <= 0 || $wait > self::MAX_WAIT_SECONDS) {
            return $response;
        }

        Log::info('Groq rate limit reached; waiting it out.', ['seconds' => $wait]);

        // The half second is slack: the window resets on the provider's clock,
        // not ours, and coming back a moment early wastes the whole wait.
        usleep((int) (($wait + 0.5) * 1_000_000));

        return $this->send($attempt);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): Response
    {
        return Http::withToken(AiSetting::current()->api_key)
            ->timeout(self::TIMEOUT_SECONDS)
            ->asJson()
            ->post(self::ENDPOINT, $payload);
    }

    /**
     * Whether this model simply does not take the reasoning settings.
     *
     * Narrow on purpose: only a 400 that names one of them. Any other refusal
     * -- the day's quota, a retired model, a rotated key -- must reach the
     * caller as itself rather than being retried into the same wall.
     */
    private function refusedTheReasoningOptions(Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        $message = (string) ($response->json('error.message') ?? '');

        foreach (array_keys(self::REASONING_OPTIONS) as $option) {
            if (str_contains($message, $option)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ChatModel's vocabulary translated into chat-completions messages.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function wire(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            if (array_key_exists('said', $message)) {
                $wire[] = ['role' => 'user', 'content' => (string) $message['said']];

                continue;
            }

            if (($message['turn'] ?? null) instanceof ModelTurn) {
                $wire[] = $this->assistantMessage($message['turn']);

                continue;
            }

            if (array_key_exists('ran', $message)) {
                foreach ($message['ran'] as $result) {
                    /*
                     * There is no is-error flag in this dialect. A failure is
                     * simply what the tool said, and the model is told in its
                     * instructions to pass on what it could not get rather
                     * than inventing a figure in its place.
                     */
                    $wire[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) $result['id'],
                        'content' => (string) $result['output'],
                    ];
                }
            }
        }

        return $wire;
    }

    /**
     * An assistant turn as this API will accept it back.
     *
     * Rebuilt rather than echoed whole, which is the opposite of the rule in
     * Claude: what comes back here is a plain JSON object, and it carries a
     * `reasoning` field that is the model thinking out loud. Replaying that
     * costs tokens on every later turn to say nothing, so only the two fields
     * the protocol needs are kept -- and `content` is kept even when null,
     * because a missing key is rejected where a null one is not.
     */
    private function assistantMessage(ModelTurn $turn): array
    {
        $raw = is_array($turn->assistantContent) ? $turn->assistantContent : null;

        if ($raw === null) {
            // A turn rebuilt from the stored transcript: words only, which is
            // all the transcript keeps.
            return ['role' => 'assistant', 'content' => $turn->text];
        }

        $message = [
            'role' => 'assistant',
            'content' => $raw['content'] ?? null,
        ];

        if (filled($raw['tool_calls'] ?? null)) {
            $message['tool_calls'] = $raw['tool_calls'];
        }

        return $message;
    }

    /**
     * Tool definitions in this dialect: wrapped in a `function` object, with
     * the schema under `parameters` rather than `inputSchema`.
     *
     * An argument-less tool gets `properties` as an explicit empty object.
     * Anthropic accepts the key being absent; here it must be present, and it
     * must serialise as `{}` -- which is why it is an object and not `[]`,
     * the shape an empty PHP array would have produced.
     *
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    private function functions(array $tools): array
    {
        return array_map(function (array $tool) {
            $schema = $tool['inputSchema'] ?? ['type' => 'object'];
            $schema['properties'] = filled($schema['properties'] ?? null)
                ? $schema['properties']
                : (object) [];

            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $schema,
                ],
            ];
        }, $tools);
    }

    /** @param array<string, mixed> $payload */
    private function turn(array $payload): ModelTurn
    {
        $message = data_get($payload, 'choices.0.message', []);
        $toolCalls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            /*
             * Arguments arrive as a JSON string and a small model does
             * occasionally emit one that will not parse. An unparseable call
             * becomes a call with no arguments rather than a crashed job:
             * the tool then says what it needs, and the model asks again.
             */
            $arguments = json_decode((string) data_get($call, 'function.arguments', '{}'), true);

            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) data_get($call, 'function.name', ''),
                'input' => is_array($arguments) ? $arguments : [],
            ];
        }

        return new ModelTurn(
            text: trim((string) ($message['content'] ?? '')),
            toolCalls: $toolCalls,
            assistantContent: $message,
            model: (string) ($payload['model'] ?? ''),
            inputTokens: (int) data_get($payload, 'usage.prompt_tokens', 0),
            outputTokens: (int) data_get($payload, 'usage.completion_tokens', 0),
            stopReason: (string) data_get($payload, 'choices.0.finish_reason', ''),
        );
    }
}
