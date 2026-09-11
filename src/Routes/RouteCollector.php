<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Reads the router's own table and reports the transport half of each route:
 * how you address it, and what stands between the request and the action.
 *
 * Everything about *what runs* is {@see ActionResolver}'s job — this class
 * deliberately knows nothing about controllers, so the two can be tested apart.
 */
class RouteCollector
{
    public function __construct(protected Router $router) {}

    /** @return array<int, Route> */
    public function all(): array
    {
        return array_values($this->router->getRoutes()->getRoutes());
    }

    /**
     * @return array{methods: array<int, string>, uri: string, name: string|null, domain: string|null, middleware: array<int, string>, stack: string}
     */
    public function describe(Route $route): array
    {
        $middleware = $this->middleware($route);

        return [
            'methods' => $this->methods($route),
            // Laravel stores the root as "/", which reads oddly in a list of
            // paths — but an empty string reads worse.
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'domain' => $route->getDomain(),
            'middleware' => $middleware,
            'stack' => self::stack($middleware),
        ];
    }

    /**
     * Which middleware stack the route was registered into: `web`, `api`, or
     * neither.
     *
     * This is the closest thing the router keeps to "which file was it written
     * in". Laravel loads `web.php` and `api.php` into one flat table and
     * retains nothing about where each entry came from — but it does apply a
     * group per file, and that group is the difference that actually matters:
     * `web` is session- and cookie-backed, which is what a first-party frontend
     * talks to; `api` is stateless, which is what everybody else talks to.
     *
     * Read from the group rather than from the URI because a prefix is a
     * convention and the group is a behaviour. An application that serves its
     * API from `/v2` or its frontend from `/app` is still correctly split here.
     *
     * `api` is checked first: a route somehow in both groups is making the
     * stateless claim, and that is the one consumers have to honour.
     *
     * @param  array<int, string>  $middleware
     */
    public static function stack(array $middleware): string
    {
        if (in_array('api', $middleware, true)) {
            return 'api';
        }

        if (in_array('web', $middleware, true)) {
            return 'web';
        }

        // Neither group. Reported rather than guessed at: a console route, or
        // one registered outside both files, is a real thing to see in the list
        // and inventing a half for it would be a lie.
        return 'other';
    }

    /**
     * The URI's placeholders, in the order they appear.
     *
     * What a placeholder binds to is deliberately not reported: the routes
     * surface describes the endpoint's own contract — how it is addressed and
     * what goes over the wire — and resolving `{post}` to a graph node is a
     * different context's question.
     *
     * @return array<int, array{name: string, optional: bool, field: string|null, pattern: string|null}>
     */
    public function parameters(Route $route): array
    {
        // `{post}`, `{post:slug}` and `{category?}` in one pass, in URI order.
        preg_match_all('/\{(\w+)(?::(\w+))?(\?)?\}/', $route->uri(), $matches, PREG_SET_ORDER);

        $wheres = $route->wheres;
        $parameters = [];

        foreach ($matches as $match) {
            $name = $match[1];

            $parameters[] = [
                'name' => $name,
                'optional' => ($match[3] ?? '') === '?',
                // `{post:slug}` — which column the model is looked up by.
                'field' => ($match[2] ?? '') !== '' ? $match[2] : null,
                'pattern' => $wheres[$name] ?? null,
            ];
        }

        return $parameters;
    }

    /**
     * Verbs worth showing.
     *
     * Laravel registers HEAD alongside every GET, which is true but says
     * nothing — it doubles the width of the busiest column in the list for no
     * information. Dropped only when GET is there to imply it.
     *
     * @return array<int, string>
     */
    protected function methods(Route $route): array
    {
        $methods = $route->methods();

        if (in_array('GET', $methods, true)) {
            $methods = array_values(array_diff($methods, ['HEAD']));
        }

        return $methods;
    }

    /**
     * Route middleware plus whatever its groups contribute, left in the form
     * they were written: `auth:sanctum` rather than the class it resolves to.
     *
     * The alias is what somebody reading the route file recognises, and it is
     * what they would search the list for.
     *
     * @return array<int, string>
     */
    protected function middleware(Route $route): array
    {
        $middleware = [];

        foreach ($route->gatherMiddleware() as $entry) {
            // A closure middleware has no name to show. Naming it after its
            // class would be noise, so it is reported as what it is.
            $middleware[] = is_string($entry) ? $entry : 'Closure';
        }

        return array_values(array_unique($middleware));
    }
}
