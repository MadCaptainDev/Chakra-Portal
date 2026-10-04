<?php

namespace App\Http\Controllers;

use App\Mcp\Server;
use App\Models\McpCallLog;
use App\Tools\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The Developer space: everything needed to connect an AI client to the
 * portal and keep an eye on what it does.
 *
 * The tool reference is built from Mcp\Server::toolsFor() at request time,
 * never written out by hand, so it cannot drift from what a token actually
 * gets -- and it shows each person only the tools their own permissions
 * allow, exactly as tools/list does.
 *
 * The SaaS backup + license API keeps its own Swagger page (saasApi), still
 * gated by SaaS Products.
 */
class DeveloperController extends Controller
{
    /**
     * Older tools predate Tool::group(); this files them for the reference
     * page without touching each class.
     */
    private const LEGACY_GROUPS = [
        'whoami' => 'General',
        'list_timesheet' => 'Timesheet & to-dos',
        'log_timesheet_entry' => 'Timesheet & to-dos',
        'list_todos' => 'Timesheet & to-dos',
        'create_todo' => 'Timesheet & to-dos',
        'set_todo_status' => 'Timesheet & to-dos',
        'list_shoots' => 'Shoots',
        'shoots_between' => 'Shoots',
        'list_scripts' => 'Content',
        'reel_planner_today' => 'Content',
        'find_client' => 'Clients',
        'invoice_lookup' => 'Finance',
        'describe_data' => 'Studio data (admin)',
        'run_query' => 'Studio data (admin)',
    ];

    public function index(Request $request, Server $server): View
    {
        $user = $request->user();

        $tools = collect($server->toolsFor($user))->map(fn (Tool $tool) => [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'group' => $this->groupOf($tool),
            'description' => $tool->description(),
            'inputs' => $this->inputsOf($tool),
            'read_only' => $tool->isReadOnly(),
            'destructive' => $tool->isDestructive(),
            'messages_client' => $tool->messagesClient(),
            'admin_only' => $tool->requiresAdmin(),
            'permission' => $tool->permission(),
            'whatsapp_assistant' => ! $tool->mcpOnly(),
        ]);

        $logs = McpCallLog::query()
            ->with(['user:id,name', 'token:id,name'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get();

        $weekQuery = McpCallLog::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', now()->subDays(7));

        $tab = in_array($request->query('tab'), ['connect', 'tokens', 'tools', 'activity', 'apis'], true)
            ? $request->query('tab')
            : (session('mcp_token_plain') || session('status') ? 'tokens' : 'connect');

        return view('developer.index', [
            'user' => $user,
            'tab' => $tab,
            'endpoint' => route('mcp'),
            'serverVersion' => Server::VERSION,
            'instructions' => Server::INSTRUCTIONS,
            'tools' => $tools,
            'toolGroups' => $tools->groupBy('group')->sortKeys(),
            'mcpTokens' => $user->mcpTokens()->latest()->get(),
            'logs' => $logs,
            'week' => [
                'calls' => (clone $weekQuery)->count(),
                'failed' => (clone $weekQuery)->where('ok', false)->count(),
                'avg_ms' => (int) (clone $weekQuery)->avg('duration_ms'),
            ],
            'canSeeSaasApi' => $user->can('saas-products.manage'),
        ]);
    }

    public function saasApi(): View
    {
        return view('developer.saas-api');
    }

    private function groupOf(Tool $tool): string
    {
        if ($tool->group() !== 'General') {
            return $tool->group();
        }

        if (isset(self::LEGACY_GROUPS[$tool->name()])) {
            return self::LEGACY_GROUPS[$tool->name()];
        }

        // Proposals\*, and StudioFigures' figures, by where they live.
        $namespace = Str::of(get_class($tool))->after('App\\Tools\\')->before('\\')->toString();

        return match (true) {
            $namespace === 'Proposals' => 'Proposals',
            $tool->requiresAdmin() => 'Studio figures (admin)',
            default => 'General',
        };
    }

    /**
     * The schema's properties as rows a person can read.
     *
     * @return list<array{name: string, type: string, required: bool, description: string}>
     */
    private function inputsOf(Tool $tool): array
    {
        $schema = $tool->schema();
        $required = $schema['required'] ?? [];

        return collect($schema['properties'] ?? [])->map(fn (array $prop, string $name) => [
            'name' => $name,
            'type' => isset($prop['enum'])
                ? implode(' | ', $prop['enum'])
                : (is_array($prop['type'] ?? null) ? implode(' | ', $prop['type']) : ($prop['type'] ?? 'any')),
            'required' => in_array($name, $required, true),
            'description' => (string) ($prop['description'] ?? ''),
        ])->values()->all();
    }

    /**
     * The OpenAPI document Swagger UI renders. Built by hand rather than
     * generated from route attributes -- there are exactly four endpoints,
     * and a generator would be more code than the document it produces.
     * Keep this in sync with docs/SAAS_INTEGRATION.md and the controllers
     * under app/Http/Controllers/Api/ when either changes.
     */
    public function openapi(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Chakra Portal — SaaS Backup & License API',
                'version' => '1.0.0',
                'description' => "The API a client-built app (App Studio's own software, e.g. an ERP) ".
                    "calls from its own server: pushing versioned backups, checking whether its AMC is ".
                    "paid up, and reading its own configuration. Every request authenticates with a bearer ".
                    "token issued once per product under SaaS Products, never a session.\n\n".
                    "Chakra Portal can only ever answer the license check truthfully — it has no access to ".
                    "the client software's own server and cannot itself stop anything running there.",
            ],
            'servers' => [
                ['url' => url('/'), 'description' => 'Chakra Portal'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => "A SaaS product's token, issued (and re-issued) from its page under SaaS Products. Sent as 'Authorization: Bearer saas_...'.",
                    ],
                ],
                'schemas' => [
                    'Backup' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 42],
                            'taken_at' => ['type' => 'string', 'format' => 'date-time'],
                            'size_bytes' => ['type' => 'integer', 'example' => 18874368],
                            'checksum' => ['type' => 'string', 'description' => 'SHA-256 of the exact bytes received.'],
                        ],
                    ],
                    'License' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['active', 'overdue', 'suspended']],
                            'message' => ['type' => 'string', 'description' => 'Plain text, safe to show verbatim in the client software\'s own UI.'],
                            'amc_paid_until' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        ],
                    ],
                    'Config' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'backup_retention_count' => ['type' => 'integer'],
                            'amc_frequency' => ['type' => 'string', 'enum' => ['monthly', 'quarterly', 'yearly'], 'nullable' => true],
                        ],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => ['error' => ['type' => 'string']],
                    ],
                ],
            ],
            'security' => [['bearerAuth' => []]],
            'paths' => [
                '/api/saas/backups' => [
                    'post' => [
                        'summary' => 'Upload a backup',
                        'tags' => ['Backups'],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['file'],
                                        'properties' => [
                                            'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'Up to 1 GB. Any filename — never trusted as the storage path.'],
                                            'taken_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Defaults to now if omitted. What backups are sorted and pruned by.'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'Stored.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Backup']]]],
                            '401' => ['description' => 'Missing or invalid token.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                            '422' => ['description' => 'Validation failed (e.g. file too large).'],
                        ],
                    ],
                    'get' => [
                        'summary' => 'List this product\'s backups',
                        'tags' => ['Backups'],
                        'description' => 'Newest first. What a restore script calls to pick a version.',
                        'responses' => [
                            '200' => [
                                'description' => 'OK.',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Backup']]],
                                ]]],
                            ],
                            '401' => ['description' => 'Missing or invalid token.'],
                        ],
                    ],
                ],
                '/api/saas/backups/{id}/download' => [
                    'get' => [
                        'summary' => 'Download one backup',
                        'tags' => ['Backups'],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'The raw file, streamed.'],
                            '404' => ['description' => 'No such backup for this product — including one that belongs to a different product. Never 403: a token has no business learning that a foreign id exists.'],
                        ],
                    ],
                ],
                '/api/saas/license' => [
                    'get' => [
                        'summary' => 'Check whether this product should keep running',
                        'tags' => ['License'],
                        'description' => 'Call on startup, then on a timer (hourly is plenty). Cache the last answer so a momentary network blip does not look like a suspension.',
                        'responses' => [
                            '200' => ['description' => 'OK.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/License']]]],
                            '401' => ['description' => 'Missing or invalid token.'],
                        ],
                    ],
                ],
                '/api/saas/config' => [
                    'get' => [
                        'summary' => 'Fetch this product\'s own configuration',
                        'tags' => ['Config'],
                        'description' => 'Read fresh every call — changing the retention count in Chakra Portal takes effect here with no redeploy of the client software.',
                        'responses' => [
                            '200' => ['description' => 'OK.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Config']]]],
                            '401' => ['description' => 'Missing or invalid token.'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
