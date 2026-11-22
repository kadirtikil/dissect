<?php

namespace KdrDev\Dissect\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The page and its gate guard HTTP. Whoever runs a command already has a
 * shell on the machine, so the commands are there with the page switched off.
 */
class ExportWithoutViewerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('dissect.enabled', false);
    }

    #[Test]
    public function it_exports_with_the_viewer_switched_off(): void
    {
        $this->get('/dissect')->assertNotFound();

        $this->assertSame(0, Artisan::call('dissect:export', ['surface' => 'schema']));
        $this->assertArrayHasKey('nodes', json_decode(Artisan::output(), true));
    }
}
