<?php

namespace KdrDev\Dissect;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Routing\Router;
use KdrDev\Dissect\Routes\ActionResolver;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use KdrDev\Dissect\Routes\ModelLinker;
use KdrDev\Dissect\Routes\RequestAnalyzer;
use KdrDev\Dissect\Routes\ResponseAnalyzer;
use KdrDev\Dissect\Routes\RouteCollector;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\Routes\RouteFingerprint;
use KdrDev\Dissect\Routes\RuleNormalizer;
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

        $this->app->singleton(MigrationState::class, fn (Application $app) => new MigrationState(
            $app->make(DatabaseManager::class),
        ));

        $this->app->singleton(SchemaExporter::class, fn (Application $app) => new SchemaExporter(
            $app->make(ModelInspector::class),
            $app->make(ColumnNormalizer::class),
            $app->make(MigrationState::class),
            (string) config('dissect.models_path', 'app/Models'),
            config('dissect.models_namespace'),
        ));

        $this->app->singleton(RouteCollector::class, fn (Application $app) => new RouteCollector(
            $app->make(Router::class),
        ));

        $this->app->singleton(ActionResolver::class, fn () => new ActionResolver);

        $this->app->singleton(ClassSource::class, fn () => new ClassSource);

        $this->app->singleton(RuleNormalizer::class, fn () => new RuleNormalizer);

        $this->app->singleton(ModelLinker::class, fn (Application $app) => new ModelLinker(
            $app->make(SchemaExporter::class),
        ));

        $this->app->singleton(RequestAnalyzer::class, fn (Application $app) => new RequestAnalyzer(
            $app->make(ClassSource::class),
            $app->make(RuleNormalizer::class),
            $app->make(ModelLinker::class),
        ));

        $this->app->singleton(RouteFingerprint::class, fn () => new RouteFingerprint(
            (array) config('dissect.routes.watch_paths', ['app', 'routes']),
        ));

        $this->app->singleton(ResponseAnalyzer::class, fn (Application $app) => new ResponseAnalyzer(
            $app->make(ClassSource::class),
            $app->make(ModelLinker::class),
        ));

        $this->app->singleton(RouteExporter::class, fn (Application $app) => new RouteExporter(
            $app->make(RouteCollector::class),
            $app->make(ActionResolver::class),
            $app->make(RequestAnalyzer::class),
            $app->make(ResponseAnalyzer::class),
            $app->make(RouteFingerprint::class),
        ));

        $this->app->singleton(LayoutRepository::class, fn () => new LayoutRepository(
            (string) config('dissect.layout_path', base_path('.dissect/layout.json')),
        ));

        $this->app->singleton(ViewRepository::class, fn () => new ViewRepository(
            (string) config('dissect.views_path', base_path('.dissect/views.json')),
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
