<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Enums\UpdateOutcome;
use Modules\Surveys\Application\Exceptions\OrganizerChangeRejectedException;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

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
    ) {}

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
        if ($this->review->needsReview($survey)) {
            $survey->submitForReview($submittedBy, $now);
            $this->surveys->save($survey);

            return SendOutcome::SentForReview;
        }

        $this->lifecycle->open($survey->id(), $now);

        return SendOutcome::Opened;
    }
}
