<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Whether a file sent as an answer may be seen now, by the results' own rules: an anonymous
 * survey shows nothing before its results do, and nothing of a question too few people answered.
 * Who may see results at all is the caller's to check.
 */
final readonly class AnswerFileAccess
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyResultsQueryInterface $query,
        private ResultsService $results,
        private SurveySettingsInterface $settings,
    ) {}

    /**
     * @return array{StoredAnswerFile, Survey}|null
     */
    public function visible(string $fileId, ?DateTimeImmutable $now = null): ?array
    {
        $file = $this->query->answerFile($fileId);
        $survey = $file instanceof StoredAnswerFile ? $this->surveys->find(SurveyId::fromString($file->surveyId)) : null;
        if (! $file instanceof StoredAnswerFile || ! $survey instanceof Survey || $survey->definition()->question($file->questionId)?->type !== QuestionType::File) {
            return null;
        }

        if ($this->results->panelHiddenReason($survey, $this->query->verifiedResponseCount($survey->id()), $now ?? now()->toDateTimeImmutable()) !== null) {
            return null;
        }

        if ($survey->isAnonymous() && ($this->query->verifiedAnsweredCounts($survey->id(), [$file->questionId])[$file->questionId] ?? 0) < $this->settings->minResponses()) {
            return null;
        }

        return [$file, $survey];
    }
}
