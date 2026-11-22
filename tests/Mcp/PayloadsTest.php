<?php

namespace KdrDev\Dissect\Tests\Mcp;

use KdrDev\Dissect\Mcp\Payloads;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading through a real artisan, as the server does. Testbench's binary is
 * this package's artisan, so these start a PHP process each; which app it
 * reads depends on the workbench, so only the shape is checked.
 */
class PayloadsTest extends TestCase
{
    protected function payloads(): Payloads
    {
        $root = dirname(__DIR__, 2);

        return Payloads::fresh([PHP_BINARY, $root.'/vendor/bin/testbench'], $root);
    }

    #[Test]
    public function one_surface_is_read_through_artisan(): void
    {
        $schema = $this->payloads()->get('schema');

        $this->assertArrayHasKey('nodes', $schema);
        $this->assertArrayHasKey('edges', $schema);
        $this->assertNotEmpty($schema['nodes']);
    }

    #[Test]
    public function every_surface_is_one_process(): void
    {
        $this->assertSame(['schema', 'routes', 'jobs', 'providers', 'domains', 'classes', 'boundaries'], array_keys($this->payloads()->all()));
    }

    #[Test]
    public function a_failure_carries_what_artisan_said(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no surface called "nope"');

        $this->payloads()->get('nope');
    }
}
