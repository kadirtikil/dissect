<?php

namespace KdrDev\Dissect\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app): void
    {
        // Tests run under the `testing` environment, where routes are off by
        // default — switch them on deliberately, and point the exporter at the
        // Workbench fixture models rather than a non-existent app/Models.
        $app['config']->set('dissect.enabled', true);
        $app['config']->set('dissect.models_path', __DIR__.'/../workbench/app/Models');
        $app['config']->set('dissect.models_namespace', 'Workbench\\App\\Models');
        $app['config']->set('dissect.layout_path', $this->layoutPath($app));

        $app['config']->set('database.default', 'testing');
    }

    protected function layoutPath(Application $app): string
    {
        return $app->basePath('.dissect-test/layout.json');
    }
}
