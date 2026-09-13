<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use App\Models\AiSetting;
use RuntimeException;

/**
 * The only class here that knows Anthropic's SDK exists.
 *
 * Deliberately thin. Every decision that is about *this studio* -- which
 * tools, what the assistant is told about itself, how many rounds it gets --
 * lives in AdminAgent. What lives here is the shape of a request and the
 * shape of a reply, so that when the SDK changes, one file changes.
 *
 * The key is read at call time, never in the constructor. The container
 * resolves this class on any request that so much as loads the settings
 * screen, and a constructor that threw for a studio with no key yet would
 * make the screen that sets the key unreachable.
 */
class Claude implements ChatModel
{
    /**
     * A ceiling, not a target -- a WhatsApp answer is a few lines. Set high
     * enough that thinking plus a long list never truncates mid-sentence,
     * and low enough to stay well inside the SDK's own HTTP timeout on a
     * non-streaming call.
     */
    private const MAX_TOKENS = 16000;

    /**
     * Low, because this is a lookup-and-phrase job on a phone: the tools do
     * the knowing, and the person is standing somewhere waiting. Raise it if
     * the assistant is ever given work that needs actual deliberation.
     */
    private const EFFORT = 'low';

    public function reply(string $system, array $messages, array $tools): ModelTurn
    {
        $settings = AiSetting::current();

        if (! $settings->hasKey()) {
            throw new RuntimeException('No Anthropic API key is on file.');
        }

        $client = new Client(apiKey: $settings->api_key);

        $response = $client->messages->create(
            model: $settings->modelName(),
            maxTokens: self::MAX_TOKENS,
            system: $system,
            messages: $this->wire($messages),
            tools: $tools,
            outputConfig: ['effort' => self::EFFORT],
        );

        return $this->turn($response);
    }

    /**
     * ChatModel's vocabulary translated into the Messages API's.
     *
     * An assistant turn is echoed back as the SDK's own content objects,
     * never as arrays rebuilt from ModelTurn's fields -- a tool_use block
     * carries more than the three things AdminAgent can see, and rebuilding
     * it is how a request starts failing months later for no visible reason.
     *
     * A tool's output goes back as `toolUseID` (camelCase): the SDK maps its
     * own names to the wire's snake_case, and passing `tool_use_id` here
     * would sail through untranslated and be rejected as unrecognised.
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
                $wire[] = ['role' => 'assistant', 'content' => $message['turn']->assistantContent];

                continue;
            }

            if (array_key_exists('ran', $message)) {
                $wire[] = [
                    'role' => 'user',
                    'content' => array_map(fn (array $result) => [
                        'type' => 'tool_result',
                        'toolUseID' => (string) $result['id'],
                        'content' => (string) $result['output'],
                        // A tool that failed says so in the same breath as
                        // it answers, so the model can apologise for the one
                        // figure it could not get rather than abandoning the
                        // other three it already has.
                        'isError' => (bool) ($result['failed'] ?? false),
                    ], $message['ran']),
                ];
            }
        }

        return $wire;
    }

    private function turn(Message $response): ModelTurn
    {
        $text = [];
        $toolCalls = [];

        foreach ($response->content as $block) {
            /*
             * Thinking blocks are skipped rather than concatenated: on this
             * model family they arrive with their text omitted anyway, and a
             * summary of the reasoning is not what the admin asked for.
             */
            if ($block instanceof TextBlock) {
                $text[] = $block->text;
            } elseif ($block instanceof ToolUseBlock) {
                $toolCalls[] = [
                    'id' => $block->id,
                    'name' => $block->name,
                    'input' => $block->input,
                ];
            }
        }

        return new ModelTurn(
            text: trim(implode("\n\n", $text)),
            toolCalls: $toolCalls,
            assistantContent: $response->content,
            model: $response->model,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            stopReason: $response->stopReason,
        );
    }
}
