<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\AuthorController;

/**
 * The web half: a view response and a redirect response, neither of which has a
 * body to describe — the case the detail pane has to render without looking
 * broken.
 */
Route::get('authors', [AuthorController::class, 'index'])->name('authors.index');

Route::post('authors', [AuthorController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('authors.store');
