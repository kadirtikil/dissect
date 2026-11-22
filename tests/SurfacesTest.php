<?php

namespace KdrDev\Dissect\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\Support\PayloadCache;
use KdrDev\Dissect\Support\Surfaces;
use PHPUnit\Framework\Attributes\Test;

class SurfacesTest extends TestCase
{
    #[Test]
    public function every_surface_is_the_payload_its_endpoint_serves(): void
    {
        $surfaces = $this->app->make(Surfaces::class);

        foreach (['schema' => 'nodes', 'routes' => 'routes', 'jobs' => 'jobs', 'providers' => 'providers', 'domains' => 'domains', 'classes' => 'classes', 'boundaries' => 'violations'] as $name => $key) {
            $payload = $surfaces->export($name);
            $served = $this->getJson("/dissect/{$name}.json")->assertOk()->json();

            $this->assertArrayHasKey($key, $payload, $name);
            $this->assertSame(
                array_column($served[$key], $name === 'domains' ? 'name' : 'id'),
                array_column($payload[$key], $name === 'domains' ? 'name' : 'id'),
                $name,
            );
        }
    }

    #[Test]
    public function the_console_is_served_what_the_page_cached(): void
    {
        $key = $this->app->make(PayloadCache::class)->key('routes', $this->app->make(RouteExporter::class)->fingerprint());

        // Planted where the page would have left it, so only a read through
        // the same cache can return it.
        Cache::put($key, ['routes' => [['id' => 'GET:planted']]], 60);

        $this->assertSame([['id' => 'GET:planted']], $this->app->make(Surfaces::class)->export('routes')['routes']);
        $this->assertSame(0, Artisan::call('dissect:export', ['surface' => 'routes']));
        $this->assertStringContainsString('GET:planted', Artisan::output());
        $this->assertSame('GET:planted', $this->getJson('/dissect/routes.json')->json('routes.0.id'));
    }

    #[Test]
    public function the_page_is_served_what_the_console_built(): void
    {
        $surfaces = $this->app->make(Surfaces::class);
        $key = $this->app->make(PayloadCache::class)->key('schema', $this->app->make(SchemaExporter::class)->fingerprint());

        $this->assertFalse(Cache::has($key));

        $built = $surfaces->export('schema');

        $this->assertSame($built, Cache::get($key));
        $this->assertSame(array_column($built['nodes'], 'id'), array_column($this->getJson('/dissect/schema.json')->json('nodes'), 'id'));
    }

    #[Test]
    public function an_unknown_surface_says_what_there_is(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pick one of: schema, routes, jobs, providers, domains, classes, boundaries.');

        $this->app->make(Surfaces::class)->export('queue');
    }
}
