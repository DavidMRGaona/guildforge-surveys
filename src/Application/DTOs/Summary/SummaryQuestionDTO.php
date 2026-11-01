<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Summary;

/**
 * One question of a closing summary: the most voted options of a choice, the slots most people
 * can make of an availability question, or the mean of a scale or a star rating. A question
 * below the minimum carries only its title.
 */
final readonly class SummaryQuestionDTO
{
    /**
     * @param  list<string|null>  $topOptions  labels of the most voted options (several on a tie); null is "Other"
     * @param  int  $topCount  answers each of the top options got ("yes" on availability)
     * @param  bool  $availability  the top options are the best slots, labelled in the display zone
     * @param  int  $topIfNeeded  "if needed" each of the best slots got
     */
    public function __construct(
        public string $title,
        public int $answered = 0,
        public bool $belowMinimum = false,
        public array $topOptions = [],
        public int $topCount = 0,
        public ?float $mean = null,
        public ?int $scaleMin = null,
        public ?int $scaleMax = null,
        public bool $availability = false,
        public int $topIfNeeded = 0,
    ) {}
}
