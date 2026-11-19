<?php

use Illuminate\Support\Facades\Route;
use Workbench\Domains\Domain\Billing\Http\Controllers\InvoiceController;
use Workbench\Domains\Domain\Sales\Http\Controllers\QuoteController;
use Workbench\Domains\Http\Controllers\Auth\LoginController;

// Registered only when the workbench runs the domain fixture.
Route::prefix('api/domains')->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);
    Route::get('quotes', [QuoteController::class, 'index']);
    Route::post('login', LoginController::class);
});
