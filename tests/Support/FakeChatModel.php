<?php

namespace Tests\Support;

use App\Services\Ai\ChatModel;
use App\Services\Ai\ModelTurn;
use RuntimeException;

/**
 * A model that says exactly what a test told it to say.
 *
 * The reason ChatModel exists. A fake that faked HTTP would be asserting
 * things about the Anthropic SDK's request shape, which is the SDK's problem;
 * this asserts things about AdminAgent's own behaviour -- that it runs the
 * tool it was asked for, hands the output back, replays the words and not the
 * figures, and always sends something.
 *
 * Every call is recorded, so a test can look at what the assistant was told
 * and what conversation it built.
 */
class FakeChatModel implements ChatModel
{
    /** @var list<ModelTurn> */
    private array $script = [];

    /** @var list<array{system: string, messages: array, tools: array}> */
    public array $calls = [];

    /** Queue a plain spoken reply. */
    public function willSay(string $text): self
    {
        $this->script[] = new ModelTurn(
            text: $text,
            assistantContent: $text,
            model: 'fake-model',
            inputTokens: 100,
            outputTokens: 20,
            stopReason: 'end_turn',
        );

        return $this;
    }

    /**
     * Queue a reply that asks for tools.
     *
     * @param  list<array{name: string, input?: array<string, mixed>}>  $tools
     */
    public function willCall(array $tools): self
    {
        $this->script[] = new ModelTurn(
            text: '',
            toolCalls: array_map(fn (array $tool, int $i) => [
                'id' => 'toolu_'.$i.'_'.$tool['name'],
                'name' => $tool['name'],
                'input' => $tool['input'] ?? [],
            ], $tools, array_keys($tools)),
            assistantContent: '(asked for tools)',
            model: 'fake-model',
            inputTokens: 100,
            outputTokens: 20,
            stopReason: 'tool_use',
        );

        return $this;
    }

    public function willRefuse(string $text = ''): self
    {
        $this->script[] = new ModelTurn(
            text: $text,
            assistantContent: $text,
            model: 'fake-model',
            stopReason: 'refusal',
        );

        return $this;
    }

    public function reply(string $system, array $messages, array $tools): ModelTurn
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'tools' => $tools];

        $turn = array_shift($this->script);

        if ($turn === null) {
            throw new RuntimeException('The fake model was asked '.count($this->calls).' times and only had '.(count($this->calls) - 1).' replies scripted.');
        }

        return $turn;
    }

    /** Everything handed back as tool output, across every call. */
    public function toolResults(): array
    {
        $results = [];

        foreach ($this->calls as $call) {
            foreach ($call['messages'] as $message) {
                foreach ($message['ran'] ?? [] as $result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }
}
