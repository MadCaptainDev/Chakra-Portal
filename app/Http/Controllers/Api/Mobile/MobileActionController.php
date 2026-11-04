<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Mcp\Server;
use App\Tools\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every portal action the app can take, through the same tool classes the
 * MCP server and the WhatsApp assistant use (app/Tools). One rulebook: what
 * a person may do here is exactly what their role and permissions allow.
 *
 * GET  /actions          what this person can run, with JSON-schema inputs
 * POST /actions/{name}   run one; body = the arguments object
 */
class MobileActionController extends Controller
{
    public function index(Request $request, Server $server): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (Tool $tool) => [
                'name' => $tool->name(),
                'title' => $tool->title(),
                'group' => $tool->group(),
                'description' => $tool->description(),
                'input_schema' => $tool->schema(),
                'read_only' => $tool->isReadOnly(),
                'destructive' => $tool->isDestructive(),
                // true = it WhatsApps a real client. Confirm with the person first.
                'messages_client' => $tool->messagesClient(),
            ], $server->toolsFor($request->user())),
        ]);
    }

    public function run(Request $request, Server $server, string $name): JsonResponse
    {
        $arguments = $request->json()->all();
        $outcome = $server->runForApp($name, is_array($arguments) ? $arguments : [], $request->user());

        return response()->json(
            $outcome['ok'] ? ['ok' => true, 'result' => $outcome['result']] : ['ok' => false, 'message' => $outcome['error']],
            $outcome['status'],
        );
    }
}
