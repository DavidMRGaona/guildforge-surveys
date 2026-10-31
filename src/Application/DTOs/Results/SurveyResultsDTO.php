<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

final readonly class SurveyResultsDTO
{
    public const string NOT_CLOSED = 'not_closed';

    public const string BELOW_MINIMUM = 'below_minimum';

    public const string NOT_SHARED = 'not_shared';

    /**
     * @param  float|null  $rate  responses / audience, null when the survey has no fixed audience
     * @param  string|null  $hiddenReason  why the results are not visible (one of the constants)
     * @param  list<QuestionResultDTO>  $questions  empty while the results are not visible
     */
    public function __construct(
        public int $responses,
        public int $audienceSize,
        public ?float $rate,
        public bool $visible,
        public ?string $hiddenReason,
        public array $questions = [],
    ) {}
}
