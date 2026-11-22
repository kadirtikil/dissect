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
    public function a_check_through_artisan_is_a_verdict_whichever_way_it_goes(): void
    {
        // Exit 1 (a rule broken) is an answer, not a failure to run.
        $check = $this->payloads()->check(['app/Nowhere.php']);

        $this->assertIsBool($check['passed']);
        $this->assertSame([], $check['violations']);
        $this->assertArrayHasKey('rules', $check);
    }

    #[Test]
    public function changes_against_a_baseline_that_is_not_there_say_so_in_words(): void
    {
        // The workbench app has no committed snapshot: dissect:diff exits 1
        // with the reason, which is passed on without the console's ERROR.
        try {
            $this->payloads()->changes('snapshot');
            $this->fail('No snapshot, yet changes were read.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('There is no snapshot at ', $e->getMessage());
            $this->assertStringContainsString('Take one with: php artisan dissect:snapshot', $e->getMessage());
        }
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
