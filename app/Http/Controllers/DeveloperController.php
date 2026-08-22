<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * The API reference for whoever is writing the client side of the backup +
 * license platform (routes/saas-api.php) -- an interactive Swagger UI, not
 * just prose. Deliberately its own top-level page under App Studio, not
 * nested inside any one SaaS product: the API shape is the same for every
 * product, and burying it in a product's own show page would mean nobody
 * finds it without already knowing which product to open first.
 */
class DeveloperController extends Controller
{
    public function index(): View
    {
        return view('developer.index');
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
