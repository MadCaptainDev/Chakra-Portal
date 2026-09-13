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
     * A query question is three rounds at its best -- find the columns, run
     * the SELECT, answer -- and four when the first column name was wrong,
     * which happens. Seven leaves room for that and for a follow-up lookup,
     * while still stopping a model that has decided to keep looking things up
     * while somebody holds a phone.
     */
    public const MAX_STEPS = 7;

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
        WhatsappSender::make()->sendText($waId, $this->compose($admin, $waId, $question));
    }

    /**
     * Everything except the sending.
     *
     * Split out so the answer can be seen without spending a WhatsApp
     * message: `assistant:ask` on the server reaches this, which is how
     * somebody tells a missing key from a spent quota from a stopped queue
     * worker without texting the studio's number and waiting.
     */
    public function compose(User $admin, string $waId, string $question): string
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
        $lookups = [];

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

                // Kept for two jobs, both in bodyFor(): they are what the
                // reply is checked against before it is sent, and they are
                // what gets sent instead when the reply does not survive that
                // check. The tools already write for a phone -- they were
                // written for the owner menu.
                if (! $ran['failed']) {
                    $lookups[] = $ran['output'];
                }

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

        $body = $this->bodyFor($turn, $calls, $lookups);

        $this->remember($waId, $admin, AdminAgentMessage::ROLE_ASSISTANT, $body, $calls, $turn);

        AiSetting::current()->forceFill(['last_answered_at' => now()])->save();

        return $body;
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
    private function bodyFor(?ModelTurn $turn, array $calls, array $lookups = []): string
    {
        if ($turn === null) {
            return $calls === []
                ? "I couldn't work that out. Try asking a different way, or type *menu*."
                : "I looked that up but couldn't finish the answer. Try asking for one thing at a time, or type *menu*.";
        }

        if ($turn->isRefusal()) {
            return filled($turn->text)
                ? self::tidy($turn->text)
                : "I can't answer that one. Type *menu* for the usual figures.";
        }

        /*
         * The open model on the free tier occasionally elides its own answer
         * mid-word -- a real reply, stored in the transcript, read
         * "Digital Harvest (Jan[zero-width] ...". It is rare, it survives
         * temperature 0, and no instruction prevents it.
         *
         * So when the words are untrustworthy, send the figures instead. The
         * tools were written for the owner menu and already read well on a
         * phone, so the fallback is a plainer answer rather than an apology --
         * and the owner gets the thing they asked for either way.
         */
        if (self::looksMangled($turn->text)) {
            Log::warning('Admin assistant discarded a mangled reply.', ['reply' => $turn->text]);

            return $this->insteadOfTheReply($lookups);
        }

        /*
         * The one error worth this much machinery. Asked what was overdue,
         * the model read "₹32,500" off the tool and wrote "₹32,000" -- one
         * digit, silently, in a message about money somebody is owed. It
         * survived temperature zero and it survived being told twice to copy
         * figures exactly, because nothing in a language model guarantees the
         * token it copies is the token it read.
         *
         * So it is checked rather than asked for: every rupee amount in the
         * reply must appear in something a tool returned this turn. Only
         * rupee amounts -- a percentage or "9 hours 30 minutes" is arithmetic
         * the model is supposed to be doing, and those are the answers the
         * owner asked for.
         */
        if (self::inventsMoney($turn->text, $lookups)) {
            Log::warning('Admin assistant stated a figure no tool returned.', [
                'reply' => $turn->text,
                'lookups' => $lookups,
            ]);

            return $this->insteadOfTheReply($lookups);
        }

        return filled($turn->text)
            ? self::tidy($turn->text)
            : "I didn't have anything to add there. Type *menu* for the usual figures.";
    }

    /**
     * What to send when the reply itself cannot be trusted.
     *
     * The last thing a tool returned, which is the answer in plainer words --
     * these tools were written for the owner menu and read fine on a phone.
     * Failing that, a sentence, because a wrong figure is worse than none and
     * silence is worse than both.
     *
     * @param  list<string>  $lookups
     */
    private function insteadOfTheReply(array $lookups): string
    {
        $last = end($lookups);

        // Tested for as a string rather than with filled(): end() answers
        // `false` on an empty array, and `filled(false)` is true -- which
        // sends an empty WhatsApp message, the one outcome worse than a
        // wrong figure.
        return is_string($last) && trim($last) !== ''
            ? self::tidy($last)
            : "I couldn't put that together reliably. Ask me again, or type *menu*.";
    }

    /**
     * Whether the reply states a rupee amount that no tool produced.
     *
     * Compared as bare digits, so ₹1,05,000 and ₹105,000 and 105000 are one
     * number -- the studio's own lakh grouping must not read as a mismatch.
     *
     * @param  list<string>  $lookups
     */
    private static function inventsMoney(string $text, array $lookups): bool
    {
        preg_match_all('/₹\s*([\d.,]+)/u', $text, $stated);

        $stated = array_filter(array_map(self::digits(...), $stated[1] ?? []));

        if ($stated === []) {
            return false;
        }

        $known = [];

        foreach ($lookups as $lookup) {
            preg_match_all('/\d[\d.,]*/', (string) $lookup, $numbers);
            $known = array_merge($known, array_map(self::digits(...), $numbers[0] ?? []));
        }

        foreach ($stated as $amount) {
            if (! in_array($amount, $known, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A number as the digits it is made of.
     *
     * Grouping separators go, so ₹1,05,000 and ₹105,000 are one number. A
     * decimal tail goes only when it is zeroes -- 32500.00 and 32500 are the
     * same money, while 32.5 and 325 are not, and collapsing those would let
     * the error this exists to catch straight through.
     */
    private static function digits(string $number): string
    {
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return (string) preg_replace('/\D/', '', $number);
    }

    /**
     * Whether a reply gave up partway through.
     *
     * A zero-width space is the tell, and it is a reliable one: nothing that
     * legitimately reaches this assistant contains one -- not a client name,
     * not a rupee figure, not anything a tool produced -- while every mangled
     * reply seen so far has. Cheaper and steadier than trying to guess from
     * the shape of the text whether a sentence finished.
     */
    private static function looksMangled(string $text): bool
    {
        return preg_match('/[\x{200B}-\x{200D}\x{FEFF}]/u', $text) === 1;
    }

    /**
     * Invisible characters out, trailing whitespace off each line.
     *
     * Unconditional, because a zero-width space has no business in a WhatsApp
     * message even when the rest of the reply is sound -- it is invisible on
     * the phone and breaks search and copy-paste for whoever reads it later.
     */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        return trim((string) preg_replace('/[ \t]+$/m', '', $text));
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
    /**
     * What the assistant is told about itself.
     *
     * Kept tight on purpose. Every word here is resent on every call, and the
     * free tier allows eight thousand tokens a minute -- a prompt that
     * wandered would cost the owner their second question rather than buying
     * better answers. The formatting rules earn their place because WhatsApp
     * has no headings and no tables, and a model left to its habits sends
     * "## Outstanding" and a pipe table to a phone.
     */
    private function system(User $admin): string
    {
        return implode("\n", [
            'You are a private assistant to the owner of Chakra Groups, a photo and video production studio in India, over their own WhatsApp. You work for this one person.',
            'You are not the studio\'s front desk. Never greet, introduce yourself, sign off, or offer further help. Answer and stop.',
            '',
            'How to answer:',
            '- Never state a figure, date, name or status you have not read from a tool this turn. Never guess one.',
            '- Copy names, figures and places exactly as the tool wrote them, character for character. Never re-spell a person\'s name or fix what looks like a typo — these are real people.',
            '- Send the finished answer only: no working out, no correcting yourself, no trailing "Actually...".',
            '- Short. Two or three lines, leading with the number or the fact.',
            '- No markdown: no #, |, ** or tables. *One asterisk* either side is bold, a plain dash for a list. Money as ₹1,20,000.',
            '- If a name could mean several clients, list the matches and ask which.',
            '- Work out "Thursday" or "next week" from today\'s date below; pass tools YYYY-MM-DD.',
            '',
            'You can read every part of the portal. money_summary, overdue_invoices, todays_shoots and timesheet_gaps are one-call answers to the four commonest questions — use them when they fit. For anything else, call describe_data for the real tables and columns, then run_query with a SELECT. Money received is `payments`, money billed is `invoices`, hours are `timesheet_entries`, jobs are `shoots`.',
            '- Aggregate in SQL (SUM, COUNT, GROUP BY, ROUND), never by adding up rows yourself.',
            '- Comparing a part against a whole — who paid the most, which venture took the most hours — put the percentage beside the figure and say what it is a share of.',
            '- Never put a raw id in an answer. Join to the name — clients.name, users.name. "Client 6 paid the most" is not an answer to a person who knows their clients by name.',
            '- Never say you cannot answer until a query has actually failed.',
            '',
            'You can read anything and change nothing: no invoices, payments, shoots or crew. Asked to create or alter something, say plainly that it has to be done on the portal and offer the figure instead. Never imply you did it.',
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
