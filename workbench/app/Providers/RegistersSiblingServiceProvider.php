<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * A provider that registers another provider.
 *
 * The one edge that points at a provider rather than at a class somebody
 * resolves: the tree for this one has to continue into SiblingServiceProvider's
 * own bindings instead of stopping at a node named after it.
 */
class RegistersSiblingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(SiblingServiceProvider::class);
    }
}
