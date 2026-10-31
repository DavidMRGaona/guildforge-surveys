<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\QuestionType;

final readonly class QuestionResultDTO
{
    /**
     * @param  int  $answered  responses that answered this question
     * @param  list<ResultBucketDTO>  $buckets
     * @param  list<string>  $texts  free text, or the text of "Other"
     */
    public function __construct(
        public string $questionId,
        public string $title,
        public QuestionType $type,
        public ChartType $chartType,
        public bool $hidden,
        public int $answered,
        public array $buckets = [],
        public ?GridResultDTO $grid = null,
        public ?float $mean = null,
        public ?float $median = null,
        public array $texts = [],
    ) {}
}
