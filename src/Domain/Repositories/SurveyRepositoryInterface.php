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

    /**
     * The surveys with these ids that exist, in the order given.
     *
     * @param  list<SurveyId>  $ids
     * @return list<Survey>
     */
    public function findMany(array $ids): array;

    /**
     * The survey with its row locked until the surrounding transaction ends.
     */
    public function findForUpdate(SurveyId $id): Survey;

    /**
     * The subject's end survey ("<type>:<id>"), if it exists.
     */
    public function findBySubjectEnd(string $key): ?Survey;

    /**
     * The subject's end survey ("<type>:<id>"), with its row locked until the transaction ends.
     */
    public function findBySubjectEndForUpdate(string $key): ?Survey;

    /**
     * Whether the survey made when this subject ended ("<type>:<id>") already exists.
     */
    public function existsForSubjectEnd(string $key): bool;

    /**
     * Scheduled drafts whose sending time has come, earliest first.
     *
     * @return list<SurveyId>
     */
    public function dueForSending(DateTimeImmutable $now): array;

    public function pendingReviewCount(): int;

    public function delete(SurveyId $id): void;

    /**
     * Open surveys whose closing date has been reached.
     *
     * @return list<Survey>
     */
    public function findDueToClose(DateTimeImmutable $now): array;

    public function hasResponses(SurveyId $id): bool;

    /**
     * Open surveys with a reminder not sent yet whose reminder time has come.
     *
     * @return list<Survey>
     */
    public function findDueForReminder(DateTimeImmutable $now): array;

    /**
     * Marks the survey's reminder as sent; false when another run already did.
     */
    public function claimReminder(SurveyId $id, DateTimeImmutable $now): bool;

    /**
     * Every survey about one subject (a subject may have several).
     *
     * @return list<Survey>
     */
    public function findBySubject(string $sourceType, string $subjectId): array;
}
