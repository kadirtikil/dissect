<?php

namespace KdrDev\Dissect\Tests\Concerns;

/**
 * Points every surface at the domain fixture in workbench/domains: two folder
 * domains (Billing, Sales), one from domains.json and an attribute (Identity),
 * a stale override and an unassigned class.
 *
 * The plain workbench app stays what the rest of the suite reads, so it keeps
 * proving that an app with no domains is left as it was.
 */
trait UsesDomainFixture
{
    protected function domainFixture(): string
    {
        return realpath(__DIR__.'/../../workbench/domains') ?: __DIR__.'/../../workbench/domains';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $root = $this->domainFixture();

        $app['config']->set('dissect.domains.root', $root);
        $app['config']->set('dissect.domains.file', $root.'/.dissect/domains.json');
        $app['config']->set('dissect.models.paths', [$root.'/app/Models', $root.'/app/Domain/*/Models']);
        // Jobs and providers take no `*` yet, unlike models.
        $app['config']->set('dissect.jobs.paths', [$root.'/app/Domain/Billing/Jobs', $root.'/app/Domain/Sales/Jobs']);
        $app['config']->set('dissect.jobs.watch_paths', [$root.'/app']);
        $app['config']->set('dissect.routes.watch_paths', [$root.'/app', $root.'/routes.php']);
        $app['config']->set('dissect.providers.paths', [$root.'/app/Domain/Billing/Providers']);
        $app['config']->set('dissect.providers.watch_paths', [$root.'/app']);
    }

    protected function defineRoutes($router): void
    {
        require $this->domainFixture().'/routes.php';
    }
}
