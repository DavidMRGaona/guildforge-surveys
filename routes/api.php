<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes of the surveys module
|--------------------------------------------------------------------------
|
| The module loader already applies the "api" middleware and the "/api"
| prefix: these routes answer under /api/surveys/...
|
*/

Route::prefix('surveys')
    ->name('surveys.api.')
    ->group(function (): void {
        // Route::apiResource('items', ItemController::class);
    });
