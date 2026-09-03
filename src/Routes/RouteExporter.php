<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Routing\Route;
use KdrDev\Dissect\JsonApi\DocumentAnalyzer;
use Throwable;

/**
 * Builds the endpoint list — the second half of what dissect draws.
 *
 * The schema answers "what does the data look like"; this answers "how do you
 * reach it". The two are deliberately not joined: an endpoint is described by
 * its own contract — how it is addressed, what goes in, what comes back — and
 * nothing here resolves a name to a node in the model graph.
 *
 * Orchestration only. Reading the router is {@see RouteCollector}, working out
 * what runs is {@see ActionResolver}.
 */
class RouteExporter
{
    public function __construct(
        protected RouteCollector $collector,
        protected ActionResolver $actions,
        protected RequestAnalyzer $requests,
        protected ResponseAnalyzer $responses,
        protected RouteFingerprint $fingerprint,
        protected DocumentAnalyzer $documents,
    ) {}

    /**
     * @return array{routes: array<int, array<string, mixed>>, generated_at: string}
     */
    public function export(): array
    {
        $routes = [];

        foreach ($this->collector->all() as $route) {
            $exported = $this->describe($route);

            if ($exported !== null) {
                $routes[] = $exported;
            }
        }

        // Grouped by path, then by verb within it — which is the order the list
        // reads in, so the client never has to re-sort.
        usort($routes, fn ($a, $b) => [$a['uri'], $a['methods'][0] ?? '']
            <=> [$b['uri'], $b['methods'][0] ?? '']);

        return [
            'routes' => $routes,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Cheap change signal — see {@see RouteFingerprint}. */
    public function fingerprint(): string
    {
        return $this->fingerprint->signal();
    }

    /**
     * One route, or null if it could not be described at all.
     *
     * A single unreadable route must not cost the whole list, for the same
     * reason a model whose table is missing still gets a node: the other forty
     * are still worth looking at.
     *
     * @return array<string, mixed>|null
     */
    protected function describe(Route $route): ?array
    {
        try {
            $reflection = $this->actions->reflect($route);
            $described = $this->collector->describe($route);
            $action = $this->actions->describe($route);
            $parameters = $this->collector->parameters($route);

            // JSON:API first. Every route the package registers runs the same
            // generic controller, so reflecting the action answers
            // `JsonApiController` for all of them — the schema behind the route
            // is the only thing that describes the payload, and where there is
            // one it is a better answer than anything the reflection can give.
            $document = $this->documents->response($route);

            $request = $this->documents->request($route) ?? $this->requests->analyse($reflection);
            $response = $document ?? $this->responses->analyse($reflection);

            return [
                'id' => self::id($described['methods'], $described['uri']),
                ...$described,
                'group' => $this->actions->group($route),
                'action' => $action,
                'parameters' => $parameters,
                'request' => $request,
                'response' => $response,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Addresses the route in the client, and survives a rename of anything but
     * the endpoint itself.
     *
     * The route's own name would be the obvious key, but plenty of routes have
     * none, and two different verbs on one path are two different endpoints —
     * so the pair that actually identifies it is what is used.
     *
     * Public and static because the jobs surface links dispatch sites back to
     * endpoints by this id. Two places that both spell a route's key and only
     * happen to agree is a cross-link that breaks the first time one of them is
     * changed.
     *
     * @param  array<int, string>  $methods
     */
    public static function id(array $methods, string $uri): string
    {
        return implode('|', $methods).':'.$uri;
    }
}
