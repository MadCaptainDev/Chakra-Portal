<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `portal:<section>` -- 404 a client portal screen the studio has not turned
 * on for this client (see Client::PORTAL_SECTIONS).
 *
 * 404 rather than 403: a section that is off should look like it does not
 * exist, not like something the client is being refused. Runs after
 * EnsureUserIsClient, which guarantees a signed-in client with a client_id.
 */
class EnsurePortalSectionEnabled
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        abort_unless(array_key_exists($section, Client::PORTAL_SECTIONS), 404);

        $client = $request->user()?->client;

        abort_unless($client?->portalSectionEnabled($section), 404);

        return $next($request);
    }
}
