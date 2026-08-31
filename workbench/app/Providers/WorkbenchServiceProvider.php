<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyFollowers;

/**
 * Points the package at the fixture models when running under Workbench.
 *
 * Testbench's base_path() is the throwaway skeleton app, not this repository,
 * so the models directory is resolved from this file's own location. That keeps
 * an absolute machine-specific path out of committed config.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__, 3);
        $database = $root.'/workbench/database/database.sqlite';

        if (! file_exists($database)) {
            touch($database);
        }

        // `testbench serve` runs its PHP server with a controlled environment,
        // so shell variables never reach it. A gitignored file at the package
        // root is the toggle instead: write a Vite dev-server URL into
        // .workbench-dev to develop the frontend with hot reload, delete it to
        // go back to the compiled bundle.
        $devServerFile = $root.'/.workbench-dev';

        if (is_file($devServerFile)) {
            config(['dissect.dev_server' => trim((string) file_get_contents($devServerFile))]);
        }

        // The generated development fixture, when it is there. Generating it is
        // the whole switch — see workbench/scripts/generate-huge-models.php —
        // and deleting the directory goes back to the curated models.
        //
        // Never under `testing`: the suite asserts against the small fixture,
        // and a generated schema would quietly redefine what it is testing.
        $huge = $root.'/workbench/app/Huge';
        $useHuge = is_dir($huge)
            && ! $this->app->environment('testing')
            && glob($huge.'/*.php');

        // Testbench defaults to `sync`, which is the one driver that never has
        // anything on it. The fixture app runs the database driver against its
        // own connection so the live surface has a real queue to show —
        // `dissect:seed-queue` puts something on it.
        //
        // Never under `testing`, for the same reason the generated model
        // fixture is not: the suite asserts against a queue it seeds itself,
        // and pointing it at the committed database file would have it read
        // whatever somebody last ran `serve` with.
        if (! $this->app->environment('testing')) {
            config([
                'queue.default' => 'database',
                'queue.connections.database.connection' => 'workbench',
                // The failer and the batch repository each carry their own
                // connection, and both default to an env var this skeleton does
                // not set — without these the history reads a database with no
                // tables in it.
                'queue.failed.database' => 'workbench',
                'queue.batching.database' => 'workbench',
            ]);
        }

        config([
            'dissect.models_path' => $useHuge ? $huge : $root.'/workbench/app/Models',
            'dissect.models_namespace' => $useHuge ? 'Workbench\\App\\Huge' : 'Workbench\\App\\Models',

            // Same reason as the models path: base_path() is the throwaway
            // skeleton, so the defaults ('app', 'routes') would watch a tree
            // holding none of the fixture controllers or route files.
            'dissect.routes.watch_paths' => [
                $root.'/workbench/app',
                $root.'/workbench/routes',
            ],

            // Same again for the job list. The fixture keeps its queueables in
            // the conventional three directories, under the workbench app
            // rather than the skeleton's.
            'dissect.jobs.paths' => [
                $root.'/workbench/app/Jobs',
                $root.'/workbench/app/Listeners',
                $root.'/workbench/app/Mail',
            ],
            'dissect.jobs.watch_paths' => [
                $root.'/workbench/app',
                $root.'/workbench/routes',
            ],
            // Workbench does not report the 'local' environment, and the viewer
            // is local-only by default.
            'dissect.enabled' => true,

            // A file-backed database: `serve` spans many requests, and
            // Testbench's default connection is in-memory, so every request
            // would see an empty schema.
            'database.default' => 'workbench',
            'database.connections.workbench' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],

            // The default database cache store wants a table this skeleton has
            // no reason to carry.
            'cache.default' => 'array',

        ]);
    }

    public function boot(): void
    {
        // What makes NotifyFollowers a *listener* rather than a job is this
        // line: nothing about the class itself says so, and the dispatcher is
        // the only place it is written down.
        Event::listen(PostPublished::class, NotifyFollowers::class);
    }
}
