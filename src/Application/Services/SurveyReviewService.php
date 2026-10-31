<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * A moderator's decision on a survey changed outside the panel (who may decide is the panel's
 * policy to say). Approving sends it, its duration counted from now; rejecting returns it to
 * the organizer with the reason. Either one tells whoever sent it.
 */
final readonly class SurveyReviewService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyLifecycleService $lifecycle,
        private ReviewNotifierInterface $notifier,
    ) {}

    /**
     * @throws InvalidSurveyStateException when it is no longer pending (another moderator decided)
     */
    public function approve(SurveyId $id, string $moderatorId, ?DateTimeImmutable $now = null): void
    {
        $now ??= now()->toDateTimeImmutable();

        $survey = DB::transaction(function () use ($id, $moderatorId, $now): Survey {
            $survey = $this->surveys->findForUpdate($id);
            $survey->approve($moderatorId, $now);
            $this->surveys->save($survey);

            // Never sent before: it goes out now, to the audience fixed at this moment
            if (! $survey->wasOpened()) {
                $this->lifecycle->open($id, $now);
            }

            return $survey;
        });

        $this->notifier->approved($survey);
    }

    /**
     * @throws InvalidSurveyStateException when it is no longer pending or the reason is empty
     */
    public function reject(SurveyId $id, string $moderatorId, string $reason, ?DateTimeImmutable $now = null): void
    {
        $survey = DB::transaction(function () use ($id, $moderatorId, $reason, $now): Survey {
            $survey = $this->surveys->findForUpdate($id);
            $survey->reject($moderatorId, $reason, $now ?? now()->toDateTimeImmutable());
            $this->surveys->save($survey);

            return $survey;
        });

        $this->notifier->rejected($survey);
    }
}
