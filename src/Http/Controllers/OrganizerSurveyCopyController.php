<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Surveys\Application\Exceptions\SubjectCancelledException;
use Modules\Surveys\Application\Services\SurveyDuplicationService;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

/**
 * Asking the same again from the public site: a survey copied to its subject (its card's
 * «Duplicar») or to the subject whose page it is copied from, and kept as a template by whoever
 * manages the templates. Who manages the survey and the subject is asked on every request.
 */
final class OrganizerSurveyCopyController extends Controller
{
    public function __construct(
        private readonly SurveyDuplicationService $duplication,
        private readonly SurveyRepositoryInterface $surveys,
        private readonly SurveyManagerGate $gate,
    ) {}

    public function duplicate(Request $request, string $survey): RedirectResponse
    {
        return $this->copied(fn (): Survey => $this->duplication->duplicateForOrganizer(
            SurveyId::fromString($survey),
            (string) $request->user()?->getAuthIdentifier(),
        ));
    }

    /**
     * Copies one of the organizer's surveys to the subject whose page they are on.
     */
    public function copy(Request $request, string $type, string $subject): RedirectResponse
    {
        $data = $request->validate(['survey' => ['required', 'uuid']]);

        return $this->copied(fn (): Survey => $this->duplication->duplicateForOrganizer(
            SurveyId::fromString($data['survey']),
            (string) $request->user()?->getAuthIdentifier(),
            $type,
            $subject,
        ));
    }

    /**
     * Templates are the whole site's: only for whoever manages them, and a survey they manage.
     */
    public function saveTemplate(Request $request, string $survey): RedirectResponse
    {
        try {
            $found = $this->surveys->findOrFail(SurveyId::fromString($survey));
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $user = $request->user();
        abort_unless(
            $user?->can('create', SurveyTemplateModel::class) === true && $this->gate->canManage((string) $user->getAuthIdentifier(), $found),
            403,
        );

        $template = $this->duplication->saveAsTemplate($found->id(), $found->title(), $found->description(), (string) $user->getAuthIdentifier());

        return back()->with('success', __('surveys::messages.manage.saved_as_template', ['name' => $template->name()]));
    }

    /**
     * @param  callable(): Survey  $copy
     */
    private function copied(callable $copy): RedirectResponse
    {
        try {
            $survey = $copy();
        } catch (ModelNotFoundException|SubjectNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        } catch (SubjectCancelledException) {
            return back()->with('error', __('surveys::messages.manage.not_duplicable'));
        }

        return redirect()->route('surveys.manage.edit', $survey->id()->value)
            ->with('success', __('surveys::messages.manage.duplicated'));
    }
}
