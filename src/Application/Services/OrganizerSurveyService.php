<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
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
