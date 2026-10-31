<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Enums\UpdateOutcome;
use Modules\Surveys\Application\Exceptions\OrganizerChangeRejectedException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Http\Requests\UpdateOrganizerSurveyRequest;

/**
 * The organizer's editor of a subject's survey on the public site: the same scope as the
 * panel's, checked by the same domain rules. Errors come back already translated, each on
 * the field it is about (definition errors under "definition.<path>").
 */
final class OrganizerSurveyEditorController extends Controller
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveys,
        private readonly SurveyManagerGate $gate,
        private readonly OrganizerSurveyService $service,
        private readonly TimezoneServiceInterface $timezone,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function edit(Request $request, string $survey): Response|RedirectResponse
    {
        $found = $this->surveys->find(SurveyId::fromString($survey));
        abort_unless($found instanceof Survey && $found->subject() instanceof SubjectSnapshot, 404);
        abort_unless($this->gate->canManage((string) $request->user()?->getAuthIdentifier(), $found), 403);

        $subject = $found->subject();
        if ($found->status() === SurveyStatus::Closed || $found->dismissedAt() !== null) {
            return redirect()->route('surveys.subject', [$subject->sourceType, $subject->subjectId])
                ->with('error', __('surveys::messages.manage.not_editable'));
        }

        $hasResponses = $this->surveys->hasResponses($found->id());

        return Inertia::render('Surveys/Manage/Edit', [
            'survey' => [
                'id' => $found->id()->value,
                'kind' => $found->kind()->value,
                'title' => $found->title(),
                'description' => $found->description(),
                'anonymous' => $found->isAnonymous(),
                'durationDays' => $found->durationDays(),
                'closesAt' => $found->closesAt() === null ? null : $this->timezone->toDisplayWallTime($found->closesAt()),
                'reminderHoursBefore' => $found->reminderHoursBefore(),
                'liveResults' => $found->results()->liveResults,
                'visibleToRespondents' => $found->results()->visibleToRespondents,
                'opened' => $found->wasOpened(),
                'pendingReview' => $found->status() === SurveyStatus::PendingReview,
                'rejectionReason' => $found->rejectionReason(),
                'sendable' => $found->status() === SurveyStatus::Draft,
            ],
            'definition' => $found->definition()->toArray(),
            'subject' => ['title' => $subject->title],
            'locked' => $hasResponses,
            'updateUrl' => route('surveys.manage.update', $found->id()->value),
            'backUrl' => route('surveys.subject', [$subject->sourceType, $subject->subjectId]),
            'seo' => $this->seo->forPage(new SeoPageDTO(title: $found->title(), noindex: true))->toArray(),
        ]);
    }

    public function update(UpdateOrganizerSurveyRequest $request, string $survey): RedirectResponse
    {
        $id = SurveyId::fromString($survey);
        $found = $this->surveys->find($id);
        abort_unless($found instanceof Survey && $found->subject() instanceof SubjectSnapshot, 404);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $subject = $found->subject();

        try {
            $outcome = $this->service->update($id, $userId, $this->input($request));
            $sent = $request->boolean('send') && $outcome === UpdateOutcome::Saved ? $this->service->send($id, $userId) : null;
        } catch (AuthorizationException) {
            abort(403);
        } catch (InvalidSurveyDefinitionException $exception) {
            $errors = [];
            foreach ($exception->violations as $violation) {
                $errors["definition.{$violation->path}"] = __("surveys::messages.definition_errors.{$violation->messageKey}");
            }

            throw ValidationException::withMessages($errors);
        } catch (OrganizerChangeRejectedException $exception) {
            throw ValidationException::withMessages([$exception->field => __("surveys::messages.state_errors.{$exception->messageKey}")]);
        } catch (InvalidSurveyStateException) {
            return back()->with('error', __('surveys::messages.manage.not_sendable'));
        }

        if ($sent !== null) {
            return redirect()->route('surveys.subject', [$subject->sourceType, $subject->subjectId])
                ->with('success', __($sent === SendOutcome::Opened ? 'surveys::messages.manage.sent' : 'surveys::messages.manage.sent_for_review'));
        }

        return back()->with('success', __($outcome === UpdateOutcome::SentForReview ? 'surveys::messages.manage.saved_and_sent_for_review' : 'surveys::messages.manage.saved'));
    }

    /**
     * @throws InvalidSurveyDefinitionException when the definition does not have its shape
     */
    private function input(UpdateOrganizerSurveyRequest $request): OrganizerSurveyInput
    {
        /** @var array<mixed> $definition */
        $definition = $request->input('definition');
        $description = $request->input('description');
        $closesAt = $request->input('closes_at');

        return new OrganizerSurveyInput(
            title: (string) $request->string('title'),
            description: is_string($description) && trim($description) !== '' ? $description : null,
            definition: SurveyDefinition::fromArray($definition),
            anonymous: $request->boolean('anonymous'),
            durationDays: $request->filled('duration_days') ? $request->integer('duration_days') : null,
            closesAt: is_string($closesAt) && $closesAt !== '' ? $this->timezone->fromDisplayWallTime($closesAt) : null,
            reminderHoursBefore: $request->filled('reminder_hours_before') ? $request->integer('reminder_hours_before') : null,
            liveResults: $request->boolean('live_results'),
            visibleToRespondents: $request->boolean('visible_to_respondents'),
        );
    }
}
