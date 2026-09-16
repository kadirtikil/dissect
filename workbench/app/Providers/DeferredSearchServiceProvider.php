<?php

namespace Workbench\App\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Services\NullSearchIndex;

/**
 * A deferred provider, which declares what it binds twice over.
 *
 * `provides()` returns a literal array, the same shape ClassSource already
 * reads for a job's `backoff()`, so the promise can be read without running
 * anything — and compared against what `register()` actually binds.
 */
class DeferredSearchServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('workbench.null-index', NullSearchIndex::class);
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['workbench.null-index'];
    }
}
