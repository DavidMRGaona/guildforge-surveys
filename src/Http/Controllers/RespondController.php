<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders resources/js/pages/Surveys/Respond.vue (module:make-vue-page surveys Respond).
 *
 * Route, in routes/web.php:
 *
 *     Route::get('responds', [RespondController::class, 'index'])->name('responds.index');
 */
final class RespondController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Surveys/Respond');
    }
}
