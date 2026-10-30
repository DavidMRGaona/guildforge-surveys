<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Repositories;

use DateTimeImmutable;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

interface SurveyRepositoryInterface
{
    public function save(Survey $survey): void;

    public function find(SurveyId $id): ?Survey;

    public function findOrFail(SurveyId $id): Survey;

    public function delete(SurveyId $id): void;

    /**
     * Open surveys whose closing date has been reached.
     *
     * @return list<Survey>
     */
    public function findDueToClose(DateTimeImmutable $now): array;

    public function hasResponses(SurveyId $id): bool;

    /**
     * Every survey about one subject (a subject may have several).
     *
     * @return list<Survey>
     */
    public function findBySubject(string $sourceType, string $subjectId): array;
}
