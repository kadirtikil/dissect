<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The plain workbench app follows no domain layout, has no domains.json and
 * no attribute: it has no domains, and says so without an error.
 */
class NoDomainsTest extends TestCase
{
    #[Test]
    public function a_plain_app_has_no_domains(): void
    {
        config()->set('dissect.domains.root', realpath(__DIR__.'/../../workbench'));

        $payload = $this->getJson('/dissect/domains.json')->assertOk()->json();

        $this->assertTrue($payload['enabled']);
        $this->assertSame([], $payload['domains']);
        $this->assertSame([], $payload['findings']);
        $this->assertSame([], $payload['problems']);
        $this->assertContains('Workbench\\App\\Models\\Post', $payload['unassigned']);
    }
}
