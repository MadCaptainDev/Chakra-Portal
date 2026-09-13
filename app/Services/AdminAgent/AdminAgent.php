<?php

namespace App\Services\AdminAgent;

use App\Models\AdminAgentMessage;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\WhatsappWebhookEvent;
use App\Services\Ai\ChatModel;
use App\Services\Ai\ModelTurn;
use App\Services\WhatsappSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The studio owner's assistant on WhatsApp: a question in English, an answer
 * in English, and the portal's own data underneath it.
 *
 * Where the owner menu (database/flows/admin_menu.php) answers four fixed
 * questions, this answers the ones nobody wrote a node for -- "how much does
 * Pothys still owe", "what's on Thursday", "who hasn't logged since Monday".
 * The menu stays: it is the fallback when this is switched off, and typing
 * "menu" reaches it deliberately. Two ways in, one set of figures, because
 * every tool here reads through AdminPortal and the models rather than
 * recomputing anything.
 *
 * Read-only, this phase, and enforced by ToolRegistry holding no write tool
 * rather than by the prompt asking nicely.
 *
 * Two gates, not one. claimant() decides whether a message is even ours --
 * an admin's number, the assistant switched on, a key on file, today's
 * ceiling not yet hit. Then every tool re-checks what it needs from the user
 * it is handed. A prompt is not a permission system; it can be talked out of
 * things, and the first gate is the one that cannot.
 */
class AdminAgent
{
    /**
     * How many rounds of tool calls one question gets.
     *
     * Five is room for "who is this client, what do they owe, and what is
     * their last invoice" with margin, and a hard stop on a model that has
     * decided to keep looking things up while somebody holds a phone.
     */
    public const MAX_STEPS = 5;

    /** The word that deliberately reaches the old menu instead. */
    public const MENU_WORD = 'menu';

    public function __construct(
        private readonly ChatModel $model,
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * The admin this inbound message belongs to, or null if the assistant
     * should keep out of it.
     *
     * Null covers every "not ours": a client, a stranger, an employee who is
     * not an admin, the assistant switched off, no key yet, today's ceiling
     * reached, or the admin asking for the menu by name. FlowEngine treats
     * null as "carry on as before", which is what makes this safe to switch
     * off -- the menu is still there underneath.
     */
    public static function claimant(WhatsappWebhookEvent $event): ?User
    {
        if (! AiSetting::current()->isReady()) {
            return null;
        }

        if (mb_strtolower(trim((string) $event->summary)) === self::MENU_WORD) {
            return null;
        }

        /*
         * Only typed words. A tap on a list row is an answer to a menu this
         * assistant did not send, and handing it over would swallow the
         * menu's own replies the moment somebody switched the assistant on.
         */
        if ($event->message_type !== 'text') {
            return null;
        }

        $user = User::findForWhatsappCrew((string) $event->wa_id);

        return $user?->isAdmin() ? $user : null;
    }

    /** Answer one message, and send the answer. */
    public function answer(User $admin, string $waId, string $question): void
    {
        $this->remember($waId, $admin, AdminAgentMessage::ROLE_USER, $question);

        // Loaded after the question is stored, so the question is in it --
        // and so the daily count reflects what was attempted even if the
        // answer below never arrives.
        $conversation = $this->conversation($waId);
        $system = $this->system($admin);
        $definitions = $this->tools->definitions();

        $calls = [];
        $turn = null;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $turn = $this->model->reply($system, $conversation, $definitions);

            if (! $turn->wantsTools()) {
                break;
            }

            $conversation[] = ['turn' => $turn];
            $results = [];

            foreach ($turn->toolCalls as $call) {
                $ran = $this->tools->run($call['name'], $admin, $call['input']);

                $calls[] = ['name' => $call['name'], 'input' => $call['input'], 'failed' => $ran['failed']];

                $results[] = [
                    'id' => $call['id'],
                    'output' => $ran['output'],
                    'failed' => $ran['failed'],
                ];
            }

            // Every result in one message, never one message each: splitting
            // them is what teaches the model to stop asking for tools in
            // parallel, and parallel is why three lookups cost one round.
            $conversation[] = ['ran' => $results];
            $turn = null;
        }

        $body = $this->bodyFor($turn, $calls);

        $this->remember($waId, $admin, AdminAgentMessage::ROLE_ASSISTANT, $body, $calls, $turn);

        AiSetting::current()->forceFill(['last_answered_at' => now()])->save();

        WhatsappSender::make()->sendText($waId, $body);
    }

    /**
     * What to actually send.
     *
     * Three ways there is no answer to send, and all three get a sentence
     * rather than silence: the model refused, it ran out of rounds still
     * looking things up, or it replied with nothing at all. A phone that
     * stays quiet is indistinguishable from the portal being broken -- which
     * is exactly the bug the owner menu had.
     *
     * @param  list<array<string, mixed>>  $calls
     */
    private function bodyFor(?ModelTurn $turn, array $calls): string
    {
        if ($turn === null) {
            return $calls === []
                ? "I couldn't work that out. Try asking a different way, or type *menu*."
                : "I looked that up but couldn't finish the answer. Try asking for one thing at a time, or type *menu*.";
        }

        if ($turn->isRefusal()) {
            return filled($turn->text)
                ? $turn->text
                : "I can't answer that one. Type *menu* for the usual figures.";
        }

        return filled($turn->text)
            ? $turn->text
            : "I didn't have anything to add there. Type *menu* for the usual figures.";
    }

    /** @return list<array<string, mixed>> */
    private function conversation(string $waId): array
    {
        return array_map(
            fn (array $turn) => $turn['role'] === AdminAgentMessage::ROLE_USER
                ? ['said' => $turn['content']]
                : ['turn' => new ModelTurn(text: $turn['content'], assistantContent: $turn['content'])],
            AdminAgentMessage::transcriptFor($waId),
        );
    }

    /**
     * What the assistant is told about itself.
     *
     * Front-loaded with the things that never change and ended with the
     * things that do -- the date, and who is asking -- because this string is
     * the cacheable prefix of every call and a date at the top of it would
     * invalidate that cache once a day for no reason.
     *
     * The formatting rules earn their place: WhatsApp has no headings, no
     * tables and no bullet syntax, so a model left to its own habits sends
     * "## Outstanding" and a pipe table to a phone, and it arrives exactly
     * like that.
     */
    private function system(User $admin): string
    {
        return implode("\n", [
            'You are the assistant for Chakra Groups, a photo and video production studio in India. You are speaking to the studio\'s owner over WhatsApp.',
            '',
            'How to answer:',
            '- Look things up. Never state a figure, a date, a name or a status you have not read from a tool this turn. If no tool can answer, say so plainly.',
            '- Be short. Two or three lines is a good answer; a phone is not a report. Lead with the number or the fact, then only what is needed to read it.',
            '- WhatsApp has no markdown. No headings, no tables, no ### or |. *One asterisk* either side is bold; use a plain dash for a list. Rupee amounts as ₹1,20,000.',
            '- One name can mean several clients. If a name is ambiguous, name the matches and ask which, rather than picking one.',
            '- Dates: work out "next week" or "Thursday" yourself from today\'s date below, and pass the tools YYYY-MM-DD.',
            '',
            'What you cannot do yet: you can read anything in the portal but change nothing — no invoices, no payments, no shoots, no crew. If the owner asks you to create or alter something, say plainly that you can only read for now and that it has to be done on the portal, and offer the figure or the record instead. Do not imply you have done it.',
            '',
            'Today is '.now()->format('l j F Y').'. You are speaking to '.$admin->name.'.',
        ]);
    }

    /** @param list<array<string, mixed>> $calls */
    private function remember(
        string $waId,
        User $admin,
        string $role,
        string $body,
        array $calls = [],
        ?ModelTurn $turn = null,
    ): void {
        try {
            AdminAgentMessage::create([
                'wa_id' => $waId,
                'user_id' => $admin->id,
                'role' => $role,
                'body' => $body,
                'tool_calls' => $calls === [] ? null : $calls,
                'model' => $turn?->model,
                'input_tokens' => $turn?->inputTokens,
                'output_tokens' => $turn?->outputTokens,
            ]);
        } catch (Throwable $e) {
            /*
             * A transcript that cannot be written must not cost the admin
             * their answer. It costs the next message its memory instead,
             * which is the smaller loss and a visible one.
             */
            Log::error('Admin assistant could not record a turn.', [
                'wa_id' => $waId,
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
