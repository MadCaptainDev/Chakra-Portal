<?php

namespace App\Http\Controllers;

use App\Mcp\Protocol;
use App\Mcp\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Streamable HTTP transport, in its plain-JSON form.
 *
 * The protocol allows a server to answer either with one JSON object or with an
 * SSE stream. This answers with JSON, always. Streaming would need a long-lived
 * connection, and this app runs on shared hosting behind a proxy that will close
 * one -- a transport that works on the developer's machine and times out in
 * production is worse than one that never claimed to stream.
 *
 * Nothing here is chatty by nature: every tool is a database read or a single
 * write, all of which return in milliseconds.
 */
class McpController extends Controller
{
    public function __invoke(Request $request, Server $server): JsonResponse|Response
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(
                Protocol::error(null, Protocol::PARSE_ERROR, 'That was not JSON.'),
                400
            );
        }

        $user = $request->user();

        /*
         * A batch -- an array of messages rather than one. Removed from the
         * 2025-06-18 revision, still sent by clients built against the two
         * before it, and cheap enough to keep answering.
         */
        if (array_is_list($payload)) {
            if ($payload === []) {
                return response()->json(
                    Protocol::error(null, Protocol::INVALID_REQUEST, 'An empty batch says nothing.'),
                    400
                );
            }

            $replies = [];

            foreach ($payload as $message) {
                if (! is_array($message)) {
                    $replies[] = Protocol::error(null, Protocol::INVALID_REQUEST, 'That was not a JSON-RPC message.');

                    continue;
                }

                $reply = $server->handle($message, $user);

                if ($reply !== null) {
                    $replies[] = $reply;
                }
            }

            // A batch of nothing but notifications earns the same silence one
            // notification would.
            return $replies === []
                ? response()->noContent(202)
                : response()->json($replies);
        }

        $reply = $server->handle($payload, $user);

        return $reply === null
            ? response()->noContent(202)
            : response()->json($reply);
    }
}
