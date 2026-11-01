<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Surveys\Http\Controllers\OrganizerSurveyController;
use Modules\Surveys\Http\Controllers\OrganizerSurveyCopyController;
use Modules\Surveys\Http\Controllers\OrganizerSurveyEditorController;
use Modules\Surveys\Http\Controllers\OrganizerSurveyResultsController;
use Modules\Surveys\Http\Controllers\OrganizerTrendsController;
use Modules\Surveys\Http\Controllers\RespondController;

/*
|--------------------------------------------------------------------------
| Web routes of the surveys module
|--------------------------------------------------------------------------
|
| Answering a survey: with an account, with a guest's link or with the public
| link. A GET only shows the page (mail scanners opening a link change nothing);
| answering is a POST. The verified public link asks for an address and mails it
| a guest's link, with its own, tighter limit.
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
        Route::post('{survey}/abierta/{token}/correo', [RespondController::class, 'requestPublicLink'])
            ->whereUuid('survey')->where('token', '[a-f0-9]{64}')
            ->middleware('throttle:surveys-access-links')->name('public.request');

        Route::middleware(['auth', 'email.verified-if-required'])->group(function (): void {
            Route::get('{survey}', [RespondController::class, 'show'])->whereUuid('survey')->name('respond');
            Route::post('{survey}', [RespondController::class, 'store'])->whereUuid('survey')->name('respond.store');
        });
    });

/*
| Managing a subject's surveys on the public site: for whoever its source lets manage the
| subject, asked live on every request. Module pages link to surveys.subject. Trends go by
| the organizers in each survey's snapshot, which keeps them after the subject is gone.
*/
Route::prefix('encuestas')
    ->name('surveys.')
    ->middleware(['auth', 'email.verified-if-required'])
    ->group(function (): void {
        Route::get('tendencias', [OrganizerTrendsController::class, 'show'])->name('manage.trends');
        Route::get('sujeto/{type}/{subject}', [OrganizerSurveyController::class, 'subject'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->name('subject');
        Route::post('sujeto/{type}/{subject}/preparar', [OrganizerSurveyController::class, 'prepare'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->name('manage.prepare');
        Route::post('sujeto/{type}/{subject}/encuesta-al-terminar', [OrganizerSurveyController::class, 'chooseEnd'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->middleware('throttle:10,1')->name('manage.end-choice');
        Route::get('sujeto/{type}/{subject}/nueva/{kind}', [OrganizerSurveyEditorController::class, 'create'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->where('kind', 'encuesta|sondeo|disponibilidad')->name('manage.create');
        Route::post('sujeto/{type}/{subject}/nueva/{kind}', [OrganizerSurveyEditorController::class, 'store'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->where('kind', 'encuesta|sondeo|disponibilidad')
            ->middleware('throttle:10,1')->name('manage.store');
        Route::post('sujeto/{type}/{subject}/copiar', [OrganizerSurveyCopyController::class, 'copy'])
            ->where('type', '[a-z][a-z0-9]*(-[a-z0-9]+)*')->middleware('throttle:10,1')->name('manage.copy');
        Route::post('{survey}/duplicar', [OrganizerSurveyCopyController::class, 'duplicate'])
            ->whereUuid('survey')->middleware('throttle:10,1')->name('manage.duplicate');
        Route::post('{survey}/guardar-como-plantilla', [OrganizerSurveyCopyController::class, 'saveTemplate'])
            ->whereUuid('survey')->middleware('throttle:10,1')->name('manage.save-template');
        Route::get('{survey}/editar', [OrganizerSurveyEditorController::class, 'edit'])->whereUuid('survey')->name('manage.edit');
        Route::put('{survey}/editar', [OrganizerSurveyEditorController::class, 'update'])->whereUuid('survey')->name('manage.update');
        Route::get('{survey}/resultados', [OrganizerSurveyResultsController::class, 'show'])->whereUuid('survey')->name('manage.results');
        Route::put('{survey}/resultados', [OrganizerSurveyResultsController::class, 'update'])->whereUuid('survey')->name('manage.results.update');
        Route::post('{survey}/enviar', [OrganizerSurveyController::class, 'send'])->whereUuid('survey')->name('manage.send');
        Route::post('{survey}/descartar', [OrganizerSurveyController::class, 'dismiss'])->whereUuid('survey')->name('manage.dismiss');
        Route::post('{survey}/cerrar', [OrganizerSurveyController::class, 'close'])->whereUuid('survey')->name('manage.close');
    });
