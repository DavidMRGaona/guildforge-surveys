<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Surveys\Http\Controllers\OrganizerSurveyController;
use Modules\Surveys\Http\Controllers\OrganizerSurveyEditorController;
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

/*
| Managing a subject's surveys on the public site: for whoever its source lets manage the
| subject, asked live on every request. Module pages link to surveys.subject.
*/
Route::prefix('encuestas')
    ->name('surveys.')
    ->middleware(['auth', 'email.verified-if-required'])
    ->group(function (): void {
        Route::get('sujeto/{type}/{subject}', [OrganizerSurveyController::class, 'subject'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->name('subject');
        Route::get('{survey}/editar', [OrganizerSurveyEditorController::class, 'edit'])->whereUuid('survey')->name('manage.edit');
        Route::put('{survey}/editar', [OrganizerSurveyEditorController::class, 'update'])->whereUuid('survey')->name('manage.update');
        Route::post('{survey}/enviar', [OrganizerSurveyController::class, 'send'])->whereUuid('survey')->name('manage.send');
        Route::post('{survey}/descartar', [OrganizerSurveyController::class, 'dismiss'])->whereUuid('survey')->name('manage.dismiss');
    });
