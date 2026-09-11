<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Support\Facades\Route;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The endpoint list.
 *
 * Asserted against the fixture routes in `workbench/routes/`, which exist to
 * cover the shapes an exporter has to survive: a controller, an invokable, a
 * closure, an optional parameter and a bound model.
 */
class RoutesTest extends TestCase
{
    #[Test]
    public function it_exports_the_fixture_endpoints(): void
    {
        $routes = $this->routes();

        $this->assertArrayHasKey('GET:api/posts', $routes);
        $this->assertArrayHasKey('POST:api/posts', $routes);
        $this->assertArrayHasKey('PUT|PATCH:api/posts/{post}', $routes);
    }

    #[Test]
    public function it_drops_the_head_verb_laravel_pairs_with_every_get(): void
    {
        // True, but it says nothing and doubles the width of the busiest column.
        $this->assertSame(['GET'], $this->routes()['GET:api/posts']['methods']);
    }

    #[Test]
    public function it_reads_the_controller_behind_a_route(): void
    {
        $action = $this->routes()['POST:api/posts']['action'];

        $this->assertSame('controller', $action['type']);
        $this->assertSame('Workbench\App\Http\Controllers\PostController', $action['class']);
        $this->assertSame('store', $action['method']);
        $this->assertSame('PostController@store', $action['label']);
    }

    #[Test]
    public function it_reports_an_invokable_controller_without_a_method_suffix(): void
    {
        $action = $this->routes()['GET:api/search']['action'];

        $this->assertSame('__invoke', $action['method']);
        $this->assertSame('SearchController', $action['label']);
    }

    #[Test]
    public function it_names_a_closure_route_by_where_it_was_written(): void
    {
        // A closure has no other identity, and the file and line is the only
        // thing that helps somebody find it again.
        $action = $this->routes()['GET:api/authors/{author}/stats']['action'];

        $this->assertSame('closure', $action['type']);
        $this->assertNull($action['class']);
        $this->assertStringStartsWith('api.php:', $action['label']);
    }

    #[Test]
    public function it_describes_a_placeholder_without_binding_it_to_a_model(): void
    {
        // `{post}` is type-hinted `Post` in the controller, and that is
        // deliberately not reported: the endpoint's contract is how it is
        // addressed, not which node the binding resolves to.
        $parameter = $this->routes()['GET:api/posts/{post}']['parameters'][0];

        $this->assertSame('post', $parameter['name']);
        $this->assertFalse($parameter['optional']);
        $this->assertArrayNotHasKey('model', $parameter);
    }

    #[Test]
    public function it_splits_the_table_by_the_stack_a_route_was_registered_into(): void
    {
        $routes = $this->routes();

        // The two halves of the fixture, and the difference that matters: one
        // is session-backed, the other stateless.
        $this->assertSame('api', $routes['GET:api/posts']['stack']);
        $this->assertSame('web', $routes['GET:authors']['stack']);
    }

    #[Test]
    public function it_reads_the_stack_from_the_group_rather_than_the_uri(): void
    {
        // A prefix is a convention; the middleware group is a behaviour. An API
        // served from somewhere other than /api is still an API.
        Route::middleware('api')->get('v2/widgets', fn () => []);

        $this->assertSame('api', $this->routes()['GET:v2/widgets']['stack']);
    }

    #[Test]
    public function it_refuses_to_place_a_route_in_neither_group(): void
    {
        // A console route, or one registered outside both files. Inventing a
        // half for it would put it under a heading that is not true.
        Route::get('unstacked', fn () => []);

        $this->assertSame('other', $this->routes()['GET:unstacked']['stack']);
    }

    #[Test]
    public function it_marks_an_optional_parameter_as_optional(): void
    {
        $parameter = $this->routes()['GET:api/feed/{category?}']['parameters'][0];

        $this->assertTrue($parameter['optional']);
        $this->assertSame('[a-z-]+', $parameter['pattern']);
    }

    #[Test]
    public function it_separates_application_routes_from_everybody_elses(): void
    {
        // Route::view() runs a controller belonging to the framework, so it is
        // both the group check and the synthetic-action check in one.
        Route::view('brochure', 'workbench::authors');

        $routes = $this->routes();

        $this->assertSame('app', $routes['GET:api/posts']['group']);
        $this->assertSame('framework', $routes['GET:brochure']['group']);
        $this->assertSame('view', $routes['GET:brochure']['action']['type']);
    }

    #[Test]
    public function it_gathers_group_and_route_middleware(): void
    {
        $middleware = $this->routes()['POST:authors']['middleware'];

        $this->assertContains('web', $middleware);
        $this->assertContains('throttle:10,1', $middleware);
    }

    #[Test]
    public function it_reads_a_form_request_by_running_its_rules(): void
    {
        $request = $this->routes()['POST:api/posts']['request'];

        $this->assertSame('form-request', $request['source']);
        $this->assertSame('Workbench\App\Http\Requests\StorePostRequest', $request['class']);
        // Nothing was inferred: the framework's own rules() produced this.
        $this->assertSame('certain', $request['confidence']);

        $title = $this->field($request, 'title');
        $this->assertTrue($title['required']);
        $this->assertSame('string', $title['type']);
    }

    #[Test]
    public function it_flattens_a_nested_rule_path(): void
    {
        // `tags.*.name` in the rules, `tags[].name` on the wire — one grammar
        // shared with the response side so both render through one component.
        $request = $this->routes()['POST:api/posts']['request'];

        $this->assertNotNull($this->field($request, 'tags[].name'));
    }

    #[Test]
    public function it_falls_back_to_the_source_when_rules_cannot_be_run(): void
    {
        // UpdatePostRequest::rules() reaches for $this->route(), which is not
        // there outside a request cycle. Reading the literal is the answer, and
        // the confidence is how the viewer admits it.
        $request = $this->routes()['PUT|PATCH:api/posts/{post}']['request'];

        $this->assertSame('inferred', $request['confidence']);
        $this->assertSame('sometimes', $this->field($request, 'title')['rules'][0]);
    }

    #[Test]
    public function it_reduces_a_fluent_rule_to_the_table_it_names(): void
    {
        // Rule::unique('posts')->ignore(…) — the chained half refines the rule,
        // the head of the chain is the half worth reporting.
        $title = $this->field($this->routes()['PUT|PATCH:api/posts/{post}']['request'], 'title');

        $this->assertContains('unique:posts', $title['rules']);
    }

    #[Test]
    public function it_reads_validation_written_inline_in_the_action(): void
    {
        $request = $this->routes()['POST:authors']['request'];

        $this->assertSame('inline-validate', $request['source']);
        $this->assertNull($request['class']);
        $this->assertSame('email', $this->field($request, 'email')['type']);
    }

    #[Test]
    public function it_reports_an_exists_rule_verbatim(): void
    {
        // The rule names the table it checks, and reading it says everything
        // resolving it to a node would have — without the endpoint's contract
        // depending on the model graph having found that model.
        $request = $this->routes()['POST:api/posts']['request'];

        $this->assertContains('exists:authors,id', $this->field($request, 'author_id')['rules']);
        $this->assertContains('exists:categories,id', $this->field($request, 'category_id')['rules']);
    }

    #[Test]
    public function it_separates_no_validation_from_unreadable_validation(): void
    {
        // "This endpoint validates nothing" is a finding; "this could not be
        // read" is an admission. Collapsing them would make the second look
        // like the first.
        $request = $this->routes()['GET:api/posts']['request'];

        $this->assertSame('none', $request['source']);
        $this->assertSame('certain', $request['confidence']);
        $this->assertSame([], $request['fields']);
    }

    #[Test]
    public function it_reads_a_resources_fields(): void
    {
        $response = $this->routes()['GET:api/posts/{post}']['response'];

        $this->assertSame('resource', $response['source']);
        $this->assertSame('Workbench\App\Http\Resources\PostResource', $response['class']);
        // PostResource declares @mixin Post, so its keys resolve against
        // something known and the shape can be trusted. Which model it names
        // is not reported — only that it said.
        $this->assertSame('certain', $response['confidence']);

        $this->assertSame('scalar', $this->field($response, 'title')['kind']);
    }

    #[Test]
    public function it_follows_a_nested_resource_into_its_own_fields(): void
    {
        $response = $this->routes()['GET:api/posts/{post}']['response'];

        $this->assertSame('object', $this->field($response, 'author')['kind']);
        $this->assertNotNull($this->field($response, 'author.name'));

        // A collection is marked on the path itself, the same `[]` the request
        // side uses for a repeated rule.
        $this->assertSame('array', $this->field($response, 'comments[]')['kind']);
        $this->assertNotNull($this->field($response, 'comments[].body'));
    }

    #[Test]
    public function it_marks_a_conditional_key_as_not_always_present(): void
    {
        // whenLoaded() and when() mean the key may simply not be there, which a
        // consumer treating this as a contract has to know.
        $response = $this->routes()['GET:api/posts/{post}']['response'];

        $this->assertTrue($this->field($response, 'author')['conditional']);
        $this->assertTrue($this->field($response, 'meta')['conditional']);
        $this->assertFalse($this->field($response, 'title')['conditional']);
    }

    #[Test]
    public function it_finds_the_resource_behind_an_anonymous_collection(): void
    {
        // The return type says AnonymousResourceCollection, which names nothing.
        // The class that was collected is in the body.
        $response = $this->routes()['GET:api/posts']['response'];

        $this->assertSame('resource-collection', $response['source']);
        $this->assertSame('Workbench\App\Http\Resources\PostResource', $response['class']);
    }

    #[Test]
    public function it_admits_a_shape_it_had_to_infer(): void
    {
        // SearchHitResource declares no @mixin, so its keys were read without
        // anything to check them against. The fields are still worth
        // reporting; claiming they are certain would not be.
        $response = $this->routes()['GET:api/search']['response'];

        $this->assertSame('inferred', $response['confidence']);
        $this->assertNotNull($this->field($response, 'label'));
    }

    #[Test]
    public function it_reads_a_hand_built_json_payload_and_its_status(): void
    {
        $response = $this->routes()['DELETE:api/posts/{post}']['response'];

        $this->assertSame('json', $response['source']);
        $this->assertNotNull($this->field($response, 'deleted'));
    }

    #[Test]
    public function it_reports_an_explicit_status_code(): void
    {
        $this->assertSame(201, $this->routes()['POST:api/posts']['response']['status']);
    }

    #[Test]
    public function it_records_a_view_or_redirect_as_having_no_body(): void
    {
        $routes = $this->routes();

        $this->assertSame('view', $routes['GET:authors']['response']['source']);
        $this->assertSame([], $routes['GET:authors']['response']['fields']);
        $this->assertSame('redirect', $routes['POST:authors']['response']['source']);
    }

    #[Test]
    public function it_carries_no_model_references_at_all(): void
    {
        // The decoupling, guarded end to end: routes describe endpoints, and
        // the model graph is a different context. One endpoint that touches
        // four models by every old measure is the sharpest place to check.
        $route = $this->routes()['GET:api/posts/{post}'];

        $this->assertArrayNotHasKey('models', $route);

        foreach ($route['parameters'] as $parameter) {
            $this->assertArrayNotHasKey('model', $parameter);
        }

        foreach ([...$route['request']['fields'], ...$route['response']['fields']] as $field) {
            $this->assertArrayNotHasKey('model', $field);
            $this->assertArrayNotHasKey('column', $field);
        }
    }

    #[Test]
    public function it_serves_the_endpoint_list_over_http(): void
    {
        $this->get('/dissect/routes.json')
            ->assertOk()
            ->assertJsonStructure(['routes', 'generated_at'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function it_reports_the_route_signal_only_when_asked_for_it(): void
    {
        // Stating the route half means walking a much wider tree than the model
        // half does, so a page that never opens the endpoint list never pays.
        $this->get('/dissect/fingerprint')
            ->assertOk()
            ->assertJsonMissingPath('routes');

        $this->get('/dissect/fingerprint?routes=1')
            ->assertOk()
            ->assertJsonPath('routes', $this->app->make(RouteExporter::class)->fingerprint());
    }

    /**
     * The export keyed by route id, since that is how the client addresses it.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function routes(): array
    {
        $export = $this->app->make(RouteExporter::class)->export();

        return array_column($export['routes'], null, 'id');
    }

    /**
     * One described field by path, failing loudly when it is missing — an
     * assertion against null would report the wrong thing.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    protected function field(array $shape, string $path): array
    {
        $fields = array_column($shape['fields'], null, 'path');

        $this->assertArrayHasKey($path, $fields, "No field described at “{$path}”.");

        return $fields[$path];
    }
}
