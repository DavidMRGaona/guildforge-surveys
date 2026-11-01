<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\ResultBucketDTO;
use Modules\Surveys\Application\DTOs\Summary\ClosingSummaryDTO;
use Modules\Surveys\Application\DTOs\Summary\SummaryQuestionDTO;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * The summary mailed when a survey closes, read from what its organizers see (the minimum
 * applied, hidden questions left out). Only choices, scales and star ratings are summed up;
 * anything else, a kind of question this summary does not know included, is left to the
 * results page. Free text never enters it.
 */
final readonly class ClosingSummaryService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private ResultsService $results,
    ) {}

    public function for(SurveyId $id, ?DateTimeImmutable $now = null): ClosingSummaryDTO
    {
        $survey = $this->surveys->findOrFail($id);
        $results = $this->results->forOrganizer($id, $now);
        $subjectTitle = $survey->subject()?->title;

        if (! $results->visible) {
            return new ClosingSummaryDTO($survey->title(), $subjectTitle, visible: false);
        }

        $questions = [];
        $others = 0;
        foreach ($results->questions as $question) {
            $summary = $this->question($question);
            if ($summary instanceof SummaryQuestionDTO) {
                $questions[] = $summary;
            } else {
                $others++;
            }
        }

        return new ClosingSummaryDTO(
            surveyTitle: $survey->title(),
            subjectTitle: $subjectTitle,
            visible: true,
            responses: $results->responses,
            audienceSize: $results->audienceSize,
            rate: $results->rate,
            questions: $questions,
            otherQuestions: $others,
        );
    }

    private function question(QuestionResultDTO $question): ?SummaryQuestionDTO
    {
        $scale = $question->type === QuestionType::LinearScale || $question->type === QuestionType::StarRating;
        if (! $question->type->hasOptions() && ! $scale) {
            return null;
        }

        if ($question->belowMinimum) {
            return new SummaryQuestionDTO($question->title, belowMinimum: true);
        }

        if ($scale) {
            $values = array_map(static fn (ResultBucketDTO $bucket): int => (int) $bucket->key, $question->buckets);

            return new SummaryQuestionDTO(
                title: $question->title,
                answered: $question->answered,
                mean: $question->mean,
                scaleMin: $values === [] ? null : min($values),
                scaleMax: $values === [] ? null : max($values),
            );
        }

        $top = max([0, ...array_map(static fn (ResultBucketDTO $bucket): int => $bucket->count, $question->buckets)]);
        $winners = $top === 0 ? [] : array_values(array_filter($question->buckets, static fn (ResultBucketDTO $bucket): bool => $bucket->count === $top));

        return new SummaryQuestionDTO(
            title: $question->title,
            answered: $question->answered,
            topOptions: array_map(static fn (ResultBucketDTO $bucket): ?string => $bucket->label, $winners),
            topCount: $top,
        );
    }
}
