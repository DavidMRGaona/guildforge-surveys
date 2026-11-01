<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\ChosenDateNotifierInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Fixes the date of a closed availability survey and tells its people once it is stored. A
 * notice that fails is reported without undoing the date. It does not ask who is fixing it:
 * callers check.
 */
final readonly class AvailabilityDateService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private ChosenDateNotifierInterface $notifier,
    ) {}

    /**
     * @throws InvalidSurveyStateException when the survey cannot fix that date
     */
    public function choose(SurveyId $id, string $slotId, ?DateTimeImmutable $now = null): void
    {
        $survey = DB::transaction(function () use ($id, $slotId, $now): Survey {
            $survey = $this->surveys->findForUpdate($id);
            $survey->chooseSlot($slotId, $now ?? now()->toDateTimeImmutable());
            $this->surveys->save($survey);

            return $survey;
        });

        DB::afterCommit(function () use ($survey): void {
            try {
                $this->notifier->dateChosen($survey);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
