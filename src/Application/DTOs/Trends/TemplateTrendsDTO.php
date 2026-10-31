<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

/**
 * A template's trends for one cut. Not visible (and without questions) while the surveys in
 * the cut do not reach the minimum number of responses together.
 */
final readonly class TemplateTrendsDTO
{
    /**
     * @param  list<QuestionTrendDTO>  $questions
     */
    public function __construct(
        public string $templateId,
        public string $templateName,
        public int $surveys,
        public int $responses,
        public bool $visible,
        public array $questions = [],
    ) {}
}
