<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The JSON:API half of the endpoint surface, against the fixture server in
 * `workbench/app/JsonApi/V1`.
 *
 * Every route the package registers runs the same generic controller, so none
 * of this can be read the way the rest of the exporter reads an endpoint. What
 * is being tested is the other join: route name → resource type → schema.
 */
class JsonApiTest extends TestCase
{
    #[Test]
    public function it_describes_a_resource_as_a_json_api_document(): void
    {
        // Not the schema's fields at the top level — the envelope. A consumer
        // writes `data.attributes.title`, and reporting `title` would be a lie
        // about where the value is.
        $response = $this->routes()['GET:v1/posts/{post}']['response'];

        $this->assertSame('json-api', $response['source']);
        $this->assertSame('certain', $response['confidence']);
        $this->assertSame(
            'Workbench\App\JsonApi\V1\Posts\PostSchema',
            $response['class'],
        );

        $paths = $this->paths($response);

        $this->assertContains('data.type', $paths);
        $this->assertContains('data.id', $paths);
        $this->assertContains('data.attributes.title', $paths);
        // The field name, not the column it reads from: `featured` is mapped to
        // `is_featured`, and the wire carries the former.
        $this->assertContains('data.attributes.featured', $paths);
        $this->assertNotContains('data.attributes.is_featured', $paths);
    }

    #[Test]
    public function it_marks_a_collection_on_the_path(): void
    {
        // The same `[]` grammar the rest of the surface uses, so one component
        // renders a JSON:API document and a plain resource alike.
        $paths = $this->paths($this->routes()['GET:v1/posts']['response']);

        $this->assertContains('data[]', $paths);
        $this->assertContains('data[].attributes.title', $paths);
        $this->assertNotContains('data.attributes.title', $paths);
    }

    #[Test]
    public function it_separates_a_relationship_from_the_data_inside_it(): void
    {
        $response = $this->routes()['GET:v1/posts/{post}']['response'];
        $fields = collect($response['fields'])->keyBy('path');

        // A relationship object is always there; the linkage inside it is only
        // present when it was asked for or included. Collapsing the two would
        // promise data that does not arrive by default.
        $this->assertFalse($fields['data.relationships.author']['conditional']);
        $this->assertTrue($fields['data.relationships.author.data']['conditional']);

        $this->assertSame('object', $fields['data.relationships.author.data']['kind']);
        $this->assertSame('array', $fields['data.relationships.comments.data']['kind']);
    }

    #[Test]
    public function it_emits_the_envelope_containers_as_fields_of_their_own(): void
    {
        // `data.attributes` is a real key in the document, not just a prefix on
        // the paths beneath it — and the field tree only nests a path under a
        // parent it can see. Without these every attribute renders as its own
        // full dotted path, which is the envelope written out fourteen times.
        $paths = $this->paths($this->routes()['GET:v1/posts/{post}']['response']);

        $this->assertContains('data.attributes', $paths);
        $this->assertContains('data.relationships', $paths);
        $this->assertLessThan(
            array_search('data.attributes.title', $paths, true),
            array_search('data.attributes', $paths, true),
            'a container has to be listed before what it contains',
        );
    }

    #[Test]
    public function it_follows_a_related_route_to_the_schema_on_the_other_end(): void
    {
        // `posts/{post}/author` answers with an author, and describing it with
        // a post's fields would be confidently wrong.
        $response = $this->routes()['GET:v1/posts/{post}/author']['response'];
        $paths = $this->paths($response);

        $this->assertSame('Workbench\App\JsonApi\V1\Authors\AuthorSchema', $response['class']);
        $this->assertContains('data.attributes.name', $paths);
        $this->assertNotContains('data.attributes.title', $paths);
    }

    #[Test]
    public function it_reads_a_to_many_related_route_as_a_collection(): void
    {
        $response = $this->routes()['GET:v1/posts/{post}/comments']['response'];
        $paths = $this->paths($response);

        $this->assertSame('Workbench\App\JsonApi\V1\Comments\CommentSchema', $response['class']);
        $this->assertContains('data[].attributes.body', $paths);
    }

    #[Test]
    public function it_reports_a_relationship_endpoint_as_identifiers_only(): void
    {
        // A resource identifier object is type and id and nothing else.
        // Reporting attributes here would promise a payload that never comes.
        $response = $this->routes()['GET:v1/posts/{post}/relationships/author']['response'];

        $this->assertSame('json-api-identifier', $response['source']);
        $this->assertSame(['data', 'data.type', 'data.id'], $this->paths($response));
    }

    #[Test]
    public function it_records_a_delete_as_having_no_content(): void
    {
        // 204 with a body would be a protocol violation, so "no fields" is the
        // answer rather than a failure to find any.
        $response = $this->routes()['DELETE:v1/posts/{post}']['response'];

        $this->assertSame('json-api-none', $response['source']);
        $this->assertSame(204, $response['status']);
        $this->assertSame([], $response['fields']);
    }

    #[Test]
    public function it_describes_the_document_a_write_has_to_send(): void
    {
        $request = $this->routes()['POST:v1/posts']['request'];
        $paths = $this->paths($request);

        $this->assertSame('json-api', $request['source']);
        $this->assertContains('data.type', $paths);
        $this->assertContains('data.attributes.title', $paths);
        $this->assertContains('data.relationships.author.data', $paths);

        // A create has no id to send; an update addresses the resource with one.
        $this->assertNotContains('data.id', $paths);
        $this->assertContains('data.id', $this->paths($this->routes()['PATCH:v1/posts/{post}']['request']));
    }

    #[Test]
    public function it_answers_201_for_a_create_and_200_for_a_read(): void
    {
        $routes = $this->routes();

        $this->assertSame(201, $routes['POST:v1/posts']['response']['status']);
        $this->assertSame(200, $routes['GET:v1/posts']['response']['status']);
    }

    #[Test]
    public function it_leaves_a_plain_laravel_route_to_the_ordinary_analyzers(): void
    {
        // The JSON:API path is tried first, so the check that matters is that
        // it declines rather than claims: an ordinary resource route must still
        // be read from its own controller and form request.
        $response = $this->routes()['GET:api/posts/{post}']['response'];

        $this->assertSame('resource', $response['source']);
        $this->assertSame('Workbench\App\Http\Resources\PostResource', $response['class']);
    }

    #[Test]
    public function it_puts_the_json_api_surface_in_the_api_half(): void
    {
        $this->assertSame('api', $this->routes()['GET:v1/posts']['stack']);
    }

    /**
     * @param  array<string, mixed>  $shape
     * @return array<int, string>
     */
    protected function paths(array $shape): array
    {
        return array_column($shape['fields'], 'path');
    }

    /** @return array<string, array<string, mixed>> */
    protected function routes(): array
    {
        $routes = [];

        foreach ($this->app->make(RouteExporter::class)->export()['routes'] as $route) {
            $routes[$route['id']] = $route;
        }

        return $routes;
    }
}
