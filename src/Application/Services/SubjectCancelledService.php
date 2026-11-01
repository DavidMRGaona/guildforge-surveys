<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\OrganizerNotifierInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SubjectEndChoiceRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;

/**
 * A subject that will never end drops its surveys that never opened (one prepared for its end
 * would wait forever) and tells their organizers. Open and closed surveys carry on, and the
 * survey chosen for its end is forgotten.
 */
final readonly class SubjectCancelledService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private OrganizerNotifierInterface $organizers,
        private SubjectEndChoiceRepositoryInterface $choices,
    ) {}

    public function handle(string $sourceType, string $subjectId, DateTimeImmutable $cancelledAt): void
    {
        $this->choices->forget($sourceType, $subjectId);

        foreach ($this->surveys->findBySubject($sourceType, $subjectId) as $candidate) {
            $withdrawn = DB::transaction(function () use ($candidate, $cancelledAt): ?Survey {
                $survey = $this->surveys->findForUpdate($candidate->id());
                if (! $this->isWithdrawable($survey)) {
                    return null;
                }

                $survey->withdrawWithSubject($cancelledAt);
                $this->surveys->save($survey);

                return $survey;
            });

            if ($withdrawn instanceof Survey) {
                $this->organizers->withdrawn($withdrawn);
            }
        }
    }

    private function isWithdrawable(Survey $survey): bool
    {
        return ! $survey->wasOpened()
            && ! $survey->dismissedAt() instanceof DateTimeImmutable
            && in_array($survey->status(), [SurveyStatus::Draft, SurveyStatus::PendingReview], true);
    }
}
