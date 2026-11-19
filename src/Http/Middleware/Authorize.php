<?php

namespace KdrDev\Dissect\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may open the viewer, once the routes are registered at all.
 *
 * Registration is decided by `dissect.enabled`, which defaults to "APP_ENV is
 * local". That is an environment check, not access control: a staging box
 * left at APP_ENV=local, or DISSECT_ENABLED switched on to debug something,
 * serves the schema, every route and live queue contents to anybody who finds
 * the path. So the routes carry this as well, appended after the configurable
 * middleware where a config edit cannot drop it.
 *
 * Local is always let in: that is the machine the package is for, and asking
 * for a gate there would only be friction. Anywhere else the application has
 * to say yes, by defining the `viewDissect` gate. An application that has not
 * defined it gets a 403, which is the point: being reachable outside local is
 * a decision somebody has to write down.
 */
class Authorize
{
    public const GATE = 'viewDissect';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        // The user is passed explicitly so a gate can accept guests with a
        // nullable parameter — useful behind a VPN or basic auth.
        if (Gate::has(self::GATE) && Gate::check(self::GATE, [$request->user()])) {
            return $next($request);
        }

        abort(403);
    }
}
