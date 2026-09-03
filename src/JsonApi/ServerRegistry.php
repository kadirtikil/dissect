<?php

namespace KdrDev\Dissect\JsonApi;

use Illuminate\Routing\Route;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Contracts\Server\Repository;
use Throwable;

/**
 * Finds the schema behind a JSON:API route.
 *
 * Every route the package registers runs the same generic controller, so the
 * usual trick — reflect the action, read its return type — answers
 * `JsonApiController` for all of them and says nothing. What identifies a
 * JSON:API route is its **name**: the package builds it as
 * `{server}.{resource}.{action}`, and both halves of the join are in there.
 *
 *     v1.posts.index          → server v1, resource posts
 *     v1.posts.author         → server v1, resource posts, relation author
 *     v1.posts.author.show    → server v1, resource posts, relation author
 *
 * The third segment is a relation on some routes and an action on others, and
 * the name alone cannot tell them apart — `v1.posts.index` and `v1.posts.author`
 * have the same shape. What separates them is the controller method the package
 * routed to, so that is what decides.
 *
 * The server name is checked against `jsonapi.servers` rather than assumed, so
 * an application route that merely happens to be called `v1.something` is not
 * mistaken for one of these.
 *
 * Unlike the rest of the exporter this asks the package to resolve the schema
 * rather than reading it out of source. A schema's `fields()` is a list of
 * objects built by method chains — reading it as syntax would mean
 * reimplementing the package's own resolution and getting a worse answer. It is
 * the same bargain {@see \KdrDev\Dissect\Routes\RequestAnalyzer} already makes
 * with `FormRequest::rules()`, and it is wrapped just as carefully: a server
 * that cannot be built costs its own routes their shape and nothing else.
 */
class ServerRegistry
{
    /** @var array<string, Schema|null> resolved per server.resource, including misses */
    protected array $cache = [];

    /**
     * @param  array<string, class-string>  $servers  from `jsonapi.servers`
     */
    public function __construct(
        protected Repository $servers,
        protected array $names,
    ) {}

    /** Whether the application has any JSON:API server at all. */
    public function configured(): bool
    {
        return $this->names !== [];
    }

    /**
     * Controller methods whose route names carry a relation in the third slot.
     */
    protected const RELATION_ACTIONS = [
        'showRelated',
        'showRelationship',
        'updateRelationship',
        'attachRelationship',
        'detachRelationship',
    ];

    /**
     * The `{server}.{resource}` a route addresses, or null if it addresses none.
     *
     * @return array{server: string, resource: string, relation: string|null}|null
     */
    public function address(Route $route): ?array
    {
        $name = $route->getName();

        if ($name === null || ! $this->configured()) {
            return null;
        }

        $parts = explode('.', $name);

        // `{server}.{resource}.{action}` is the shortest form there is.
        if (count($parts) < 3 || ! array_key_exists($parts[0], $this->names)) {
            return null;
        }

        return [
            'server' => $parts[0],
            'resource' => $parts[1],
            'relation' => in_array($route->getActionMethod(), self::RELATION_ACTIONS, true)
                ? $parts[2]
                : null,
        ];
    }

    /** The schema for a resource type, or null when there is not one. */
    public function schema(string $server, string $resource): ?Schema
    {
        $key = $server.'.'.$resource;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($server, $resource);
    }

    protected function resolve(string $server, string $resource): ?Schema
    {
        try {
            $schemas = $this->servers->server($server)->schemas();

            return $schemas->exists($resource) ? $schemas->schemaFor($resource) : null;
        } catch (Throwable) {
            // Building a server touches the container and the application's own
            // schema classes. A half-configured one must not cost the whole
            // endpoint list — the routes still describe themselves without it.
            return null;
        }
    }
}
