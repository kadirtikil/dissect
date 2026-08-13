<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

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
     * @return array{methods: array<int, string>, uri: string, name: string|null, domain: string|null, middleware: array<int, string>}
     */
    public function describe(Route $route): array
    {
        return [
            'methods' => $this->methods($route),
            // Laravel stores the root as "/", which reads oddly in a list of
            // paths — but an empty string reads worse.
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'domain' => $route->getDomain(),
            'middleware' => $this->middleware($route),
        ];
    }

    /**
     * The URI's placeholders, in the order they appear, each with the model it
     * resolves to when route model binding is in play.
     *
     * The binding is read from the action's own type hints rather than from the
     * container's bindings, because implicit binding — a `Post $post` parameter
     * matching a `{post}` placeholder — is how almost every application does
     * it, and it is the form that carries the model.
     *
     * @return array<int, array{name: string, optional: bool, field: string|null, pattern: string|null, model: string|null}>
     */
    public function parameters(Route $route, ?ReflectionFunctionAbstract $action = null): array
    {
        // `{post}`, `{post:slug}` and `{category?}` in one pass, in URI order.
        preg_match_all('/\{(\w+)(?::(\w+))?(\?)?\}/', $route->uri(), $matches, PREG_SET_ORDER);

        $bound = $this->boundModels($action);
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
                'model' => $bound[$name] ?? null,
            ];
        }

        return $parameters;
    }

    /**
     * Action parameters type-hinted as an Eloquent model, keyed by name.
     *
     * @return array<string, string>
     */
    protected function boundModels(?ReflectionFunctionAbstract $action): array
    {
        if ($action === null) {
            return [];
        }

        $bound = [];

        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            // class_exists() first: is_subclass_of() would autoload a class the
            // application may not be able to resolve.
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                // Class basename, matching the node ids in schema.json — that
                // match is the whole point of carrying it.
                $bound[$parameter->getName()] = class_basename($class);
            }
        }

        return $bound;
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
