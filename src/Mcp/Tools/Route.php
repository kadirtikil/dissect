<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use KdrDev\Dissect\Digest\Markdown;
use KdrDev\Dissect\Mcp\Lookup;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Support\ClassNames;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('dissect_route')]
#[Description('An HTTP route: its verb and URI, name, the controller action it runs, its middleware, the request fields it validates (* is required) and the response fields it returns, with how sure dissect is of each. Without a query, every route in one line each.')]
#[IsReadOnly]
#[IsIdempotent]
class Route extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Route name (invoices.store), URI (api/invoices/{invoice}), verb and URI (POST api/invoices), or controller (InvoiceController, InvoiceController@store). Part of one works too. Leave out to list every route.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $routes = $payloads->get('routes')['routes'] ?? [];
            $query = trim((string) $request->get('query', ''));

            if ($query === '') {
                return $this->index('Routes', array_map(
                    fn (array $route) => strtok($markdown->route($route), "\n"),
                    $routes,
                ), 'Ask for one by name, URI or controller for what it takes and returns.');
            }

            $found = Lookup::find($routes, $query, fn (array $route) => [
                $route['id'],
                $route['name'],
                $route['uri'],
                '/'.$route['uri'],
                ...array_map(fn (string $method) => $method.' '.$route['uri'], $route['methods']),
                $route['action']['label'] ?? null,
                $route['action']['class'] ?? null,
                isset($route['action']['class']) ? ClassNames::base($route['action']['class']) : null,
            ]);

            return $this->matches('route', $query, array_map(fn (array $route) => $markdown->route($route), $found));
        });
    }
}
