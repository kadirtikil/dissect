<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * A base class for providers, which is not itself one.
 *
 * It is a ServiceProvider subclass by every check a reader can make except the
 * one that matters: it can never be registered, so listing it would put a
 * provider on the surface that does not exist at runtime.
 */
abstract class AbstractDomainServiceProvider extends ServiceProvider
{
    /**
     * @return array<class-string, class-string>
     */
    abstract protected function domainBindings(): array;

    public function register(): void
    {
        foreach ($this->domainBindings() as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }
}
