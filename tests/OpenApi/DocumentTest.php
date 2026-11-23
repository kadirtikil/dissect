<?php

namespace KdrDev\Dissect\Tests\OpenApi;

use Illuminate\Http\Resources\Json\JsonResource;
use KdrDev\Dissect\OpenApi\Document;
use KdrDev\Dissect\Support\Surfaces;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DocumentTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    protected function route(string $id, array $overrides = []): array
    {
        [$methods, $uri] = explode(':', $id, 2);

        return array_replace([
            'id' => $id,
            'methods' => explode('|', $methods),
            'uri' => $uri,
            'name' => null,
            'domain' => null,
            'middleware' => ['api'],
            'stack' => 'api',
            'group' => 'app',
            'action' => ['type' => 'controller', 'class' => 'App\\Http\\Controllers\\InvoiceController', 'method' => 'index', 'label' => 'InvoiceController@index'],
            'context' => null,
            'parameters' => [],
            'request' => ['source' => 'none', 'class' => null, 'confidence' => 'certain', 'fields' => []],
            'response' => ['source' => 'unknown', 'class' => null, 'status' => null, 'confidence' => 'unknown', 'fields' => []],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function invoiceResponse(string $source = 'resource', ?int $status = null): array
    {
        return ['source' => $source, 'class' => 'App\\Http\\Resources\\InvoiceResource', 'status' => $status, 'confidence' => 'certain', 'fields' => [
            ['path' => 'id', 'kind' => 'scalar', 'conditional' => false],
            ['path' => 'customer', 'kind' => 'object', 'conditional' => true],
            ['path' => 'customer.name', 'kind' => 'scalar', 'conditional' => false],
        ]];
    }

    #[Test]
    public function it_is_an_openapi_31_document_of_the_api_routes(): void
    {
        $document = Document::of([
            $this->route('GET:api/invoices', ['name' => 'invoices.index']),
            $this->route('GET:dashboard', ['stack' => 'web']),
            $this->route('GET:dissect', ['stack' => 'api', 'action' => ['type' => 'controller', 'class' => 'KdrDev\\Dissect\\Http\\Controllers\\ViewerController', 'method' => 'show', 'label' => 'ViewerController@show']]),
        ], ['title' => 'Billing', 'version' => '2.1.0', 'servers' => ['https://api.example.com']]);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['title' => 'Billing', 'version' => '2.1.0'], $document['info']);
        $this->assertSame([['url' => 'https://api.example.com']], $document['servers']);

        // Web routes are pages, and dissect's own are not the app's.
        $this->assertSame(['/api/invoices'], array_keys($document['paths']));

        $this->assertSame(['/api/invoices', '/dashboard'], array_keys(Document::of([
            $this->route('GET:api/invoices'),
            $this->route('GET:dashboard', ['stack' => 'web']),
        ], ['stacks' => ['api', 'web']])['paths']));
    }

    #[Test]
    public function a_resource_is_a_component_wrapped_in_data(): void
    {
        $document = Document::of([
            $this->route('GET:api/invoices', ['name' => 'invoices.index', 'response' => $this->invoiceResponse('resource-collection')]),
            $this->route('POST:api/invoices', ['name' => 'invoices.store', 'response' => $this->invoiceResponse('resource', 201)]),
        ]);

        $index = $document['paths']['/api/invoices']['get']['responses']['200']['content']['application/json']['schema'];
        $store = $document['paths']['/api/invoices']['post']['responses']['201']['content']['application/json']['schema'];

        $this->assertSame(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/InvoiceResource']]], 'required' => ['data']], $index);
        $this->assertSame(['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/InvoiceResource']], 'required' => ['data']], $store);

        $component = $document['components']['schemas']['InvoiceResource'];
        $this->assertSame(['id'], $component['required']);
        $this->assertSame(['name'], $component['properties']['customer']['required']);
    }

    #[Test]
    public function wrapping_follows_the_resource_and_the_app(): void
    {
        $this->assertSame('invoice', Document::wrapOf(WrappedInvoiceResource::class));
        $this->assertSame('data', Document::wrapOf(null));

        $wrap = JsonResource::$wrap;
        JsonResource::withoutWrapping();

        try {
            $document = Document::of([$this->route('GET:api/invoices/{invoice}', ['response' => $this->invoiceResponse()])]);

            $this->assertSame(
                ['$ref' => '#/components/schemas/InvoiceResource'],
                $document['paths']['/api/invoices/{invoice}']['get']['responses']['200']['content']['application/json']['schema'],
            );
        } finally {
            JsonResource::$wrap = $wrap;
        }
    }

    #[Test]
    public function a_form_request_is_the_body_and_failing_it_is_a_422(): void
    {
        $document = Document::of([$this->route('POST:api/invoices', [
            'name' => 'invoices.store',
            'request' => ['source' => 'form-request', 'class' => 'App\\Http\\Requests\\StoreInvoice', 'confidence' => 'certain', 'fields' => [
                ['path' => 'total', 'type' => 'integer', 'required' => true, 'rules' => ['required', 'integer', 'min:1']],
                ['path' => 'note', 'type' => 'string', 'required' => false, 'rules' => ['nullable', 'string']],
            ]],
            'response' => $this->invoiceResponse('resource', 201),
        ])]);

        $operation = $document['paths']['/api/invoices']['post'];

        $this->assertSame(['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StoreInvoice']]]], $operation['requestBody']);
        $this->assertSame(['$ref' => '#/components/responses/ValidationError'], $operation['responses']['422']);
        $this->assertSame(['total'], $document['components']['schemas']['StoreInvoice']['required']);
        $this->assertSame(1, $document['components']['schemas']['StoreInvoice']['properties']['total']['minimum']);
        $this->assertSame(['message', 'errors'], $document['components']['responses']['ValidationError']['content']['application/json']['schema']['required']);
    }

    #[Test]
    public function a_get_reads_its_fields_from_the_query_string(): void
    {
        $document = Document::of([$this->route('GET:api/search', [
            'request' => ['source' => 'inline-validate', 'class' => null, 'confidence' => 'inferred', 'fields' => [
                ['path' => 'q', 'type' => 'string', 'required' => true, 'rules' => ['required', 'string', 'min:2']],
                ['path' => 'ids[]', 'type' => 'integer', 'required' => false, 'rules' => ['integer']],
                ['path' => 'filter.status', 'type' => 'string', 'required' => false, 'rules' => ['string']],
            ]],
        ])]);

        $operation = $document['paths']['/api/search']['get'];
        $parameters = array_column($operation['parameters'], null, 'name');

        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertSame(['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 2, 'description' => '`required|string|min:2`']], $parameters['q']);
        $this->assertSame('array', $parameters['ids[]']['schema']['type']);
        $this->assertSame('deepObject', $parameters['filter']['style']);
        $this->assertStringContainsString('inferred', $operation['description']);
        $this->assertArrayHasKey('422', $operation['responses']);
    }

    #[Test]
    public function path_parameters_are_declared_with_their_constraint(): void
    {
        $document = Document::of([$this->route('GET:api/invoices/{invoice:number}/lines/{line}', [
            'parameters' => [
                ['name' => 'invoice', 'optional' => false, 'field' => 'number', 'pattern' => null],
                ['name' => 'line', 'optional' => false, 'field' => null, 'pattern' => '[0-9]+'],
            ],
        ])]);

        $parameters = $document['paths']['/api/invoices/{invoice}/lines/{line}']['get']['parameters'];

        $this->assertSame(['name' => 'invoice', 'in' => 'path', 'required' => true, 'description' => 'Looked up by its number.', 'schema' => ['type' => 'string']], $parameters[0]);
        $this->assertSame('^(?:[0-9]+)$', $parameters[1]['schema']['pattern']);
    }

    #[Test]
    public function an_optional_parameter_is_a_path_with_it_and_one_without(): void
    {
        $document = Document::of([$this->route('GET:api/feed/{category?}', [
            'name' => 'feed',
            'parameters' => [['name' => 'category', 'optional' => true, 'field' => null, 'pattern' => '[a-z-]+']],
        ])]);

        $this->assertSame(['/api/feed', '/api/feed/{category}'], array_keys($document['paths']));
        $this->assertSame('feed', $document['paths']['/api/feed/{category}']['get']['operationId']);
        $this->assertSame('feed.withoutCategory', $document['paths']['/api/feed']['get']['operationId']);
        $this->assertArrayNotHasKey('parameters', $document['paths']['/api/feed']['get']);
    }

    #[Test]
    public function every_verb_is_an_operation_with_its_own_id(): void
    {
        $document = Document::of([
            $this->route('PUT|PATCH:api/invoices/{invoice}', ['name' => 'invoices.update', 'parameters' => [['name' => 'invoice', 'optional' => false, 'field' => null, 'pattern' => null]]]),
            $this->route('GET|HEAD:api/ping'),
            $this->route('POST:api/ping'),
        ]);

        $update = $document['paths']['/api/invoices/{invoice}'];

        $this->assertSame(['put', 'patch'], array_keys($update));
        $this->assertSame('invoices.update', $update['put']['operationId']);
        $this->assertSame('invoices.update.patch', $update['patch']['operationId']);
        $this->assertSame('invoices.update', $update['patch']['summary']);

        // HEAD is Laravel's own; an unnamed route is named by verb and path.
        $this->assertSame(['get', 'post'], array_keys($document['paths']['/api/ping']));
        $this->assertSame('get.api.ping', $document['paths']['/api/ping']['get']['operationId']);
        $this->assertSame('GET /api/ping', $document['paths']['/api/ping']['get']['summary']);
    }

    #[Test]
    public function each_kind_of_response_is_said_as_openapi_says_it(): void
    {
        $document = Document::of([
            $this->route('GET:api/a', ['response' => ['source' => 'json', 'class' => null, 'status' => null, 'confidence' => 'inferred', 'fields' => [['path' => 'ok', 'kind' => 'scalar', 'conditional' => false]]]]),
            $this->route('DELETE:v1/posts/{post}', ['parameters' => [['name' => 'post', 'optional' => false, 'field' => null, 'pattern' => null]], 'response' => ['source' => 'json-api-none', 'class' => null, 'status' => 204, 'confidence' => 'certain', 'fields' => []]]),
            $this->route('GET:api/c'),
            $this->route('GET:api/d', ['response' => ['source' => 'redirect', 'class' => null, 'status' => null, 'confidence' => 'certain', 'fields' => []]]),
        ]);

        $this->assertSame(['ok'], $document['paths']['/api/a']['get']['responses']['200']['content']['application/json']['schema']['required']);
        $this->assertStringContainsString('inferred', $document['paths']['/api/a']['get']['description']);
        $this->assertSame(['204' => ['description' => 'No content.']], $document['paths']['/v1/posts/{post}']['delete']['responses']);
        $this->assertSame(['default'], array_keys($document['paths']['/api/c']['get']['responses']));
        $this->assertSame("Handled by `InvoiceController@index`.\n\ndissect could not work out what this returns.", $document['paths']['/api/c']['get']['description']);
        // PHP keys "302" as an integer; JSON writes it as the string OpenAPI wants.
        $this->assertSame([302], array_keys($document['paths']['/api/d']['get']['responses']));
    }

    #[Test]
    public function json_api_and_files_get_their_own_media_types(): void
    {
        $document = Document::of([
            $this->route('POST:v1/posts', [
                'request' => ['source' => 'json-api', 'class' => null, 'confidence' => 'certain', 'fields' => [['path' => 'data', 'type' => 'object', 'required' => true, 'rules' => []]]],
                'response' => ['source' => 'json-api', 'class' => 'App\\JsonApi\\V1\\Posts\\PostSchema', 'status' => 201, 'confidence' => 'certain', 'fields' => [['path' => 'data', 'kind' => 'object', 'conditional' => false]]],
            ]),
            $this->route('POST:api/avatar', [
                'request' => ['source' => 'inline-validate', 'class' => null, 'confidence' => 'certain', 'fields' => [['path' => 'avatar', 'type' => 'image', 'required' => true, 'rules' => ['required', 'image', 'max:2048']]]],
            ]),
        ]);

        $post = $document['paths']['/v1/posts']['post'];
        $this->assertSame(['application/vnd.api+json'], array_keys($post['requestBody']['content']));
        $this->assertSame(['application/vnd.api+json'], array_keys($post['responses']['201']['content']));
        $this->assertArrayNotHasKey('422', $post['responses']);

        $avatar = $document['paths']['/api/avatar']['post']['requestBody']['content'];
        $this->assertSame(['type' => 'string', 'format' => 'binary', 'description' => '`required|image|max:2048`'], $avatar['multipart/form-data']['schema']['properties']['avatar']);
    }

    #[Test]
    public function auth_middleware_says_who_may_call_it(): void
    {
        $document = Document::of([
            $this->route('GET:api/me', ['middleware' => ['api', 'auth:sanctum']]),
            $this->route('GET:api/either', ['middleware' => ['api', 'auth:sanctum,api']]),
            $this->route('GET:api/session', ['middleware' => ['api', 'auth']]),
            $this->route('GET:api/basic', ['middleware' => ['api', 'auth.basic']]),
            $this->route('GET:api/class', ['middleware' => ['api', 'Illuminate\\Auth\\Middleware\\Authenticate:sanctum']]),
            $this->route('GET:api/open'),
        ], ['guards' => ['web' => 'session', 'sanctum' => 'sanctum', 'api' => 'token'], 'default_guard' => 'web', 'session_cookie' => 'app_session']);

        $paths = $document['paths'];

        $this->assertSame([['sanctum' => []]], $paths['/api/me']['get']['security']);
        $this->assertSame(['$ref' => '#/components/responses/Unauthenticated'], $paths['/api/me']['get']['responses']['401']);
        $this->assertSame([['sanctum' => []], ['api' => []]], $paths['/api/either']['get']['security']);
        $this->assertSame([['web' => []]], $paths['/api/session']['get']['security']);
        $this->assertSame([['basic' => []]], $paths['/api/basic']['get']['security']);
        $this->assertSame([['sanctum' => []]], $paths['/api/class']['get']['security']);
        $this->assertArrayNotHasKey('security', $paths['/api/open']['get']);
        $this->assertArrayNotHasKey('401', $paths['/api/open']['get']['responses']);

        $schemes = $document['components']['securitySchemes'];
        $this->assertSame(['api', 'basic', 'sanctum', 'web'], array_keys($schemes));
        $this->assertSame(['type' => 'apiKey', 'in' => 'cookie', 'name' => 'app_session'], array_intersect_key($schemes['web'], ['type' => 0, 'in' => 0, 'name' => 0]));
        $this->assertSame('bearer', $schemes['sanctum']['scheme']);
        $this->assertSame('basic', $schemes['basic']['scheme']);
        $this->assertArrayHasKey('Unauthenticated', $document['components']['responses']);

        // Scopes are a list, empty or not; a field called "security" is still a schema.
        $this->assertStringContainsString('"sanctum": []', Document::encode($document));
        $field = Document::of([$this->route('GET:api/x', ['response' => ['source' => 'json', 'class' => null, 'status' => null, 'confidence' => 'certain', 'fields' => [['path' => 'security', 'kind' => 'scalar', 'conditional' => false]]]])]);
        $this->assertStringContainsString('"security": {}', Document::encode($field));
    }

    #[Test]
    public function two_resources_with_one_short_name_get_two_components(): void
    {
        $document = Document::of([
            $this->route('GET:api/a', ['response' => ['source' => 'resource', 'class' => 'App\\Billing\\InvoiceResource', 'status' => null, 'confidence' => 'certain', 'fields' => []]]),
            $this->route('GET:api/b', ['response' => ['source' => 'resource', 'class' => 'App\\Sales\\InvoiceResource', 'status' => null, 'confidence' => 'certain', 'fields' => []]]),
        ]);

        $this->assertSame(['Billing.InvoiceResource', 'Sales.InvoiceResource'], array_keys($document['components']['schemas']));
    }

    #[Test]
    public function a_schema_that_says_nothing_is_written_as_an_object(): void
    {
        $document = Document::of([$this->route('GET:api/a', ['response' => ['source' => 'json', 'class' => null, 'status' => null, 'confidence' => 'certain', 'fields' => [
            ['path' => 'id', 'kind' => 'scalar', 'conditional' => false],
        ]]])]);

        $json = Document::encode($document);

        $this->assertStringContainsString('"id": {}', $json);
        $this->assertStringNotContainsString('[]', $json);
        $this->assertStringEndsWith("}\n", $json);
        $this->assertSame('{}', json_encode(Document::of([])['paths']));
    }

    #[Test]
    public function the_workbench_api_is_a_well_formed_document(): void
    {
        $routes = $this->app->make(Surfaces::class)->export('routes')['routes'];
        $document = Document::of($routes, ['title' => 'Workbench']);

        $this->assertWellFormed($document);
        $this->assertArrayHasKey('/api/posts', $document['paths']);
        $this->assertArrayHasKey('/v1/posts/{post}/relationships/comments', $document['paths']);
        $this->assertArrayHasKey('PostResource', $document['components']['schemas']);

        // The same app gives the same bytes.
        $this->assertSame(json_encode($document), json_encode(Document::of($routes, ['title' => 'Workbench'])));
    }

    #[Test]
    public function a_route_bound_to_a_domain_answers_on_that_domain(): void
    {
        $document = Document::of([
            $this->route('GET:dash', ['domain' => '{account}.example.com']),
        ]);

        $this->assertSame(
            [['url' => '//{account}.example.com', 'variables' => ['account' => ['default' => 'account']]]],
            json_decode(Document::encode($document), true)['paths']['/dash']['get']['servers'],
        );
    }

    #[Test]
    public function the_same_path_on_two_domains_is_named_not_written_over(): void
    {
        $document = json_decode(Document::encode(Document::of([
            $this->route('GET:dash', ['domain' => 'a.example.com', 'action' => ['type' => 'controller', 'class' => 'A', 'method' => 'show', 'label' => 'A@show']]),
            $this->route('GET:dash', ['domain' => 'b.example.com', 'action' => ['type' => 'controller', 'class' => 'B', 'method' => 'show', 'label' => 'B@show']]),
        ])), true);

        $operation = $document['paths']['/dash']['get'];

        // The first keeps the operation; the second is not lost without a word.
        $this->assertSame('//a.example.com', $operation['servers'][0]['url']);
        $this->assertStringContainsString('Handled by `A@show`.', $operation['description']);
        $this->assertStringContainsString('also answered on `b.example.com` by `B@show`', $operation['description']);
    }

    #[Test]
    public function numbered_fields_are_still_an_object(): void
    {
        $document = Document::of([
            $this->route('POST:api/places', ['request' => ['source' => 'form-request', 'class' => null, 'confidence' => 'certain', 'fields' => [
                ['path' => 'coords', 'type' => 'array', 'required' => true, 'rules' => ['required', 'array']],
                ['path' => 'coords.0', 'type' => 'numeric', 'required' => true, 'rules' => ['required', 'numeric']],
                ['path' => 'coords.1', 'type' => 'numeric', 'required' => true, 'rules' => ['required', 'numeric']],
            ]]]),
        ]);

        $json = Document::encode($document);
        $schema = json_decode($json)->paths->{'/api/places'}->post->requestBody->content->{'application/json'}->schema;

        $this->assertIsObject($schema->properties->coords->properties);
        $this->assertTrue(property_exists($schema->properties->coords->properties, '0') && property_exists($schema->properties->coords->properties, '1'));
        $this->assertDoesNotMatchRegularExpression('/"properties":\s*\[/', $json);
    }

    /**
     * What the OpenAPI specification requires of a document, checked without
     * a validator: every reference resolves, every operation has responses
     * and a unique id, and each path's parameters are exactly its template's.
     *
     * @param  array<string, mixed>  $document
     */
    protected function assertWellFormed(array $document): void
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        preg_match_all('/"\$ref":"#\\\\\/components\\\\\/(schemas|responses)\\\\\/([^"]+)"/', $json, $refs, PREG_SET_ORDER);
        $this->assertNotEmpty($refs);

        foreach ($refs as [, $kind, $name]) {
            $this->assertArrayHasKey($name, $decoded['components'][$kind], "#/components/{$kind}/{$name} does not resolve.");
        }

        $ids = [];

        foreach ($decoded['paths'] as $path => $item) {
            $this->assertStringStartsWith('/', $path);
            preg_match_all('/\{([^}]+)\}/', $path, $template);

            foreach ($item as $method => $operation) {
                $this->assertContains($method, ['get', 'put', 'post', 'delete', 'options', 'patch', 'trace']);
                $this->assertNotEmpty($operation['responses'], "{$method} {$path} has no responses.");
                $this->assertArrayNotHasKey($operation['operationId'], $ids, "{$operation['operationId']} is used twice.");
                $ids[$operation['operationId']] = true;

                $declared = array_column(array_filter($operation['parameters'] ?? [], fn ($p) => $p['in'] === 'path'), 'name');
                $this->assertEqualsCanonicalizing($template[1], $declared, "{$method} {$path} declares the wrong path parameters.");

                foreach ($operation['responses'] as $status => $response) {
                    $this->assertMatchesRegularExpression('/^([1-5]\d\d|default)$/', (string) $status);
                    $this->assertTrue(isset($response['description']) || isset($response['$ref']), "{$method} {$path} {$status} has no description.");
                }
            }
        }
    }
}

class WrappedInvoiceResource extends JsonResource
{
    public static $wrap = 'invoice';
}
