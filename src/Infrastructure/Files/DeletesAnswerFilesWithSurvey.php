<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Files;

use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\AnswerFileStoreInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;

/**
 * Deleting a survey (from the panel, the site or a service) takes its answer files with it once
 * the deletion is committed: a deletion that rolls back keeps them.
 */
final readonly class DeletesAnswerFilesWithSurvey
{
    public function __construct(
        private AnswerFileStoreInterface $files,
    ) {}

    public function deleted(SurveyModel $survey): void
    {
        $id = SurveyId::fromString((string) $survey->getKey());
        DB::afterCommit(fn () => $this->files->deleteForSurvey($id));
    }
}
