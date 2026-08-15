<?php

namespace App\Http\Middleware;

use App\Mcp\Protocol;
use App\Models\McpToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door to the MCP endpoint.
 *
 * Everything past here runs as a real user with their real permissions. This is
 * the only place that decides who that is.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Origin checking, which the specification asks of every HTTP MCP
         * server. A command-line client sends no Origin at all, so its absence
         * is fine; a *browser* always sends one, so a page on some other site
         * cannot quietly POST here using a token it somehow obtained. This is
         * the DNS-rebinding guard, and it costs three lines.
         */
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! $this->isOwnOrigin($origin)) {
            return $this->refuse('Requests from that origin are not accepted.', 403);
        }

        $token = McpToken::resolve($request->bearerToken());

        if (! $token || ! $token->user) {
            // WWW-Authenticate because the specification says a 401 from an MCP
            // server carries one, and clients read it to know what to send.
            return $this->refuse('A valid bearer token is required.', 401)
                ->header('WWW-Authenticate', 'Bearer realm="Chakra Portal"');
        }

        $token->touchLastUsed();

        /*
         * Bound onto the request rather than into the session guard. There is
         * no session here -- this route deliberately runs outside the web
         * middleware, so nothing is remembered between calls and a token can
         * never be upgraded into a browser login.
         */
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('mcp_token', $token);

        return $next($request);
    }

    private function isOwnOrigin(string $origin): bool
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $originHost = parse_url($origin, PHP_URL_HOST);

        return $appHost !== null && $originHost !== null && strcasecmp($appHost, $originHost) === 0;
    }

    /**
     * Refusals speak JSON-RPC too, so a client's error handling works on them
     * rather than choking on an HTML error page.
     */
    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(
            Protocol::error(null, Protocol::INVALID_REQUEST, $message),
            $status
        );
    }
}
