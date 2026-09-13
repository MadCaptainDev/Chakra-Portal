<?php

namespace App\Services\Ai;

/**
 * One reply from the model: either something to say, or tools to run first.
 *
 * The whole point of this object is that nothing outside App\Services\Ai has
 * to know the shape of an Anthropic response. AdminAgent reads `text` and
 * `toolCalls` and nothing else, which is what lets the tests hand it a
 * scripted turn instead of a live call.
 */
final class ModelTurn
{
    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  mixed  $assistantContent  This turn exactly as the model
     *                                   produced it, to be handed back verbatim when the
     *                                   conversation continues. Opaque on purpose: only the
     *                                   ChatModel that made it may look inside, because a
     *                                   tool_use block that is rebuilt rather than echoed is a
     *                                   block the next request may reject.
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls = [],
        public readonly mixed $assistantContent = null,
        public readonly ?string $model = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $stopReason = null,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * The model declined on safety grounds. Distinct from an error: the call
     * succeeded, the answer is a refusal, and the right response is to say so
     * rather than to retry.
     */
    public function isRefusal(): bool
    {
        // Two spellings for one thing: Anthropic stops with `refusal`,
        // OpenAI-compatible providers with `content_filter`. Either way the
        // call succeeded, the answer is a refusal, and retrying it would
        // simply be refused again.
        return in_array($this->stopReason, ['refusal', 'content_filter'], true);
    }
}
