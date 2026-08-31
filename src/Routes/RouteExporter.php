<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Routing\Route;
use Throwable;

/**
 * Builds the endpoint list — the second half of what dissect draws.
 *
 * The schema answers "what does the data look like"; this answers "how do you
 * reach it". The field that joins the two is `models`: every model an endpoint
 * touches, expressed as the same class-basename ids `schema.json` uses as node
 * keys, so the graph and the endpoint list address the same things.
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
            $parameters = $this->collector->parameters($route, $reflection);
            $request = $this->requests->analyse($reflection);
            $response = $this->responses->analyse($reflection);

            return [
                'id' => self::id($described['methods'], $described['uri']),
                ...$described,
                'group' => $this->actions->group($route),
                'action' => $action,
                'parameters' => $parameters,
                'request' => $request,
                'response' => $response,
                'models' => $this->models($parameters, $request, $response),
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

    /**
     * Models this endpoint is known to touch, deduplicated and sorted.
     *
     * Every source of a link contributes to one list: a bound route parameter,
     * a rule pointing at a table, and the model behind a resource. One list,
     * because "what does this endpoint touch" is one question however the
     * answer was arrived at.
     *
     * @param  array<int, array{model: string|null}>  $parameters
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     * @return array<int, string>
     */
    protected function models(array $parameters, ?array $request, ?array $response): array
    {
        $models = array_column($parameters, 'model');

        foreach ([...$request['fields'] ?? [], ...$response['fields'] ?? []] as $field) {
            $models[] = $field['model'] ?? null;
        }

        $models = array_values(array_unique(array_filter($models)));
        sort($models);

        return $models;
    }
}
