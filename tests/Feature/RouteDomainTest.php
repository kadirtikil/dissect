<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Routing\Router;
use KdrDev\Dissect\Jobs\RouteMap;
use KdrDev\Dissect\Routes\ActionResolver;
use KdrDev\Dissect\Routes\RouteCollector;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\PostController;

/**
 * One path on several domains is several endpoints: an admin dashboard and a
 * tenant dashboard share a URI and nothing else.
 */
class RouteDomainTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $router) {
            $router->get('dashboard', fn () => 'plain');
            $router->domain('admin.example.test')->get('dashboard', fn () => 'admin');
            $router->domain('{account}.example.test')->get('dashboard', fn () => 'tenant');
            $router->domain('admin.example.test')->get('/', fn () => 'admin home');
        });
    }

    #[Test]
    public function the_same_path_on_two_domains_is_two_endpoints(): void
    {
        $ids = array_column($this->app->make(RouteExporter::class)->export()['routes'], 'id');

        $this->assertContains('GET:dashboard', $ids);
        $this->assertContains('GET://admin.example.test/dashboard', $ids);
        $this->assertContains('GET://{account}.example.test/dashboard', $ids);
        $this->assertContains('GET://admin.example.test/', $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'every route has an id of its own');
    }

    #[Test]
    public function a_route_without_a_domain_keeps_the_id_it_always_had(): void
    {
        // Job links and the page's selection already hold these.
        $this->assertSame('POST:api/posts', RouteExporter::id(['POST'], 'api/posts'));
        $this->assertSame('GET:/', RouteExporter::id(['GET'], '/'));
        $this->assertSame('GET:/', RouteExporter::id(['GET'], '/', ''));
    }

    #[Test]
    public function a_job_dispatched_behind_a_domain_route_links_to_that_route(): void
    {
        // A router holding only the domain route, so the link cannot come
        // from the workbench's own `POST api/posts`.
        $router = new Router($this->app['events'], $this->app);
        $router->domain('admin.example.test')->middleware('api')
            ->post('api/posts', [PostController::class, 'store']);

        $map = new RouteMap(new RouteCollector($router), $this->app->make(ActionResolver::class));

        $this->assertSame(
            'POST://admin.example.test/api/posts',
            $map->forContext(PostController::class.'@store'),
        );
    }
}
