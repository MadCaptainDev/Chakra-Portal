<?php

namespace App\Http\Middleware;

use App\Models\SaasProduct;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door to the backup/license API -- same shape as
 * AuthenticateMcpToken, except the principal is a SaasProduct, not a User,
 * and there is no `->user` on it to check.
 */
class AuthenticateSaasProduct
{
    public function handle(Request $request, Closure $next): Response
    {
        // Same DNS-rebinding guard AuthenticateMcpToken uses: a command-line
        // backup script sends no Origin at all, which is fine; a browser
        // always sends one, so a page on some other site cannot quietly call
        // this endpoint with a token it somehow obtained.
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! $this->isOwnOrigin($origin)) {
            return $this->refuse('Requests from that origin are not accepted.', 403);
        }

        $product = SaasProduct::resolveToken($request->bearerToken());

        if (! $product) {
            return $this->refuse('A valid bearer token is required.', 401)
                ->header('WWW-Authenticate', 'Bearer realm="Chakra Portal"');
        }

        // Bound onto the request, not a session -- this route deliberately
        // runs outside the web middleware group, so nothing is remembered
        // between calls.
        $request->attributes->set('saas_product', $product);

        return $next($request);
    }

    private function isOwnOrigin(string $origin): bool
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $originHost = parse_url($origin, PHP_URL_HOST);

        return $appHost !== null && $originHost !== null && strcasecmp($appHost, $originHost) === 0;
    }

    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
