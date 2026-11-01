<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
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
use Modules\Surveys\Application\Exceptions\SubjectCancelledException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Http\Requests\UpdateOrganizerSurveyRequest;

/**
 * The organizer's editor of a subject's survey on the public site: the same scope as the
 * panel's, checked by the same domain rules. It also writes a new survey or poll of their own
 * ("encuesta" or "sondeo" in the address), saved only when they first save it. Errors come back
 * already translated, each on the field it is about (definition errors under "definition.<path>").
 */
final class OrganizerSurveyEditorController extends Controller
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveys,
        private readonly SurveyManagerGate $gate,
        private readonly OrganizerSurveyService $service,
        private readonly TimezoneServiceInterface $timezone,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly SurveySubjectSourceRegistryInterface $sources,
    ) {}

    public function create(Request $request, string $type, string $subject, string $kind): Response|RedirectResponse
    {
        $source = $this->sources->source($type);
        $found = $source?->find($subject);
        abort_unless($found instanceof SurveySubjectDTO, 404);
        abort_unless($source->canManage((string) $request->user()?->getAuthIdentifier(), $subject), 403);

        if ($found->status === SurveySubjectStatus::Cancelled) {
            return redirect()->route('surveys.subject', [$type, $subject])
                ->with('error', __('surveys::messages.manage.create_unavailable'));
        }

        $surveyKind = $this->kind($kind);
        $defaults = $this->service->defaultsFor($type, $surveyKind);
        $heading = (string) __("surveys::messages.manage.new_{$surveyKind->value}");

        return Inertia::render('Surveys/Manage/Edit', [
            'survey' => [
                'id' => null,
                'kind' => $surveyKind->value,
                'title' => '',
                'description' => null,
                'anonymous' => $defaults['anonymous'],
                'durationDays' => $defaults['durationDays'],
                'closesAt' => null,
                'reminderHoursBefore' => null,
                'liveResults' => $defaults['liveResults'],
                'visibleToRespondents' => $defaults['visibleToRespondents'],
                'closingSummary' => $defaults['closingSummary'],
                'opened' => false,
                'pendingReview' => false,
                'rejectionReason' => null,
                'sendable' => true,
            ],
            'endSending' => null,
            'definition' => $this->service->startingDefinition($surveyKind)->toArray(),
            'subject' => ['title' => $found->title],
            'locked' => false,
            'submitUrl' => route('surveys.manage.store', [$type, $subject, $kind]),
            'submitMethod' => 'post',
            'backUrl' => route('surveys.subject', [$type, $subject]),
            'seo' => $this->seo->forPage(new SeoPageDTO(title: $heading, noindex: true))->toArray(),
        ]);
    }

    public function store(UpdateOrganizerSurveyRequest $request, string $type, string $subject, string $kind): RedirectResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();

        try {
            $survey = $this->service->create($type, $subject, $userId, $this->kind($kind), $this->input($request));
            $sent = $request->boolean('send') ? $this->service->send($survey->id(), $userId) : null;
        } catch (SubjectNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        } catch (SubjectCancelledException) {
            return back()->with('error', __('surveys::messages.manage.create_unavailable'));
        } catch (InvalidSurveyDefinitionException $exception) {
            throw $this->definitionErrors($exception);
        }

        if ($sent instanceof SendOutcome) {
            return redirect()->route('surveys.subject', [$type, $subject])
                ->with('success', __($sent === SendOutcome::Opened ? 'surveys::messages.manage.sent' : 'surveys::messages.manage.sent_for_review'));
        }

        return redirect()->route('surveys.manage.edit', $survey->id()->value)
            ->with('success', __('surveys::messages.manage.created'));
    }

    public function edit(Request $request, string $survey): Response|RedirectResponse
    {
        $found = $this->surveys->find(SurveyId::fromString($survey));
        abort_unless($found instanceof Survey && $found->subject() instanceof SubjectSnapshot, 404);
        abort_unless($this->gate->canManage((string) $request->user()?->getAuthIdentifier(), $found), 403);

        $subject = $found->subject();
        if ($found->status() === SurveyStatus::Closed || $found->dismissedAt() instanceof \DateTimeImmutable) {
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
                'closesAt' => $found->closesAt() instanceof \DateTimeImmutable ? $this->timezone->toDisplayWallTime($found->closesAt()) : null,
                'reminderHoursBefore' => $found->reminderHoursBefore(),
                'liveResults' => $found->results()->liveResults,
                'visibleToRespondents' => $found->results()->visibleToRespondents,
                'closingSummary' => $found->sendsClosingSummary(),
                'opened' => $found->wasOpened(),
                'pendingReview' => $found->status() === SurveyStatus::PendingReview,
                'rejectionReason' => $found->rejectionReason(),
                // Prepared for its subject's end: it goes out then, as chosen below
                'sendable' => $found->status() === SurveyStatus::Draft && ! $found->isAwaitingSubjectEnd(),
            ],
            'endSending' => $found->isAwaitingSubjectEnd() ? [
                'sendOnEnd' => $found->sendsOnEnd(),
                'sendDelayHours' => $found->sendDelayHours(),
            ] : null,
            'definition' => $found->definition()->toArray(),
            'subject' => ['title' => $subject->title],
            'locked' => $hasResponses,
            'submitUrl' => route('surveys.manage.update', $found->id()->value),
            'submitMethod' => 'put',
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
            throw $this->definitionErrors($exception);
        } catch (OrganizerChangeRejectedException $exception) {
            throw ValidationException::withMessages([$exception->field => __("surveys::messages.state_errors.{$exception->messageKey}")]);
        } catch (InvalidSurveyStateException) {
            return back()->with('error', __('surveys::messages.manage.not_sendable'));
        }

        if ($sent instanceof SendOutcome) {
            return redirect()->route('surveys.subject', [$subject->sourceType, $subject->subjectId])
                ->with('success', __($sent === SendOutcome::Opened ? 'surveys::messages.manage.sent' : 'surveys::messages.manage.sent_for_review'));
        }

        return back()->with('success', __($outcome === UpdateOutcome::SentForReview ? 'surveys::messages.manage.saved_and_sent_for_review' : 'surveys::messages.manage.saved'));
    }

    private function kind(string $kind): SurveyKind
    {
        return $kind === 'sondeo' ? SurveyKind::Poll : SurveyKind::Form;
    }

    private function definitionErrors(InvalidSurveyDefinitionException $exception): ValidationException
    {
        $errors = [];
        foreach ($exception->violations as $violation) {
            $errors["definition.{$violation->path}"] = __("surveys::messages.definition_errors.{$violation->messageKey}");
        }

        return ValidationException::withMessages($errors);
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
            sendOnEnd: $request->has('send_on_end') ? $request->boolean('send_on_end') : null,
            sendDelayHours: $request->filled('send_delay_hours') ? $request->integer('send_delay_hours') : null,
            closingSummary: $request->has('closing_summary') ? $request->boolean('closing_summary') : null,
        );
    }
}
