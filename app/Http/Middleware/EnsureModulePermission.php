<?php

namespace App\Http\Middleware;

use App\Support\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route behind one module ability: ->middleware('module:scripts,edit').
 *
 * Applied to a whole route group in the same spirit as EnsureUserIsAdmin, so a
 * new route inside the group is protected by default rather than by someone
 * remembering. Controllers still authorize the sharper abilities (create,
 * delete) per action.
 *
 * An unregistered module or ability is refused rather than allowed. A typo in
 * a route definition should lock the door, not leave it open.
 */
class EnsureModulePermission
{
    public function handle(Request $request, Closure $next, string $module, string $ability = 'view'): Response
    {
        abort_unless(Permission::isGrantable($module, $ability), 403);
        abort_unless($request->user()?->can($module.'.'.$ability), 403);

        return $next($request);
    }
}
