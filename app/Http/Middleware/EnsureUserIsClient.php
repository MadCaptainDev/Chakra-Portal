<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client area is for clients only -- including keeping staff out.
 *
 * An admin being refused here is deliberate rather than an oversight. Every
 * screen inside answers "what does *my* client see", and it answers it from
 * the signed-in user's own client_id. An admin has none, so the honest options
 * were to refuse them or to invent an impersonation feature; refusing is the
 * one that cannot leak the wrong client's invoices.
 */
class EnsureUserIsClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isClient(), 403);

        /*
         * A client login with no client attached. Possible because deleting a
         * client nulls the column rather than deleting the account -- which is
         * the right trade, but it leaves a login that can sign in and has
         * nothing to be shown. Refused clearly here rather than 500ing on a
         * null relation four screens deep.
         */
        abort_if($user->client_id === null, 403, 'This login is not linked to a client. Ask the studio to fix it.');

        return $next($request);
    }
}
