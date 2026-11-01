<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Repositories;

use Modules\Surveys\Domain\ValueObjects\SubjectEndChoice;

interface SubjectEndChoiceRepositoryInterface
{
    public function find(string $sourceType, string $subjectId): ?SubjectEndChoice;

    /**
     * Within a transaction: holds the subject's row, when there is one, until it commits.
     */
    public function findForUpdate(string $sourceType, string $subjectId): ?SubjectEndChoice;

    public function save(SubjectEndChoice $choice, ?string $chosenBy): void;

    public function forget(string $sourceType, string $subjectId): void;
}
