<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Support\ClassNames;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('dissect_search')]
#[Description('Every place a name appears across the app as dissect reads it: models and their tables and columns, routes, controllers, jobs, provider bindings (including classes deep in a provider tree) and domains. One line per hit, naming the tool to call for the full entry. Use it to answer "where is X used" or "which provider wires X".')]
#[IsReadOnly]
#[IsIdempotent]
class Search extends DissectTool
{
    /** Hits listed per surface before the rest are counted. */
    public const PER_SURFACE = 20;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('A class, table, column, route, or any part of one. Case does not matter.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads): Response
    {
        $asked = trim((string) $request->get('query', ''));
        $query = mb_strtolower($asked);

        if ($query === '') {
            return Response::error('Give a query: a class, table, column or route, or part of one.');
        }

        return $this->answer(function () use ($asked, $query, $payloads) {
            $all = $payloads->all();
            $sections = array_filter([
                'Models (dissect_model)' => $this->models($all['schema'] ?? [], $query),
                'Routes (dissect_route)' => $this->routes($all['routes']['routes'] ?? [], $query),
                'Jobs (dissect_job)' => $this->jobs($all['jobs']['jobs'] ?? [], $query),
                'Service providers (dissect_provider)' => $this->providers($all['providers']['providers'] ?? [], $query),
                'Domains (dissect_domain)' => $this->domains($all['domains']['domains'] ?? [], $query),
            ]);

            if ($sections === []) {
                return "Nothing dissect reads mentions \"{$asked}\".\n";
            }

            $lines = [];

            foreach ($sections as $heading => $hits) {
                $lines[] = "## {$heading}";
                $lines = [...$lines, ...array_slice($hits, 0, self::PER_SURFACE)];

                if (count($hits) > self::PER_SURFACE) {
                    $lines[] = '+'.(count($hits) - self::PER_SURFACE).' more';
                }

                $lines[] = '';
            }

            return rtrim(implode("\n", $lines))."\n";
        });
    }

    protected function has(?string $haystack, string $query): bool
    {
        return $haystack !== null && str_contains(mb_strtolower($haystack), $query);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected function models(array $schema, string $query): array
    {
        $hits = [];
        $names = ClassNames::short(array_column($schema['nodes'] ?? [], 'id'));

        foreach ($schema['nodes'] ?? [] as $node) {
            $name = $names[$node['id']];

            if ($this->has($node['id'], $query) || $this->has($node['table'], $query)) {
                $hits[] = "- {$name} `{$node['table']}`";
            }

            foreach ($node['columns'] ?? [] as $column) {
                $column = is_array($column) ? $column['name'] : $column;

                if ($this->has($column, $query)) {
                    $hits[] = "- {$name}.{$column} (column)";
                }
            }
        }

        foreach ($schema['edges'] ?? [] as $edge) {
            if ($this->has($edge['name'], $query) || ($edge['type'] !== 'MorphTo' && $this->has($edge['target'], $query) && ! $this->has($edge['source'], $query))) {
                $hits[] = '- '.($names[$edge['source']] ?? ClassNames::base($edge['source'])).'.'.$edge['name'].' → '.ClassNames::base($edge['target'])." ({$edge['type']})";
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, string>
     */
    protected function routes(array $routes, string $query): array
    {
        $hits = [];

        foreach ($routes as $route) {
            $fields = array_column($route['request']['fields'] ?? [], 'path');
            $matched = array_filter($fields, fn (string $field) => $this->has($field, $query));

            if ($this->has($route['uri'], $query) || $this->has($route['name'], $query) || $this->has($route['action']['class'] ?? null, $query)
                || $this->has($route['request']['class'] ?? null, $query) || $this->has($route['response']['class'] ?? null, $query) || $matched !== []) {
                $hits[] = '- `'.implode('|', $route['methods']).' '.$route['uri'].'` → '.$route['action']['label']
                    .($matched !== [] ? ' (takes '.implode(', ', $matched).')' : '');
            }
        }

        return $hits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     * @return array<int, string>
     */
    protected function jobs(array $jobs, string $query): array
    {
        $hits = [];

        foreach ($jobs as $job) {
            $carries = array_filter($job['models'] ?? [], fn (string $model) => $this->has($model, $query));

            if ($this->has($job['class'], $query) || $this->has($job['queue'] ?? null, $query) || $carries !== []) {
                $hits[] = '- '.$job['name'].' ('.$job['kind'].')'.($carries !== [] ? ' carries '.implode(', ', array_map(ClassNames::base(...), $carries)) : '');
            }
        }

        return $hits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     * @return array<int, string>
     */
    protected function providers(array $providers, string $query): array
    {
        $hits = [];

        foreach ($providers as $provider) {
            $nodes = array_filter(
                $provider['nodes'] ?? [],
                fn (array $node) => $node['id'] !== $provider['id'] && ($this->has($node['id'], $query) || $this->has($node['label'] ?? null, $query)),
            );

            if ($this->has($provider['class'], $query) || $nodes !== []) {
                $hits[] = '- '.$provider['name'].($nodes !== [] ? ': '.implode(', ', array_map(fn (array $node) => $node['label'].' ('.$node['kind'].')', $nodes)) : '');
            }
        }

        return $hits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $domains
     * @return array<int, string>
     */
    protected function domains(array $domains, string $query): array
    {
        $hits = [];

        foreach ($domains as $domain) {
            $classes = array_filter($domain['classes'] ?? [], fn (string $class) => $this->has($class, $query));

            if ($this->has($domain['name'], $query) || $classes !== []) {
                $hits[] = '- '.$domain['name'].($classes !== [] ? ' holds '.implode(', ', array_map(ClassNames::base(...), $classes)) : '');
            }
        }

        return $hits;
    }
}
