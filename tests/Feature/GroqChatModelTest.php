<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\ChatModel;
use App\Services\Ai\Claude;
use App\Services\Ai\Groq;
use App\Services\Ai\ModelTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The translation between this portal's vocabulary and Groq's wire format.
 *
 * AdminAgentTest proves what the assistant *does*, against a scripted model.
 * This proves the one thing that cannot catch: that what goes out is a shape
 * the provider accepts, and what comes back is read correctly. Every
 * assertion here is about a difference from Anthropic's dialect -- the places
 * a translation written from memory would be wrong.
 */
class GroqChatModelTest extends TestCase
{
    use RefreshDatabase;

    private function keyed(array $overrides = []): void
    {
        AiSetting::current()->update($overrides + [
            'api_key' => 'gsk_test_key',
            'provider' => AiSetting::PROVIDER_GROQ,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $message */
    private function fakeReply(array $message, string $finish = 'stop'): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'model' => 'openai/gpt-oss-120b',
            'choices' => [['message' => $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 164, 'completion_tokens' => 45],
        ])]);
    }

    /** The body of the single request that was sent. */
    private function sentBody(): array
    {
        $body = null;

        Http::assertSent(function (Request $request) use (&$body) {
            $body = $request->data();

            return true;
        });

        return $body ?? [];
    }

    public function test_a_plain_answer_is_read_back(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'Nothing is overdue.']);

        $turn = (new Groq)->reply('You are an assistant.', [['said' => 'anything overdue?']], []);

        $this->assertSame('Nothing is overdue.', $turn->text);
        $this->assertFalse($turn->wantsTools());
        $this->assertSame(164, $turn->inputTokens);
        $this->assertSame(45, $turn->outputTokens);
        $this->assertSame('openai/gpt-oss-120b', $turn->model);
    }

    public function test_the_key_travels_as_a_bearer_token_and_the_system_prompt_leads(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'ok']);

        (new Groq)->reply('You are an assistant.', [['said' => 'hello']], []);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gsk_test_key'));

        $this->assertSame(
            [['role' => 'system', 'content' => 'You are an assistant.'], ['role' => 'user', 'content' => 'hello']],
            $this->sentBody()['messages'],
        );
    }

    public function test_no_key_is_refused_before_any_request_is_made(): void
    {
        Http::fake();
        AiSetting::current()->update(['provider' => AiSetting::PROVIDER_GROQ, 'is_active' => true]);

        $this->expectException(RuntimeException::class);

        try {
            (new Groq)->reply('system', [['said' => 'hello']], []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_default_model_is_used_when_none_is_typed(): void
    {
        $this->keyed(['model' => null]);
        $this->fakeReply(['role' => 'assistant', 'content' => 'ok']);

        (new Groq)->reply('system', [['said' => 'hello']], []);

        $this->assertSame(
            AiSetting::DEFAULT_MODELS[AiSetting::PROVIDER_GROQ],
            $this->sentBody()['model'],
        );
    }

    // ——— Tools out ———

    public function test_a_tool_is_sent_as_a_function_with_its_schema_under_parameters(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'ok']);

        (new Groq)->reply('system', [['said' => 'hello']], [[
            'name' => 'find_client',
            'description' => 'Look up clients by name.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        ]]);

        $this->assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'find_client',
                'description' => 'Look up clients by name.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                    'required' => ['name'],
                ],
            ],
        ]], $this->sentBody()['tools']);
    }

    public function test_an_argumentless_tool_sends_properties_as_an_object_not_a_list(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'ok']);

        (new Groq)->reply('system', [['said' => 'hello']], [[
            'name' => 'money_summary',
            'description' => 'Money in and owed.',
            // Anthropic accepts the key being absent entirely; this dialect
            // wants it present, and as `{}` rather than `[]`.
            'inputSchema' => ['type' => 'object'],
        ]]);

        Http::assertSent(function (Request $request) {
            return str_contains($request->body(), '"properties":{}');
        });
    }

    // ——— Tools back ———

    public function test_a_tool_call_is_read_with_its_arguments_parsed(): void
    {
        $this->keyed();
        $this->fakeReply([
            'role' => 'assistant',
            // No `content` key at all, which is what this provider actually
            // sends when the model only asked for a tool.
            'reasoning' => 'thinking out loud',
            'tool_calls' => [[
                'id' => 'fc_1',
                'type' => 'function',
                'function' => ['name' => 'find_client', 'arguments' => '{"name":"Pothys"}'],
            ]],
        ], finish: 'tool_calls');

        $turn = (new Groq)->reply('system', [['said' => 'what does pothys owe?']], []);

        $this->assertTrue($turn->wantsTools());
        $this->assertSame('', $turn->text);
        $this->assertSame([['id' => 'fc_1', 'name' => 'find_client', 'input' => ['name' => 'Pothys']]], $turn->toolCalls);
    }

    public function test_unparseable_arguments_become_no_arguments_rather_than_a_crash(): void
    {
        $this->keyed();
        $this->fakeReply([
            'role' => 'assistant',
            'tool_calls' => [[
                'id' => 'fc_1',
                'function' => ['name' => 'find_client', 'arguments' => '{"name": unquoted}'],
            ]],
        ], finish: 'tool_calls');

        $turn = (new Groq)->reply('system', [['said' => 'hello']], []);

        // The tool then says what it needs and the model asks again, which is
        // a conversation rather than a failed job.
        $this->assertSame([], $turn->toolCalls[0]['input']);
    }

    public function test_each_tool_result_is_its_own_message_carrying_the_call_id(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'Both answered.']);

        $asked = new ModelTurn(
            text: '',
            toolCalls: [],
            assistantContent: [
                'role' => 'assistant',
                'reasoning' => 'thinking out loud',
                'tool_calls' => [
                    ['id' => 'fc_1', 'function' => ['name' => 'money_summary', 'arguments' => '{}']],
                    ['id' => 'fc_2', 'function' => ['name' => 'timesheet_gaps', 'arguments' => '{}']],
                ],
            ],
        );

        (new Groq)->reply('system', [
            ['said' => 'money and timesheets?'],
            ['turn' => $asked],
            ['ran' => [
                ['id' => 'fc_1', 'output' => 'Collected 120000', 'failed' => false],
                ['id' => 'fc_2', 'output' => 'Everyone logged', 'failed' => false],
            ]],
        ], []);

        $messages = $this->sentBody()['messages'];

        // One `tool` message per result -- the opposite of Anthropic, where
        // they all belong to a single user message.
        $this->assertSame(
            ['system', 'user', 'assistant', 'tool', 'tool'],
            array_column($messages, 'role'),
        );
        $this->assertSame('fc_1', $messages[3]['tool_call_id']);
        $this->assertSame('Collected 120000', $messages[3]['content']);
        $this->assertSame('fc_2', $messages[4]['tool_call_id']);
    }

    public function test_the_echoed_assistant_turn_keeps_content_and_drops_the_reasoning(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'done']);

        $asked = new ModelTurn(text: '', assistantContent: [
            'role' => 'assistant',
            'reasoning' => 'a paragraph of thinking that costs tokens on every later turn',
            'tool_calls' => [['id' => 'fc_1', 'function' => ['name' => 'money_summary', 'arguments' => '{}']]],
        ]);

        (new Groq)->reply('system', [
            ['said' => 'how are we doing?'],
            ['turn' => $asked],
            ['ran' => [['id' => 'fc_1', 'output' => 'Collected 120000', 'failed' => false]]],
        ], []);

        $assistant = $this->sentBody()['messages'][2];

        // `content` present even though it is null: a missing key is
        // rejected where a null one is not.
        $this->assertArrayHasKey('content', $assistant);
        $this->assertNull($assistant['content']);
        $this->assertArrayNotHasKey('reasoning', $assistant);
        $this->assertCount(1, $assistant['tool_calls']);
    }

    public function test_a_turn_rebuilt_from_the_transcript_goes_back_as_plain_words(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'Yes, this month.']);

        // What conversation() hands over for yesterday's exchange: the words,
        // never the figures behind them.
        $remembered = new ModelTurn(text: 'Collected ₹1,20,000.', assistantContent: 'Collected ₹1,20,000.');

        (new Groq)->reply('system', [
            ['said' => 'how are we doing?'],
            ['turn' => $remembered],
            ['said' => 'this month?'],
        ], []);

        $this->assertSame(
            ['role' => 'assistant', 'content' => 'Collected ₹1,20,000.'],
            $this->sentBody()['messages'][2],
        );
    }

    // ——— When it goes wrong ———

    public function test_a_refused_request_says_what_the_provider_said(): void
    {
        $this->keyed();
        Http::fake(['api.groq.com/*' => Http::response([
            'error' => ['message' => 'Rate limit reached for model on requests per day'],
        ], 429)]);

        $this->expectException(RuntimeException::class);
        // The provider's own words: a free tier refuses for reasons worth
        // reading, and "the assistant failed" with none of them is an
        // afternoon of guessing.
        $this->expectExceptionMessage('Rate limit reached');

        (new Groq)->reply('system', [['said' => 'hello']], []);
    }

    public function test_a_content_filter_reads_as_a_refusal_rather_than_an_error(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'I can\'t help with that.'], finish: 'content_filter');

        $turn = (new Groq)->reply('system', [['said' => 'something off-limits']], []);

        $this->assertTrue($turn->isRefusal());
    }
    // ——— Which provider the container hands out ———

    public function test_the_settings_row_decides_which_provider_is_resolved(): void
    {
        $this->keyed();
        $this->assertInstanceOf(Groq::class, app(ChatModel::class));

        AiSetting::current()->update(['provider' => AiSetting::PROVIDER_ANTHROPIC]);
        // Resolved per use rather than cached: a queue worker lives for
        // minutes and the provider can change underneath it.
        $this->assertInstanceOf(Claude::class, app(ChatModel::class));
    }

    public function test_an_unrecognised_provider_falls_back_to_the_free_one(): void
    {
        $this->keyed();
        AiSetting::current()->forceFill(['provider' => 'whatever-was-in-the-column'])->save();

        // Nonsense in the column must not mean nobody gets an answer.
        $this->assertInstanceOf(Groq::class, app(ChatModel::class));
    }
    // ——— The free tier's ceilings ———

    public function test_a_per_minute_rate_limit_is_waited_out_and_retried(): void
    {
        $this->keyed();

        Http::fakeSequence()
            ->push(['error' => ['message' => 'Rate limit reached ... Please try again in 0.2s.']], 429, ['retry-after' => '0'])
            ->push([
                'model' => 'openai/gpt-oss-120b',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Collected ₹1,05,000.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]);

        $turn = (new Groq)->reply('system', [['said' => 'how are we doing?']], []);

        // The owner's third question in a minute is not an outage.
        $this->assertSame('Collected ₹1,05,000.', $turn->text);
        Http::assertSentCount(2);
    }

    public function test_a_wait_longer_than_the_cap_is_reported_rather_than_slept_through(): void
    {
        $this->keyed();
        Http::fake(['api.groq.com/*' => Http::response(
            ['error' => ['message' => 'Rate limit reached on requests per day. Please try again in 3600.0s.']],
            429,
        )]);

        // A day's quota is not a burst: say so rather than park a worker on it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requests per day');

        (new Groq)->reply('system', [['said' => 'hello']], []);
    }

    public function test_a_model_that_refuses_the_reasoning_settings_is_asked_again_without_them(): void
    {
        $this->keyed(['model' => 'groq/compound']);

        Http::fakeSequence()
            ->push(['error' => ['message' => '`reasoning_effort` is not supported with this model']], 400)
            ->push([
                'model' => 'groq/compound',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]);

        $turn = (new Groq)->reply('system', [['said' => 'hello']], []);

        $this->assertSame('ok', $turn->text);
        Http::assertSentCount(2);
    }

    public function test_any_other_four_hundred_is_not_retried(): void
    {
        $this->keyed();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'model `made-up` does not exist']], 400)]);

        $this->expectException(RuntimeException::class);

        try {
            (new Groq)->reply('system', [['said' => 'hello']], []);
        } finally {
            // Retrying a typo into the same wall is not a strategy.
            Http::assertSentCount(1);
        }
    }

    public function test_names_and_figures_are_never_sampled(): void
    {
        $this->keyed();
        $this->fakeReply(['role' => 'assistant', 'content' => 'ok']);

        (new Groq)->reply('system', [['said' => 'who is on tomorrow?']], []);

        $body = $this->sentBody();
        // Everything it says is copied from a tool. Sampling is what turns
        // "Annamalai" into "Annanmali" on the way out.
        $this->assertSame(0, $body['temperature']);
        $this->assertSame('hidden', $body['reasoning_format']);
    }
}