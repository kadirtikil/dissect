<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Http\Controllers\SearchController;
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
