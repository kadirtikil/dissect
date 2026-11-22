<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The plain workbench app follows no domain layout, has no domains.json and
 * no attribute: it has no domains, and says so without an error.
 */
class NoDomainsTest extends TestCase
{
    use RefreshDatabase;

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
        $this->get('/dissect')->assertOk()->assertSee('domains: []', escape: false);
    }

    #[Test]
    public function no_row_of_a_plain_app_has_a_domain(): void
    {
        // The class index walks the source folders under the domain root.
        config()->set('dissect.domains.root', realpath(__DIR__.'/../../workbench'));

        $surfaces = [
            'schema.json' => 'nodes',
            'routes.json' => 'routes',
            'jobs.json' => 'jobs',
            'providers.json' => 'providers',
            'classes.json' => 'classes',
        ];

        foreach ($surfaces as $surface => $key) {
            $rows = $this->getJson('/dissect/'.$surface)->assertOk()->json($key);

            $this->assertNotEmpty($rows, $surface);

            foreach ($rows as $row) {
                $this->assertArrayHasKey('context', $row, $surface);
                $this->assertNull($row['context'], $surface);
            }
        }
    }

    #[Test]
    public function a_plain_app_has_no_rules_and_nothing_to_break(): void
    {
        config()->set('dissect.domains.root', realpath(__DIR__.'/../../workbench'));

        $payload = $this->getJson('/dissect/boundaries.json')->assertOk()->json();

        $this->assertSame([], $payload['rules']);
        $this->assertSame([], $payload['violations']);
        $this->assertSame([], $payload['problems']);
    }
}
