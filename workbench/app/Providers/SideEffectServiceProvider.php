<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyFollowers;

/**
 * A provider that binds nothing and still does plenty.
 *
 * Every call here is something the provider *does* rather than something it
 * depends on: an event wired to a listener, a gate defined, config merged,
 * files published, migrations loaded. Drawn as edges they would bury the two or
 * three real dependencies in a surface that is mostly noise, so they belong on
 * the provider node as badges.
 *
 * The listener is the exception worth arguing about — it does name a class this
 * provider points at.
 */
class SideEffectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/workbench.php', 'workbench');
    }

    public function boot(): void
    {
        Event::listen(PostPublished::class, NotifyFollowers::class);

        Gate::define('publish-post', fn ($user) => true);

        $this->publishes([
            __DIR__.'/../../config/workbench.php' => config_path('workbench.php'),
        ], 'workbench-config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
