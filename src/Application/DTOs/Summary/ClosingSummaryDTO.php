<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Summary;

/**
 * What a closing survey's summary mail says. While the results may not be seen (an anonymous
 * survey below the minimum) it carries no figure at all, not even how many answered.
 */
final readonly class ClosingSummaryDTO
{
    /**
     * @param  float|null  $rate  responses / audience, null without a fixed audience or while hidden
     * @param  list<SummaryQuestionDTO>  $questions
     * @param  int  $otherQuestions  questions on the results page the summary does not sum up
     * @param  ?float  $quizMean  a quiz's mean score, null when it is not a quiz or its scores are withheld
     */
    public function __construct(
        public string $surveyTitle,
        public ?string $subjectTitle,
        public bool $visible,
        public ?int $responses = null,
        public ?int $audienceSize = null,
        public ?float $rate = null,
        public array $questions = [],
        public int $otherQuestions = 0,
        public ?float $quizMean = null,
        public ?int $quizMaxPoints = null,
    ) {}
}
