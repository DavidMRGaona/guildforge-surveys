<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\Services\AvailabilityDateService;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Http\Support\ResultsPayload;

/**
 * A subject survey's results for its organizer on the public site, under the same rule as the
 * panel (an anonymous survey waits for its close and its minimum), and how they show.
 */
final class OrganizerSurveyResultsController extends Controller
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveys,
        private readonly SurveyManagerGate $gate,
        private readonly ResultsService $results,
        private readonly OrganizerSurveyService $service,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly AvailabilityDateService $dates,
    ) {}

    public function show(Request $request, string $survey): Response
    {
        $found = $this->surveys->find(SurveyId::fromString($survey));
        abort_unless($found instanceof Survey && $found->subject() instanceof SubjectSnapshot && $found->wasOpened(), 404);
        abort_unless($this->gate->canManage((string) $request->user()?->getAuthIdentifier(), $found), 403);

        $subject = $found->subject();

        return Inertia::render('Surveys/Manage/Results', [
            'survey' => [
                'id' => $found->id()->value,
                'title' => $found->title(),
                'anonymous' => $found->isAnonymous(),
                'closed' => $found->status() === SurveyStatus::Closed,
            ],
            'subject' => ['title' => $subject->title],
            'results' => ResultsPayload::forOrganizer($this->results->forOrganizer($found->id())),
            'hiddenQuestions' => array_values(array_map(
                static fn ($question): array => ['id' => $question->id, 'title' => $question->title],
                array_filter($found->definition()->questions(), static fn ($question): bool => $found->results()->isHidden($question->id)),
            )),
            'settingsUrl' => route('surveys.manage.results.update', $found->id()->value),
            // Once an availability survey closes, its organizer fixes the date
            'chooseDateUrl' => $found->awaitsChosenSlot() ? route('surveys.manage.choose-date', $found->id()->value) : null,
            'backUrl' => route('surveys.subject', [$subject->sourceType, $subject->subjectId]),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('surveys::messages.manage.results_title', ['title' => $found->title()]),
                noindex: true,
            ))->toArray(),
        ]);
    }

    /**
     * Fixes the date of the closed availability survey; everyone is told, so it must be confirmed.
     */
    public function chooseDate(Request $request, string $survey): RedirectResponse
    {
        $found = $this->surveys->find(SurveyId::fromString($survey));
        abort_unless($found instanceof Survey && $found->subject() instanceof SubjectSnapshot, 404);
        abort_unless($this->gate->canManage((string) $request->user()?->getAuthIdentifier(), $found), 403);

        $request->validate(
            ['slot_id' => ['required', 'string'], 'confirmed' => ['accepted']],
            ['confirmed.accepted' => __('surveys::messages.manage.date_confirm_required'), 'slot_id.required' => __('surveys::messages.manage.date_slot_required')],
        );

        try {
            $this->dates->choose($found->id(), (string) $request->string('slot_id'));
        } catch (InvalidSurveyStateException) {
            return back()->with('error', __('surveys::messages.manage.date_not_chosen'));
        }

        return back()->with('success', __('surveys::messages.manage.date_chosen'));
    }

    public function update(Request $request, string $survey): RedirectResponse
    {
        $chartTypes = $request->input('chart_types', []);
        $hidden = $request->input('hidden_question_ids', []);

        try {
            $this->service->configureResults(
                SurveyId::fromString($survey),
                (string) $request->user()?->getAuthIdentifier(),
                is_array($chartTypes) ? $chartTypes : [],
                is_array($hidden) ? array_values($hidden) : [],
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        return back()->with('success', __('surveys::messages.manage.results_saved'));
    }
}
