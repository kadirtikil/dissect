<?php

namespace KdrDev\Dissect;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelInspector;
use KdrDev\Dissect\Types\TypeNormalizerManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DissectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dissect.php', 'dissect');

        $this->app->singleton(TypeNormalizerManager::class, fn (Application $app) => new TypeNormalizerManager(
            $app->make(DatabaseManager::class),
        ));

        $this->app->singleton(ColumnNormalizer::class, fn (Application $app) => new ColumnNormalizer(
            $app->make(TypeNormalizerManager::class),
        ));

        $this->app->singleton(SchemaExporter::class, fn (Application $app) => new SchemaExporter(
            $app->make(ModelInspector::class),
            $app->make(ColumnNormalizer::class),
            (string) config('dissect.models_path', 'app/Models'),
            config('dissect.models_namespace'),
        ));

        $this->app->singleton(LayoutRepository::class, fn () => new LayoutRepository(
            (string) config('dissect.layout_path', base_path('.dissect/layout.json')),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dissect');

        $this->publishes([
            __DIR__.'/../config/dissect.php' => config_path('dissect.php'),
        ], 'dissect-config');

        if ($this->shouldRegisterRoutes()) {
            $this->registerRoutes();
        }
    }

    /**
     * The viewer exposes the full schema and writes a layout file, so it stays
     * off outside local development unless switched on deliberately.
     */
    protected function shouldRegisterRoutes(): bool
    {
        $enabled = config('dissect.enabled');

        return $enabled === null
            ? $this->app->environment('local')
            : (bool) $enabled;
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => config('dissect.path', 'dissect'),
            'middleware' => config('dissect.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }
}
