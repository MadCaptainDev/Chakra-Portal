<?php

namespace App\Services\Ai;

/**
 * A model that can be asked a question and may ask for tools back.
 *
 * The seam between the assistant and Anthropic. One implementation calls the
 * API (Claude); the tests bind a scripted one. Nothing here mentions HTTP,
 * because a fake that has to fake HTTP is a fake that tests the SDK.
 *
 * The conversation is passed in this package's own vocabulary rather than the
 * API's, so that a caller never has to build a content block:
 *
 *   ['said' => 'how much is outstanding?']   the admin
 *   ['turn' => ModelTurn]                    a reply already received
 *   ['ran' => [['id' => ..., 'output' => ...], ...]]  what its tools returned
 *
 * Translating that into whatever the wire wants is the implementation's job,
 * and the only place that knows a `tool_result` from a `tool_use`.
 */
interface ChatModel
{
    /**
     * @param  list<array<string, mixed>>  $messages  the vocabulary above
     * @param  list<array<string, mixed>>  $tools  tool definitions
     */
    public function reply(string $system, array $messages, array $tools): ModelTurn;
}
