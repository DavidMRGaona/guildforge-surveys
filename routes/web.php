<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Surveys\Http\Controllers\RespondController;

/*
|--------------------------------------------------------------------------
| Web routes of the surveys module
|--------------------------------------------------------------------------
|
| Answering a survey: with an account, with a guest's link or with the public
| link. A GET only shows the page (mail scanners opening a link change nothing);
| answering is a POST.
|
*/

Route::prefix('encuestas')
    ->name('surveys.')
    ->middleware('throttle:surveys-responses')
    ->group(function (): void {
        Route::get('{survey}/invitado/{token}', [RespondController::class, 'showGuest'])
            ->whereUuid('survey')->where('token', '[a-f0-9]{64}')->name('guest');
        Route::post('{survey}/invitado/{token}', [RespondController::class, 'storeGuest'])
            ->whereUuid('survey')->where('token', '[a-f0-9]{64}')->name('guest.store');

        Route::get('{survey}/abierta/{token}', [RespondController::class, 'showPublic'])
            ->whereUuid('survey')->where('token', '[a-f0-9]{64}')->name('public');
        Route::post('{survey}/abierta/{token}', [RespondController::class, 'storePublic'])
            ->whereUuid('survey')->where('token', '[a-f0-9]{64}')->name('public.store');

        Route::middleware(['auth', 'email.verified-if-required'])->group(function (): void {
            Route::get('{survey}', [RespondController::class, 'show'])->whereUuid('survey')->name('respond');
            Route::post('{survey}', [RespondController::class, 'store'])->whereUuid('survey')->name('respond.store');
        });
    });
