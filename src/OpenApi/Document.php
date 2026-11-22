<?php

namespace KdrDev\Dissect\OpenApi;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use KdrDev\Dissect\Support\ClassNames;

/**
 * The routes payload, as an OpenAPI 3.1 document.
 *
 * Everything in it is read from the code: the routes, the rules of their form
 * requests or inline validation, and the fields of the API resources or
 * arrays they return. Nothing is annotated by hand, so it cannot drift from
 * the code, and where the code does not say something, neither does this:
 * a response field of unknown type has no type, a response dissect could not
 * work out is a `default` saying so.
 *
 * Built from the payload rather than from the router, like every other way
 * of reading the app, so the page, the terminal and an agent agree on it.
 */
final class Document
{
    public const OPENAPI = '3.1.0';

    /** Path item keys, in the order the specification lists them. */
    protected const METHODS = ['get', 'put', 'post', 'delete', 'options', 'patch', 'trace'];

    /** Request fields of these sources are validated, so a 422 can come back. */
    protected const VALIDATED = ['form-request', 'inline-validate'];

    /** Verbs whose fields are read from the query string. */
    protected const QUERY = ['get', 'delete'];

    /** @var array<string, string> Each class with a component, keyed to its name. */
    protected array $components = [];

    /** @var array<string, array<string, mixed>> */
    protected array $schemas = [];

    /** @var array<string, true> */
    protected array $operationIds = [];

    protected bool $validates = false;

    /**
     * @param  array<int, array<string, mixed>>  $routes  The routes payload's `routes`.
     * @param  array{title?: string, version?: string, description?: string|null, servers?: array<int, string|array<string, mixed>>, stacks?: array<int, string>}  $options
     * @return array<string, mixed>
     */
    public static function of(array $routes, array $options = []): array
    {
        return (new self)->build($routes, $options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function build(array $routes, array $options): array
    {
        $stacks = $options['stacks'] ?? ['api'];
        $routes = array_values(array_filter($routes, fn (array $route) => in_array($route['stack'] ?? null, $stacks, true) && ! self::ours($route)));

        $this->name($routes);

        $paths = [];

        foreach ($routes as $route) {
            foreach (self::variants($route) as $path => $dropped) {
                foreach ($this->methods($route) as $index => $method) {
                    $paths[$path][$method] = $this->operation($route, $method, $index, $path, $dropped);
                }
            }
        }

        ksort($paths);

        foreach ($paths as $path => $item) {
            $paths[$path] = array_merge(array_flip(array_intersect(self::METHODS, array_keys($item))), $item);
        }

        $document = [
            'openapi' => self::OPENAPI,
            'info' => array_filter([
                'title' => (string) ($options['title'] ?? 'API'),
                'version' => (string) ($options['version'] ?? '1.0.0'),
                'description' => $options['description'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];

        $servers = array_map(fn ($server) => is_string($server) ? ['url' => $server] : $server, $options['servers'] ?? []);

        if ($servers !== []) {
            $document['servers'] = array_values($servers);
        }

        $document['paths'] = $paths;

        $components = [];

        if ($this->schemas !== []) {
            ksort($this->schemas);
            $components['schemas'] = $this->schemas;
        }

        if ($this->validates) {
            $components['responses'] = ['ValidationError' => self::validationError()];
        }

        if ($components !== []) {
            $document['components'] = $components;
        }

        return self::objects($document);
    }

    /**
     * The document as JSON, the way it is written to a file: the same app
     * always gives the same bytes.
     *
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Every empty map as an object, so it is written `{}`.
     *
     * PHP has one empty array for both, and json_encode writes it `[]`. An
     * empty schema (a field whose type nothing says) must be `{}`, and a
     * document has no empty list.
     */
    protected static function objects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return (object) [];
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::objects($child);
        }

        return $value;
    }

    /**
     * Names every resource and form request that gets a component, with only
     * as much of the namespace as it takes to tell two apart.
     *
     * @param  array<int, array<string, mixed>>  $routes
     */
    protected function name(array $routes): void
    {
        $classes = [];

        foreach ($routes as $route) {
            if (in_array($route['response']['source'] ?? null, ['resource', 'resource-collection'], true) && is_string($route['response']['class'] ?? null)) {
                $classes[] = $route['response']['class'];
            }

            if (($route['request']['source'] ?? null) === 'form-request' && is_string($route['request']['class'] ?? null)) {
                $classes[] = $route['request']['class'];
            }
        }

        foreach (ClassNames::short($classes) as $class => $short) {
            $this->components[$class] = str_replace('\\', '.', $short);
        }
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<int, string>
     */
    protected function methods(array $route): array
    {
        $methods = [];

        foreach ($route['methods'] ?? [] as $method) {
            $method = strtolower((string) $method);

            // Laravel answers HEAD on every GET route by itself.
            if ($method !== 'head' && in_array($method, self::METHODS, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * The paths a route answers on. OpenAPI has no optional path parameter,
     * so `feed/{category?}` is two paths: with it, and without.
     *
     * @param  array<string, mixed>  $route
     * @return array<string, array<int, string>> Each path, keyed to the parameters it leaves out.
     */
    protected static function variants(array $route): array
    {
        $uri = '/'.ltrim((string) ($route['uri'] ?? ''), '/');
        $optional = array_values(array_filter($route['parameters'] ?? [], fn (array $parameter) => $parameter['optional'] ?? false));

        $variants = [self::path($uri) => []];
        $dropped = [];

        foreach (array_reverse($optional) as $parameter) {
            $name = preg_quote((string) $parameter['name'], '#');
            $shorter = preg_replace('#/?\{'.$name.'(:[^}?]+)?\?\}$#', '', $uri);

            if ($shorter === null || $shorter === $uri) {
                break;
            }

            $uri = $shorter === '' ? '/' : $shorter;
            $dropped[] = (string) $parameter['name'];
            $variants[self::path($uri)] = $dropped;
        }

        return $variants;
    }

    /** `{post:slug}` and `{category?}` as OpenAPI writes a parameter: `{post}`. */
    protected static function path(string $uri): string
    {
        return (string) preg_replace('#\{([^}:?]+)(:[^}?]+)?\??\}#', '{$1}', $uri);
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<int, string>  $dropped
     * @return array<string, mixed>
     */
    protected function operation(array $route, string $method, int $index, string $path, array $dropped): array
    {
        $operation = [
            'tags' => [self::tag($route)],
            'summary' => is_string($route['name'] ?? null) && $route['name'] !== '' ? $route['name'] : strtoupper($method).' '.$path,
        ];

        $description = self::description($route);

        if ($description !== null) {
            $operation['description'] = $description;
        }

        $operation['operationId'] = $this->operationId($route, $method, $index, $dropped);

        $request = $route['request'] ?? null;
        $fields = $request['fields'] ?? [];
        $parameters = self::pathParameters($route, $path);

        if ($fields !== [] && in_array($method, self::QUERY, true)) {
            $parameters = [...$parameters, ...self::queryParameters($fields)];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($fields !== [] && ! in_array($method, self::QUERY, true)) {
            $operation['requestBody'] = $this->requestBody($request);
        }

        $operation['responses'] = $this->responses($route, $fields !== []);

        return $operation;
    }

    /**
     * The domain the route belongs to, or else the first segment of its URI
     * that names something: `api/posts/{post}` and `v1/posts/{post}/author`
     * are both under "posts".
     *
     * @param  array<string, mixed>  $route
     */
    protected static function tag(array $route): string
    {
        if (is_string($route['context'] ?? null) && $route['context'] !== '') {
            return $route['context'];
        }

        foreach (explode('/', (string) ($route['uri'] ?? '')) as $segment) {
            if ($segment === '' || $segment === 'api' || str_starts_with($segment, '{') || preg_match('/^v\d+$/', $segment) === 1) {
                continue;
            }

            return $segment;
        }

        return 'default';
    }

    /**
     * What handles it, and what the reader should know about how sure the
     * shapes are.
     *
     * @param  array<string, mixed>  $route
     */
    protected static function description(array $route): ?string
    {
        $lines = [];

        if (is_string($route['action']['label'] ?? null) && $route['action']['label'] !== '') {
            $lines[] = 'Handled by `'.$route['action']['label'].'`.';
        }

        $request = $route['request'] ?? null;
        $response = $route['response'] ?? null;

        if (($request['confidence'] ?? 'certain') !== 'certain' && ($request['fields'] ?? []) !== []) {
            $lines[] = 'The request fields were inferred from the code, not read from it, and may be incomplete.';
        }

        if (in_array($response['source'] ?? 'unknown', ['unknown'], true) || $response === null) {
            $lines[] = 'dissect could not work out what this returns.';
        } elseif (($response['confidence'] ?? 'certain') !== 'certain') {
            $lines[] = 'The response was inferred from the code, not read from it, and may be incomplete.';
        }

        return $lines === [] ? null : implode("\n\n", $lines);
    }

    /**
     * The route name, or the verb and path when it has none. Unique in the
     * document, as the specification requires.
     *
     * @param  array<string, mixed>  $route
     * @param  array<int, string>  $dropped
     */
    protected function operationId(array $route, string $method, int $index, array $dropped): string
    {
        $id = is_string($route['name'] ?? null) && $route['name'] !== ''
            ? $route['name']
            : $method.'.'.trim((string) preg_replace('/[^A-Za-z0-9]+/', '.', (string) ($route['uri'] ?? '')), '.');

        // `PUT|PATCH` is one route and two operations; the first keeps the name.
        if ($index > 0) {
            $id .= '.'.$method;
        }

        if ($dropped !== []) {
            $id .= '.without'.implode('', array_map(fn (string $name) => Str::studly($name), array_reverse($dropped)));
        }

        $unique = $id;

        for ($n = 2; isset($this->operationIds[$unique]); $n++) {
            $unique = $id.'.'.$n;
        }

        $this->operationIds[$unique] = true;

        return $unique;
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<int, array<string, mixed>>
     */
    protected static function pathParameters(array $route, string $path): array
    {
        $parameters = [];

        foreach ($route['parameters'] ?? [] as $parameter) {
            $name = (string) $parameter['name'];

            if (! str_contains($path, '{'.$name.'}')) {
                continue;
            }

            $schema = ['type' => 'string'];

            // A where() constraint must match the whole segment, and a JSON
            // Schema pattern matches anywhere unless anchored.
            if (is_string($parameter['pattern'] ?? null) && $parameter['pattern'] !== '') {
                $schema['pattern'] = '^(?:'.$parameter['pattern'].')$';
            }

            $entry = ['name' => $name, 'in' => 'path', 'required' => true];

            if (is_string($parameter['field'] ?? null) && $parameter['field'] !== '') {
                $entry['description'] = "Looked up by its {$parameter['field']}.";
            }

            $entry['schema'] = $schema;
            $parameters[] = $entry;
        }

        return $parameters;
    }

    /**
     * Fields of a GET or DELETE, as query parameters: one per top-level
     * field, a list written `tags[]` the way PHP reads one, and an object as
     * `filter[status]=…`.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected static function queryParameters(array $fields): array
    {
        $schema = Schemas::request($fields);
        $required = $schema['required'] ?? [];
        $parameters = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            // A field called "0" comes back from PHP as an integer key.
            $name = (string) $name;
            $types = (array) ($property['type'] ?? []);
            $list = in_array('array', $types, true) && ! in_array('object', $types, true);
            $map = in_array('object', $types, true);

            $parameter = [
                'name' => $list ? $name.'[]' : $name,
                'in' => 'query',
                'required' => in_array($name, $required, true),
            ];

            if ($map) {
                $parameter['style'] = 'deepObject';
                $parameter['explode'] = true;
            }

            $parameter['schema'] = $property;
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function requestBody(array $request): array
    {
        $fields = $request['fields'];

        $type = match (true) {
            ($request['source'] ?? null) === 'json-api' => 'application/vnd.api+json',
            Schemas::hasFile($fields) => 'multipart/form-data',
            default => 'application/json',
        };

        $schema = Schemas::request($fields);
        $required = ($schema['required'] ?? []) !== [];

        if (($request['source'] ?? null) === 'form-request' && is_string($request['class'] ?? null)) {
            $schema = $this->component($request['class'], $schema);
        }

        return ['required' => $required, 'content' => [$type => ['schema' => $schema]]];
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    protected function responses(array $route, bool $hasFields): array
    {
        $response = $route['response'] ?? ['source' => 'unknown'];
        $source = (string) ($response['source'] ?? 'unknown');
        $status = (string) ($response['status'] ?? 200);
        $fields = $response['fields'] ?? [];
        $class = is_string($response['class'] ?? null) ? $response['class'] : null;

        $responses = match ($source) {
            'resource' => [$status => $this->resource($class, $fields, false)],
            'resource-collection' => [$status => $this->resource($class, $fields, true)],
            'json' => [$status => self::json('A JSON body.', 'application/json', $fields)],
            'json-api', 'json-api-identifier' => [$status => self::json('A JSON:API document.', 'application/vnd.api+json', $fields)],
            'json-api-none' => ['204' => ['description' => 'No content.']],
            'view' => [$status => ['description' => 'An HTML page.', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]]],
            'redirect' => [$status === '200' ? '302' : $status => ['description' => 'A redirect.']],
            default => ['default' => ['description' => 'dissect could not work out what this returns.']],
        };

        if ($hasFields && in_array($route['request']['source'] ?? null, self::VALIDATED, true)) {
            $this->validates = true;
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }

        return $responses;
    }

    /**
     * An API resource, wrapped the way Laravel wraps it: in `data`, unless
     * the resource (or the app, with `withoutWrapping()`) says otherwise. A
     * collection is always a list under `data`.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function resource(?string $class, array $fields, bool $collection): array
    {
        $item = $class === null ? Schemas::response($fields) : $this->component($class, Schemas::response($fields));
        $name = $class === null ? 'resource' : ClassNames::base($class);

        if ($collection) {
            $wrap = self::wrapOf(null) ?? 'data';
            $schema = ['type' => 'object', 'properties' => [$wrap => ['type' => 'array', 'items' => $item]], 'required' => [$wrap]];

            return ['description' => "A list of {$name}.", 'content' => ['application/json' => ['schema' => $schema]]];
        }

        $wrap = self::wrapOf($class);
        $schema = $wrap === null ? $item : ['type' => 'object', 'properties' => [$wrap => $item], 'required' => [$wrap]];

        return ['description' => "A {$name}.", 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected static function json(string $description, string $type, array $fields): array
    {
        return ['description' => $description, 'content' => [$type => ['schema' => Schemas::response($fields)]]];
    }

    /**
     * Registers a component once and refers to it.
     *
     * @param  array<string, mixed>  $schema
     * @return array{'$ref': string}
     */
    protected function component(string $class, array $schema): array
    {
        $name = $this->components[$class] ?? str_replace('\\', '.', ClassNames::base($class));
        $this->schemas[$name] ??= $schema;

        return ['$ref' => '#/components/schemas/'.$name];
    }

    /** The key Laravel wraps a resource in, or null when it does not. */
    public static function wrapOf(?string $class): ?string
    {
        if ($class !== null && class_exists($class) && is_subclass_of($class, JsonResource::class)) {
            return $class::$wrap;
        }

        return JsonResource::$wrap;
    }

    /** dissect's own page and endpoints are not the app's API. */
    protected static function ours(array $route): bool
    {
        return str_starts_with((string) ($route['action']['class'] ?? ''), 'KdrDev\\Dissect\\');
    }

    /**
     * What Laravel answers when validation fails.
     *
     * @return array<string, mixed>
     */
    protected static function validationError(): array
    {
        return [
            'description' => 'The request did not pass validation.',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'description' => 'The messages for each field that failed, by field.',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'required' => ['message', 'errors'],
            ]]],
        ];
    }
}
