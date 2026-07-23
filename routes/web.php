<?php

use Illuminate\Support\Facades\Route;
use KdrDev\Dissect\Http\Controllers\DissectController;

Route::get('/', [DissectController::class, 'index'])->name('dissect.index');
Route::get('/schema.json', [DissectController::class, 'schemaJson'])->name('dissect.schema');
Route::get('/fingerprint', [DissectController::class, 'fingerprintJson'])->name('dissect.fingerprint');
Route::post('/layout', [DissectController::class, 'saveLayout'])->name('dissect.layout');
Route::get('/views.json', [DissectController::class, 'viewsJson'])->name('dissect.views');
Route::post('/views', [DissectController::class, 'saveViews'])->name('dissect.views.save');
Route::get('/assets/{file}', [DissectController::class, 'asset'])
    ->where('file', '[A-Za-z0-9._-]+')
    ->name('dissect.asset');
