<?php

namespace KdrDev\Dissect\Tests;

use InvalidArgumentException;
use KdrDev\Dissect\Support\Surfaces;
use PHPUnit\Framework\Attributes\Test;

class SurfacesTest extends TestCase
{
    #[Test]
    public function every_surface_is_the_payload_its_endpoint_serves(): void
    {
        $surfaces = $this->app->make(Surfaces::class);

        foreach (['schema' => 'nodes', 'routes' => 'routes', 'jobs' => 'jobs', 'providers' => 'providers', 'domains' => 'domains'] as $name => $key) {
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
    public function an_unknown_surface_says_what_there_is(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pick one of: schema, routes, jobs, providers, domains.');

        $this->app->make(Surfaces::class)->export('queue');
    }
}
