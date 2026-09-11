<?php

use Illuminate\Support\Facades\Route;
use LaravelJsonApi\Laravel\Facades\JsonApiRoute;
use LaravelJsonApi\Laravel\Http\Controllers\JsonApiController;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Http\Controllers\SearchController;
use Workbench\App\Jobs\PruneComments;
use Workbench\App\Models\Author;

/**
 * The API half of the route fixture.
 *
 * Testbench groups this file under the `api` middleware but adds no prefix, so
 * the prefix is declared here — the exported URIs should read `api/posts`, the
 * way they would in a real application.
 */
Route::prefix('api')->group(function () {
    Route::apiResource('posts', PostController::class);

    Route::get('search', SearchController::class)->name('search');

    // A closure route: no controller class to reflect, so the exporter has to
    // report it honestly rather than skipping it.
    Route::get('authors/{author}/stats', function (Author $author) {
        // A dispatch site inside a route closure: no controller class to match
        // on, so the join has to address it by where it is written.
        PruneComments::dispatch()->onQueue('maintenance');

        return response()->json([
            'author_id' => $author->id,
            'posts' => $author->posts()->count(),
        ]);
    })->name('authors.stats');

    // An optional parameter and a constrained one, both of which show up in the
    // parameter list.
    Route::get('feed/{category?}', [PostController::class, 'index'])
        ->where('category', '[a-z-]+')
        ->name('feed');
});

/**
 * The JSON:API half.
 *
 * Registered through the package's own registrar rather than as plain routes,
 * because how it registers them is exactly what the exporter has to read: the
 * controller is generic, so the shape of a response lives in the schema bound
 * to the resource type rather than anywhere the route itself points at.
 */
JsonApiRoute::server('v1')
    ->prefix('v1')
    ->resources(function ($server) {
        $server->resource('posts', JsonApiController::class)
            ->relationships(function ($relations) {
                $relations->hasOne('author');
                $relations->hasMany('comments');
            });

        $server->resource('authors', JsonApiController::class)->only('index', 'show');

        // Writable, and deliberately without a `CommentRequest` beside its
        // schema: a resource whose document shape is known but whose
        // constraints are written nowhere.
        $server->resource('comments', JsonApiController::class);
    });
