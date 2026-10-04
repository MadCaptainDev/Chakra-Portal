<?php

namespace App\Mcp;

use App\Models\McpCallLog;
use App\Models\McpToken;
use App\Models\User;
use App\Tools\AdReports;
use App\Tools\Clients;
use App\Tools\CreateTodo;
use App\Tools\DescribeData;
use App\Tools\Finance;
use App\Tools\FindClient;
use App\Tools\Insights;
use App\Tools\InvoiceLookup;
use App\Tools\ListScripts;
use App\Tools\ListShoots;
use App\Tools\ListTimesheet;
use App\Tools\ListTodos;
use App\Tools\LogTimesheetEntry;
use App\Tools\ReelPlannerToday;
use App\Tools\RunQuery;
use App\Tools\SetTodoStatus;
use App\Tools\ShootsBetween;
use App\Tools\StudioFigures;
use App\Tools\Tool;
use App\Tools\ToolException;
use App\Tools\Proposals;
use App\Tools\Quotations;
use App\Tools\Shoots;
use App\Tools\WhoAmI;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The MCP server: one JSON-RPC message in, one answer out.
 *
 * Stateless on purpose. The specification allows a Streamable HTTP server to
 * keep a session across requests, and this one does not -- there is no session
 * id issued and nothing held between calls. That is not a shortcut: this app
 * runs on shared hosting with no long-lived process to hold state in, and a
 * server that pretends otherwise would work in testing and fall apart the first
 * time two requests landed on different workers.
 *
 * What is deliberately absent: resources, prompts, sampling, and SSE streaming.
 * Each is a real part of the protocol and none of them earns its keep here --
 * every capability advertised is one a client may call, and one that has to be
 * right. The capabilities block says tools and only tools.
 */
class Server
{
    public const NAME = 'chakra-portal';

    public const VERSION = '1.1.0';

    /**
     * The house rules every client is handed at the handshake, and shown on
     * the Developer page. Written as numbered rules because a model follows
     * rules more reliably than it infers them from tool descriptions.
     */
    public const INSTRUCTIONS = 'The Chakra Productions studio portal (a video and social-media studio in '
        .'India; money is in rupees). Rules: '
        .'1) Call whoami first. Every tool answers as the person whose token this is, and "today" '
        .'means the studio\'s today. Dates are YYYY-MM-DD, times HH:MM 24-hour, studio local time. '
        .'2) Never invent a number, a name or a price. Report only figures a tool returned; if a tool '
        .'returns nothing, say so. '
        .'3) Pass a client id when you have one. If a tool says a name matches more than one client, '
        .'ask the person which -- never pick one yourself. '
        .'4) Tools with openWorldHint message a real client on WhatsApp (send_ad_report, '
        .'send_quotation_whatsapp, send_brief_reminder, share/send proposal, replying to proposal '
        .'comments). Call them only when the person explicitly asked for that send, then say who it '
        .'went to. "sent" means WhatsApp accepted it, not that it was read. '
        .'5) After a tool changes data, read back what it returned so the person can check it. Do not '
        .'silently retry a write that failed. '
        .'6) Timesheets record work already done; to-dos record work still to do. '
        .'7) Proposals: read proposal_guide before writing one. '
        .'8) There are no tools to record payments, approve invoices, read client passwords or delete '
        .'clients -- send the person to the portal for those.';

    /**
     * Every tool, in the order a model meets them.
     *
     * whoami first because it is the one that orients everything else, and
     * the owner's figures last because they are the narrowest audience.
     *
     * This is the studio's one tool list. Two front doors read it: this
     * server, for Claude on a laptop, and AdminAgent, for the owner's
     * WhatsApp. Adding a tool here adds it to both.
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        return [
            new WhoAmI,
            new ListTimesheet,
            new LogTimesheetEntry,
            new ListTodos,
            new CreateTodo,
            new SetTodoStatus,
            new ListShoots,
            new Shoots\ShootsRollingNow,
            new Shoots\CreateShoot,
            new Shoots\AssignShootCrew,
            new ListScripts,
            new ReelPlannerToday,
            /*
             * Proposals, end to end: writing the document, working through
             * the client's comments, sharing and sending it. MCP only (see
             * Tool::mcpOnly) and gated by the Proposals module, ability by
             * ability, like the screens.
             */
            new Proposals\ProposalGuide,
            new Proposals\ListProposals,
            new Proposals\GetProposal,
            new Proposals\CreateProposal,
            new Proposals\UpdateProposal,
            new Proposals\WriteProposalSections,
            new Proposals\ArrangeProposalSections,
            new Proposals\ListProposalComments,
            new Proposals\ReplyToProposalComment,
            new Proposals\SetProposalCommentStatus,
            new Proposals\ShareProposal,
            new Proposals\SendProposalWhatsapp,
            new Proposals\DeleteProposal,
            // Quotations: drafted from line items, sent only when asked.
            new Quotations\ListQuotations,
            new Quotations\CreateQuotation,
            new Quotations\SendQuotationWhatsapp,
            // Clients: what they told us, and how their Instagram is doing.
            new Clients\GetClientBrief,
            new Clients\SendBriefReminder,
            new Clients\InstagramPerformance,
            // Money owed to the studio, each behind its own module.
            new Finance\MoneyDue,
            new Finance\ClientAdvancesOwed,
            new Finance\LogClientAdvance,
            // Insights, read-only, behind the Insights module.
            new Insights\NeedsAttention,
            new Insights\PaymentBehaviour,
            new Insights\HoursVsRevenue,
            // Monthly Meta Ads reports. Importing one is admin-only.
            new AdReports\ListAdReports,
            new AdReports\ImportAdReport,
            new AdReports\SendAdReport,
            /*
             * The owner's half. These began life as a separate set for the
             * WhatsApp assistant and were merged in, because two lists of
             * tools over one database is two places to add the next one and
             * two places for them to drift apart. Everything above is filtered
             * by module permission; everything here is filtered by
             * requiresAdmin(), because reading across every client's money
             * belongs to no module.
             */
            ...StudioFigures::all(),
            new FindClient,
            new InvoiceLookup,
            new ShootsBetween,
            new DescribeData,
            new RunQuery,
        ];
    }

    /**
     * The tools this person may actually call.
     *
     * Filtered rather than refused at call time, so a writer with no Shoots
     * permission is never told a shoots tool exists. A model shown a tool it
     * cannot use will keep trying it, and the person watching gets a stream of
     * refusals instead of an answer.
     *
     * @return list<Tool>
     */
    public function toolsFor(User $user): array
    {
        return array_values(array_filter(
            $this->tools(),
            // Through the Gate rather than asking the user directly, so admins
            // are answered by Gate::before exactly as they are everywhere else.
            fn (Tool $tool) => (! $tool->requiresAdmin() || $user->isAdmin())
                && ($tool->permission() === null
                    || Gate::forUser($user)->allows($tool->permission()))
        ));
    }

    /**
     * Handle one message. Returns null for a notification, which gets no reply.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, User $user): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (! is_string($method)) {
            return Protocol::isNotification($message)
                ? null
                : Protocol::error($id, Protocol::INVALID_REQUEST, 'No method given.');
        }

        /*
         * Notifications are acknowledged by silence. The one that matters is
         * notifications/initialized, which a client sends after the handshake
         * and expects nothing back from; answering it is a protocol violation.
         */
        if (Protocol::isNotification($message)) {
            return null;
        }

        return match ($method) {
            'initialize' => Protocol::result($id, $this->initialize($params)),
            'ping' => Protocol::result($id, (object) []),
            'tools/list' => Protocol::result($id, ['tools' => array_map(
                fn (Tool $tool) => $tool->describe(),
                $this->toolsFor($user)
            )]),
            'tools/call' => $this->call($id, $params, $user),
            // Answered rather than refused: some clients probe for these during
            // start-up and log an error if the method is unknown, even when the
            // capability was never advertised.
            'resources/list' => Protocol::result($id, ['resources' => []]),
            'prompts/list' => Protocol::result($id, ['prompts' => []]),
            default => Protocol::error($id, Protocol::METHOD_NOT_FOUND, 'Unknown method: '.$method),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => Protocol::negotiate($params['protocolVersion'] ?? null),
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::NAME, 'version' => self::VERSION],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(string|int|null $id, array $params, User $user): array
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = collect($this->toolsFor($user))->first(fn (Tool $t) => $t->name() === $name);

        if (! $tool) {
            return Protocol::error($id, Protocol::INVALID_PARAMS, 'No such tool: '.(is_string($name) ? $name : '(none given)'));
        }

        // Every call lands in mcp_call_logs for the Developer page's Activity
        // tab: what was asked, whether it worked, how long it took.
        $started = hrtime(true);
        $token = request()->attributes->get('mcp_token');
        $log = fn (bool $ok, ?string $error) => McpCallLog::record(
            $user,
            $token instanceof McpToken ? $token : null,
            $tool->name(),
            $arguments,
            $ok,
            $error,
            (int) ((hrtime(true) - $started) / 1_000_000),
        );

        try {
            $result = Protocol::result($id, Protocol::toolResult($tool->handle($arguments, $user)));
            $log(true, null);

            return $result;
        } catch (ToolException $e) {
            // The tool's own "I could not", which the model is meant to read.
            $log(false, $e->getMessage());

            return Protocol::result($id, Protocol::toolError($e->getMessage()));
        } catch (Throwable $e) {
            $log(false, 'Server error -- details in laravel.log.');

            /*
             * Anything else is a bug. Logged in full, and reported to the model
             * as a flat apology -- an exception message can carry a table name,
             * a file path or a fragment of somebody's data, and this endpoint
             * hands its output to a third party by design.
             */
            Log::error('MCP tool failed', [
                'tool' => $tool->name(),
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return Protocol::result($id, Protocol::toolError(
                'That did not work, and the problem is at the studio\'s end rather than yours. '
                .'It has been logged.'
            ));
        }
    }
}
