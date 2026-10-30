<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Opens and closes surveys. Opening will also fix who the survey goes to.
 */
final readonly class SurveyLifecycleService
{
    public function __construct(private SurveyRepositoryInterface $surveys) {}

    /**
     * @throws InvalidSurveyStateException
     */
    public function open(SurveyId $id, ?DateTimeImmutable $now = null): void
    {
        $survey = $this->surveys->findOrFail($id);
        $survey->open($now ?? now()->toDateTimeImmutable());
        $this->surveys->save($survey);
    }

    /**
     * @throws InvalidSurveyStateException
     */
    public function close(SurveyId $id, ?DateTimeImmutable $now = null): void
    {
        $survey = $this->surveys->findOrFail($id);
        $survey->close($now ?? now()->toDateTimeImmutable());
        $this->surveys->save($survey);
    }
}
