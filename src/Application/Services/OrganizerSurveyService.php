<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Enums\UpdateOutcome;
use Modules\Surveys\Application\Exceptions\OrganizerChangeRejectedException;
use Modules\Surveys\Application\Exceptions\SurveyPreparationUnavailableException;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * What an organizer does with their subject's survey on the public site. Every action checks,
 * live, that they still manage the subject, and runs with the survey's row locked so two clicks
 * (or a click and the scheduled sending) never send it twice.
 */
final readonly class OrganizerSurveyService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyManagerGate $gate,
        private SurveyLifecycleService $lifecycle,
        private SurveyReviewRule $review,
        private ReviewNotifierInterface $reviewNotifier,
        private SurveySubjectSourceRegistryInterface $sources,
        private SurveySettingsInterface $settings,
        private SurveyTemplateRepositoryInterface $templates,
    ) {}

    /**
     * The subject's end survey, prepared ahead from its type's template: it waits for the end and
     * then goes out by itself or is offered, as the type says until the organizer changes it. On
     * a subject that already ended it is ready to send. Preparing again gives the same survey,
     * taken back if it had been dismissed without opening.
     *
     * @throws AuthorizationException
     * @throws SubjectNotFoundException
     * @throws SurveyPreparationUnavailableException
     */
    public function prepare(string $sourceType, string $subjectId, string $userId, ?DateTimeImmutable $now = null): Survey
    {
        $now ??= now()->toDateTimeImmutable();
        $source = $this->sources->source($sourceType);
        $subject = $source?->find($subjectId);
        if (! $subject instanceof SurveySubjectDTO) {
            throw SubjectNotFoundException::for($sourceType, $subjectId);
        }

        if (! $source->canManage($userId, $subjectId)) {
            throw new AuthorizationException;
        }

        if ($subject->status === SurveySubjectStatus::Cancelled) {
            throw new SurveyPreparationUnavailableException('cancelled');
        }

        $endKey = "{$sourceType}:{$subjectId}";
        $existing = $this->takeBack($endKey);
        if ($existing instanceof Survey) {
            return $existing;
        }

        $type = $this->settings->forSubjectType($sourceType);
        $template = $type->templateId === null ? null : $this->templates->find(SurveyTemplateId::fromString($type->templateId));
        if (! $template instanceof SurveyTemplate) {
            throw new SurveyPreparationUnavailableException('no_template');
        }

        try {
            return DB::transaction(function () use ($sourceType, $subjectId, $template, $type, $endKey, $userId, $subject, $now): Survey {
                $survey = $this->lifecycle->createFromTemplateForSubject(
                    $sourceType,
                    $subjectId,
                    $template,
                    $type->anonymous,
                    $type->closeDays,
                    $endKey,
                    createdBy: $userId,
                    subjectEndedAt: $subject->status === SurveySubjectStatus::Ended ? $now : null,
                );

                if ($survey->isAwaitingSubjectEnd()) {
                    $survey->chooseEndSending($type->autoSend, $type->sendDelayHours);
                    $this->surveys->save($survey);
                }

                return $survey;
            });
        } catch (UniqueConstraintViolationException) {
            // Prepared at the same time by another click, or made by the end meanwhile
            return $this->takeBack($endKey) ?? throw SubjectNotFoundException::for($sourceType, $subjectId);
        }
    }

    /**
     * Whether the subject's page offers to prepare its end survey: its type has a template, the
     * subject was not cancelled and it has no end survey still in play (a dismissed one that
     * never opened is taken back by preparing).
     */
    public function preparationAvailable(SurveySubjectDTO $subject): bool
    {
        if ($subject->status === SurveySubjectStatus::Cancelled) {
            return false;
        }

        $type = $this->settings->forSubjectType($subject->sourceType);
        if ($type->templateId === null || ! $this->templates->find(SurveyTemplateId::fromString($type->templateId)) instanceof SurveyTemplate) {
            return false;
        }

        $existing = $this->surveys->findBySubjectEnd("{$subject->sourceType}:{$subject->subjectId}");

        return ! $existing instanceof Survey
            || ($existing->dismissedAt() instanceof DateTimeImmutable && ! $existing->wasOpened());
    }

    /**
     * The subject's end survey if it exists, taken back when dismissed without ever opening.
     */
    private function takeBack(string $endKey): ?Survey
    {
        return DB::transaction(function () use ($endKey): ?Survey {
            $survey = $this->surveys->findBySubjectEndForUpdate($endKey);
            if ($survey instanceof Survey && $survey->dismissedAt() instanceof DateTimeImmutable && ! $survey->wasOpened()) {
                $survey->reconsider();
                $this->surveys->save($survey);
            }

            return $survey;
        });
    }

    /**
     * Sends the survey: it opens, or waits for a moderator when it carries unreviewed changes
     * and the review setting is on.
     *
     * @throws AuthorizationException
     * @throws InvalidSurveyStateException
     */
    public function send(SurveyId $id, string $userId, ?DateTimeImmutable $now = null): SendOutcome
    {
        $now ??= now()->toDateTimeImmutable();

        [$outcome, $survey] = DB::transaction(function () use ($id, $userId, $now): array {
            $survey = $this->surveys->findForUpdate($id);
            $this->authorize($userId, $survey);

            return [$this->sendLocked($survey, $userId, $now), $survey];
        });

        if ($outcome === SendOutcome::SentForReview) {
            $this->reviewNotifier->submittedForReview($survey);
        }

        return $outcome;
    }

    /**
     * Sends a scheduled survey whose time has come, on nobody's behalf: changed content goes to
     * review in the name of whoever changed it. Null when there was nothing to send any more
     * (sent, dismissed or moved meanwhile).
     *
     * @throws InvalidSurveyStateException
     */
    public function sendScheduled(SurveyId $id, ?DateTimeImmutable $now = null): ?SendOutcome
    {
        $now ??= now()->toDateTimeImmutable();

        [$outcome, $survey] = DB::transaction(function () use ($id, $now): array {
            $survey = $this->surveys->findForUpdate($id);
            if (! $survey->isDueForSending($now)) {
                return [null, $survey];
            }

            return [$this->sendLocked($survey, $survey->lastChangedBy() ?? $survey->createdBy() ?? '', $now), $survey];
        });

        if ($outcome === SendOutcome::SentForReview) {
            $this->reviewNotifier->submittedForReview($survey);
        }

        return $outcome;
    }

    /**
     * The organizer chose not to send a survey offered to them; the panel keeps it.
     *
     * @throws AuthorizationException
     * @throws InvalidSurveyStateException
     */
    public function dismiss(SurveyId $id, string $userId, ?DateTimeImmutable $now = null): void
    {
        DB::transaction(function () use ($id, $userId, $now): void {
            $survey = $this->surveys->findForUpdate($id);
            $this->authorize($userId, $survey);

            $survey->dismiss($now ?? now()->toDateTimeImmutable());
            $this->surveys->save($survey);
        });
    }

    /**
     * How the results show: a chart per question and the questions left out. Not content, so
     * never reviewed. A chart the question cannot draw and an unknown question are dropped; a
     * question without a chart in the request keeps the one it had.
     *
     * @param  array<mixed>  $chartTypes  question id => chart type
     * @param  array<mixed>  $hiddenQuestionIds
     *
     * @throws AuthorizationException
     */
    public function configureResults(SurveyId $id, string $userId, array $chartTypes, array $hiddenQuestionIds): void
    {
        DB::transaction(function () use ($id, $userId, $chartTypes, $hiddenQuestionIds): void {
            $survey = $this->surveys->findForUpdate($id);
            $this->authorize($userId, $survey);

            $current = $survey->results();
            $charts = [];
            $hidden = [];
            foreach ($survey->definition()->questions() as $question) {
                $chart = ChartType::tryFrom(is_string($chartTypes[$question->id] ?? null) ? $chartTypes[$question->id] : '');
                if ($chart instanceof ChartType && in_array($chart, $question->type->chartTypes(), true)) {
                    $charts[$question->id] = $chart->value;
                } elseif (isset($current->chartTypes[$question->id])) {
                    $charts[$question->id] = $current->chartTypes[$question->id];
                }
                if (in_array($question->id, $hiddenQuestionIds, true)) {
                    $hidden[] = $question->id;
                }
            }

            $survey->changeResults(new ResultsSettings($current->liveResults, $current->visibleToRespondents, $charts, $hidden));
            $this->surveys->save($survey);
        });
    }

    /**
     * Saves the editor's changes. Changed content is marked as unreviewed in the organizer's name;
     * an open survey changed while the review setting is on goes back to review and stops taking
     * responses; while it waits for a moderator its content stays as sent. Dates, anonymity and
     * results settings are not content. Once someone answered,
     * only help texts and the closing date may change: the check runs with the row locked, and
     * submissions lock it too, so no response slips in between.
     *
     * @throws AuthorizationException
     * @throws InvalidSurveyDefinitionException
     * @throws OrganizerChangeRejectedException
     */
    public function update(SurveyId $id, string $userId, OrganizerSurveyInput $input, ?DateTimeImmutable $now = null): UpdateOutcome
    {
        $now ??= now()->toDateTimeImmutable();

        [$outcome, $survey] = DB::transaction(function () use ($id, $userId, $input, $now): array {
            $survey = $this->surveys->findForUpdate($id);
            $this->authorize($userId, $survey);

            if ($survey->status() === SurveyStatus::Closed || $survey->dismissedAt() instanceof DateTimeImmutable) {
                throw new OrganizerChangeRejectedException('survey', 'closed');
            }

            $hasResponses = $this->surveys->hasResponses($id);
            $contentChanged = $survey->contentDiffers($input->title, $input->description, $input->definition);

            // A moderator approves the content they saw: it never changes while it waits for them
            if ($contentChanged && $survey->status() === SurveyStatus::PendingReview) {
                throw new OrganizerChangeRejectedException('survey', 'pending_review');
            }

            $survey->replaceDefinition($input->definition, $hasResponses, new SurveyDefinitionValidator);
            $this->onField('title', 'locked_by_responses', fn () => $survey->editDetails($input->title, $input->description, $hasResponses));
            $this->onField('anonymous', 'anonymity', fn () => $survey->changeAnonymity($input->anonymous, $hasResponses));
            if ($survey->wasOpened()) {
                $this->onField('closes_at', 'closing_date', fn () => $survey->reschedule($input->closesAt, $input->reminderHoursBefore, $now));
            } else {
                $this->onField('duration_days', 'closing_date', fn () => $survey->changeDuration($input->durationDays));
                $this->onField('reminder_hours_before', 'closing_date', fn () => $survey->reschedule($survey->closesAt(), $input->reminderHoursBefore, $now));
            }
            if ($input->sendOnEnd !== null && $survey->isAwaitingSubjectEnd()) {
                $this->onField('send_delay_hours', 'send_delay', fn () => $survey->chooseEndSending($input->sendOnEnd, $input->sendDelayHours ?? 0));
            }
            $survey->changeResults(new ResultsSettings(
                liveResults: $input->liveResults,
                visibleToRespondents: $input->visibleToRespondents,
                chartTypes: $survey->results()->chartTypes,
                hiddenQuestionIds: $survey->results()->hiddenQuestionIds,
            ));

            $outcome = UpdateOutcome::Saved;
            if ($contentChanged) {
                $survey->markUnreviewedChanges($userId);

                if ($survey->status() === SurveyStatus::Open && $this->review->needsReview($survey)) {
                    $survey->submitForReview($userId, $now);
                    $outcome = UpdateOutcome::SentForReview;
                }
            }

            $this->surveys->save($survey);

            return [$outcome, $survey];
        });

        if ($outcome === UpdateOutcome::SentForReview) {
            $this->reviewNotifier->submittedForReview($survey);
        }

        return $outcome;
    }

    /**
     * @param  callable(): void  $change
     *
     * @throws OrganizerChangeRejectedException
     */
    private function onField(string $field, string $messageKey, callable $change): void
    {
        try {
            $change();
        } catch (InvalidSurveyStateException) {
            throw new OrganizerChangeRejectedException($field, $messageKey);
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(string $userId, Survey $survey): void
    {
        if (! $this->gate->canManage($userId, $survey)) {
            throw new AuthorizationException;
        }
    }

    private function sendLocked(Survey $survey, string $submittedBy, DateTimeImmutable $now): SendOutcome
    {
        // Prepared for its subject's end: it goes out then, as chosen
        if ($survey->isAwaitingSubjectEnd()) {
            throw InvalidSurveyStateException::awaitingSubjectEnd($survey->id()->value);
        }

        if ($this->review->needsReview($survey)) {
            $survey->submitForReview($submittedBy, $now);
            $this->surveys->save($survey);

            return SendOutcome::SentForReview;
        }

        $this->lifecycle->open($survey->id(), $now);

        return SendOutcome::Opened;
    }
}
