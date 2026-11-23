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
 * Local is let in without a gate, but only when the request is addressed to
 * the developer's own machine: a loopback address, a `.localhost` or `.test`
 * name, the host APP_URL names, or one listed in `dissect.local_hosts`. That
 * is the machine the package is for, and asking for a gate there would only
 * be friction. Any other host goes to the gate like everywhere else, which
 * covers the staging box above when it is reached by its real name, and a
 * page on some other site whose name was pointed at 127.0.0.1 to read this
 * one from the developer's browser: that request still says the other site's
 * name in its Host header.
 *
 * Anywhere else the application has to say yes, by defining the
 * `viewDissect` gate. An application that has not defined it gets a 403,
 * which is the point: being reachable outside local is a decision somebody
 * has to write down.
 */
class Authorize
{
    public const GATE = 'viewDissect';

    public function handle(Request $request, Closure $next): Response
    {
        $local = app()->environment('local');

        if ($local && $this->isOwnMachine($request->getHost())) {
            return $next($request);
        }

        // The user is passed explicitly so a gate can accept guests with a
        // nullable parameter — useful behind a VPN or basic auth.
        if (Gate::has(self::GATE) && Gate::check(self::GATE, [$request->user()])) {
            return $next($request);
        }

        // Said in words in local, where the likely cause is opening the page
        // from a phone or another machine on the network, not an attack.
        abort(403, $local
            ? 'dissect only answers to this machine\'s own names in local. Add "'.$request->getHost().'" to dissect.local_hosts to open it from here.'
            : '');
    }

    /** Whether a host names the machine the request was made on. */
    protected function isOwnMachine(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return true;
        }

        if ($host === '::1' || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.'))) {
            return true;
        }

        return in_array($host, $this->namedHosts(), true);
    }

    /**
     * The hosts the application names as its own: APP_URL's, and any listed.
     *
     * @return array<int, string>
     */
    protected function namedHosts(): array
    {
        $hosts = (array) config('dissect.local_hosts', []);
        $url = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($url)) {
            $hosts[] = $url;
        }

        return array_values(array_filter(array_map(
            fn ($host) => is_string($host) ? strtolower(trim($host, '[] ')) : null,
            $hosts,
        )));
    }
}
